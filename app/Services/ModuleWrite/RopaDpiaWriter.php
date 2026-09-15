<?php

namespace App\Services\ModuleWrite;

use App\Models\AuditLog;
use App\Models\Dpia;
use App\Models\InformationSystem;
use App\Models\Ropa;
use App\Models\Vendor;
use App\Services\ApprovalWorkflowDispatcher;
use App\Services\AssessmentAutoTriggerService;
use App\Services\NotificationService;
use App\Services\RegistrationCodeService;
use App\Services\RopaRiskCalculator;
use App\Support\PenugasanDivisi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Jalur tulis RoPA & DPIA, lepas dari HTTP.
 *
 * Membuat RoPA bukan sekadar INSERT. Sekali tulis membawa sebelas akibat:
 * penomoran (berbasis kategori, kode divisi, atau tahunan lintas tenant),
 * perhitungan ulang risiko, percobaan ulang saat kode bentrok, tiga sinkronisasi
 * pivot, jejak audit, notifikasi ke DPO dan penerima tugas, DPIA draf otomatis
 * saat risiko tinggi, dan LIA draf otomatis untuk dasar hukum kepentingan sah.
 *
 * Selama ini semuanya tinggal di dalam ModuleCrudController dan bersandar pada
 * `$request->user()`, sehingga penulis non-HTTP — kunci API mitra, impor massal,
 * agen AI — tidak punya cara memakainya tanpa menyalin. Menyalinnya berarti dua
 * salinan yang pasti menyimpang; temuan F-03 sudah menunjukkan bagaimana itu
 * berakhir. Karena itu logikanya pindah ke sini utuh, dengan pelaku dibawa
 * sebagai ModuleWriteContext.
 *
 * Kaidah yang dipertahankan apa adanya dari perilaku lama, dan sengaja tidak
 * "dirapikan" sambil jalan:
 *   - efek samping non-inti dibungkus try/catch dan hanya dicatat ke log.
 *     Notifikasi gagal, DPIA otomatis gagal, atau LIA otomatis gagal TIDAK boleh
 *     membatalkan penyimpanan record utamanya — bagi penggunanya itu tampak
 *     seperti "simpan gagal" padahal datanya sudah benar;
 *   - `applyRopaAutoRisk` berjalan SEBELUM pemeriksaan DPIA otomatis, dan
 *     menimpa risk_level kiriman pemanggil kecuali risk_level_locked bernilai
 *     benar. Urutan ini menentukan hasil, jadi tidak boleh ditukar.
 */
class RopaDpiaWriter
{
    public function __construct(
        private ModuleCodeGenerator $codeGenerator,
        private RegistrationCodeService $codes,
    ) {}

    /**
     * @param  array<string, mixed>  $data  payload mentah (belum ber-org_id/created_by)
     * @return array{record: Model, auto_dpia_id: ?string, auto_lia_id: ?string}
     */
    public function create(string $module, array $data, ModuleWriteContext $ctx): array
    {
        $model = $this->modelFor($module);

        $data['org_id'] = $ctx->orgId;
        $data['created_by'] = $ctx->actorUserId;

        // Divisi pembuatnya dikunci ke penugasan record ini. Tanpa ini
        // `assign_group` jatuh ke NULL, yang di AssignmentScope berarti
        // "(All Group)" — record baru buatan staf HR jadi terbaca seluruh
        // tenant, kebalikan dari maksud penyaringan divisi.
        $data = PenugasanDivisi::saatBuat($data, $ctx->actor);

        $data = $this->prepare($module, $data, $model, $ctx);
        $record = $this->insertWithCodeRetry($module, $model, $data, $ctx);

        $this->syncPivots($module, $record);
        $this->writeCreateAudit($module, $record);
        $this->notifyCreated($module, $record, $data);

        $autoDpiaId = $this->spawnAutoDpia($module, $record, $data, $ctx);
        $autoLiaId = $this->spawnAutoLia($module, $record, $ctx);

        return [
            'record' => $record,
            'auto_dpia_id' => $autoDpiaId,
            'auto_lia_id' => $autoLiaId,
        ];
    }

    public function modelFor(string $module)
    {
        return $module === 'dpia' ? new Dpia : new Ropa;
    }

    /**
     * Perbarui RoPA/DPIA yang sudah ada.
     *
     * Pencarian record dan gerbang izin TETAP milik pemanggil — merekalah yang
     * tahu siapa yang sedang meminta dan record mana yang boleh ia lihat. Yang
     * pindah ke sini adalah aturan MODUL-nya: kunci penyuntingan, perhitungan
     * ulang risiko, sinkronisasi pivot, notifikasi, alur persetujuan, jejak
     * audit per bagian wizard, dan DPIA otomatis saat risiko naik.
     *
     * @param  array<string, mixed>  $payload
     * @return array{record: Model, auto_dpia_id: ?string}
     *
     * @throws ModuleWriteRejected saat kunci penyuntingan menolak perubahan
     */
    public function update(string $module, $record, array $payload, ModuleWriteContext $ctx): array
    {
        $this->guardEditLocks($module, $payload, $record);

        // Divisi asal tidak boleh dilepas dari penugasan. Dibaca dari RECORD-nya
        // — bukan dari divisi orang yang sedang mengubah — karena kuncinya milik
        // record itu, bukan milik siapa pun yang kebetulan menyuntingnya.
        $payload = PenugasanDivisi::saatUbah($payload, $record->origin_division ?? null);

        $oldWizard = $record->wizard_data ?? [];
        $newWizard = $payload['wizard_data'] ?? [];
        $oldStatus = $record->status;
        $oldAssignees = $record->assignees ?? [];

        if ($module === 'ropa') {
            // Wizard bisa datang sebagian; kalkulator harus melihat gabungan
            // keadaan sekarang dengan kiriman baru, bukan kiriman saja.
            $merged = $this->applyRopaAutoRisk(array_merge([
                'wizard_data' => $record->wizard_data,
                'risk_level_locked' => $record->risk_level_locked,
                'risk_level' => $record->risk_level,
            ], $payload));
            $payload['risk_level'] = $merged['risk_level'];
            $payload['wizard_data'] = $merged['wizard_data'];
        }

        $record->update($payload);

        $this->notifyStatusChange($module, $record, $payload, $oldStatus);
        $this->syncPivots($module, $record);
        $this->notifyNewAssignees($module, $record, $payload, $oldAssignees);
        $this->dispatchApproval($module, $record, $payload, $oldStatus);
        $this->auditWizardSections($module, $record, $oldWizard, $newWizard);

        return [
            'record' => $record,
            'auto_dpia_id' => $this->spawnAutoDpiaOnUpdate($module, $record, $ctx),
        ];
    }

    /**
     * Dua kunci penyuntingan, keduanya menolak dengan 409.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ModuleWriteRejected
     */
    private function guardEditLocks(string $module, array $payload, $record): void
    {
        $currentStatus = $record->status ?? 'in_progress';

        // Penugasan hanya boleh berubah selagi masih dikerjakan. Record yang
        // sudah menunggu review / disetujui harus dibuka kembali dulu.
        $menyentuhPenugasan = array_key_exists('assignees', $payload) || array_key_exists('assign_group', $payload);
        if ($menyentuhPenugasan && ! in_array($currentStatus, ['in_progress', 'draft'], true)) {
            throw new ModuleWriteRejected(
                'Assign group terkunci karena status bukan in_progress.',
                409,
                ['status' => $currentStatus],
            );
        }

        // Berstatus `waiting` berarti sedang ditelaah: isinya terkunci. Yang
        // tetap diizinkan hanya transisi status MURNI, supaya alur re-open dan
        // approve berbasis status tetap jalan.
        if ($currentStatus === 'waiting') {
            $kunciTransisi = ['status', 'review_notes', 'approver_id', 'approved_at'];
            $ekstra = array_diff(array_keys($payload), $kunciTransisi);
            $transisiMurni = array_key_exists('status', $payload) && count($ekstra) === 0;
            if (! $transisiMurni) {
                throw new ModuleWriteRejected(
                    strtoupper($module).' berstatus "waiting" (menunggu review) — konten terkunci dan tidak bisa diedit. Gunakan mode review (read-only) atau jalur approve/reject.',
                    409,
                    ['status' => $currentStatus],
                );
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function notifyStatusChange(string $module, $record, array $payload, ?string $oldStatus): void
    {
        if (! array_key_exists('status', $payload) || $payload['status'] === $oldStatus) {
            return;
        }

        try {
            $label = ['ropa' => 'RoPA', 'dpia' => 'DPIA'][$module] ?? strtoupper($module);
            $newStatus = (string) $payload['status'];
            $sev = in_array($newStatus, ['approved', 'rejected', 'waiting'], true) ? 'medium' : 'low';

            NotificationService::dispatch(
                kind: $newStatus === 'rejected' ? 'warning' : 'info',
                severity: $sev,
                module: $module,
                type: "{$module}.status.{$newStatus}",
                recipient: 'role:dpo,admin',
                orgId: $record->org_id,
                title: "{$label} {$record->registration_number}: status → {$newStatus}",
                body: ($oldStatus ?? '-')." → {$newStatus}",
                actionUrl: "/{$module}/{$record->id}",
                metadata: ['record_id' => $record->id, 'old_status' => $oldStatus, 'new_status' => $newStatus],
            );
        } catch (\Throwable $e) {
            Log::warning("{$module} status notif failed: ".$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, mixed>  $oldAssignees
     */
    private function notifyNewAssignees(string $module, $record, array $payload, $oldAssignees): void
    {
        if (! array_key_exists('assignees', $payload) || ! is_array($payload['assignees'])) {
            return;
        }

        try {
            // $oldAssignees dibaca dari kolom ber-cast array, jadi selalu array —
            // tidak perlu dijaga ulang di sini.
            $ditambahkan = array_values(array_diff($payload['assignees'], $oldAssignees));
            foreach ($ditambahkan as $uid) {
                NotificationService::dispatch(
                    kind: 'info',
                    severity: 'low',
                    module: $module,
                    type: "{$module}.assigned",
                    recipient: 'user:'.$uid,
                    orgId: $record->org_id,
                    title: strtoupper($module).' '.($record->registration_number ?? '').' di-assign ke Anda',
                    body: $record->processing_activity ?? $record->description ?? '',
                    actionUrl: "/{$module}/{$record->id}",
                    metadata: ['record_id' => $record->id]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Assignee notification failed: '.$e->getMessage());
        }
    }

    /** @param array<string, mixed> $payload */
    private function dispatchApproval(string $module, $record, array $payload, ?string $oldStatus): void
    {
        $masukWaiting = ($payload['status'] ?? null) === 'waiting' && $oldStatus !== 'waiting';
        if (! $masukWaiting) {
            return;
        }

        ApprovalWorkflowDispatcher::dispatch($record->org_id, $module, $record->id);

        try {
            NotificationService::dispatch(
                kind: 'alert',
                severity: 'high',
                module: 'approval',
                type: 'approval.pending',
                recipient: 'role:dpo',
                orgId: $record->org_id,
                title: '✋ Approval pending: '.strtoupper($module)." {$record->registration_number}",
                body: 'Menunggu review DPO untuk '.($record->processing_activity ?? $record->description ?? ''),
                actionUrl: "/{$module}/{$record->id}",
                metadata: ['record_id' => $record->id, 'workflow_module' => $module]
            );
        } catch (\Throwable $e) {
            Log::warning('Approval pending notification failed: '.$e->getMessage());
        }
    }

    /**
     * Jejak audit per BAGIAN wizard, bukan satu baris untuk seluruh dokumen —
     * supaya riwayatnya terbaca sebagai "bagian mana yang berubah".
     *
     * `$newWizard` sengaja bertipe mixed, bukan array: isinya datang langsung
     * dari payload klien (`wizard_data`), yang betul-betul bisa dikirim sebagai
     * string. Penjaga is_array() di bawah melindungi kasus itu dan BUKAN kode
     * mati — menghapusnya demi menyenangkan analisis statis justru membuat jalur
     * ini lebih rapuh daripada versi controller yang digantikannya.
     *
     * @param  array<string, mixed>  $oldWizard
     * @param  mixed  $newWizard
     */
    private function auditWizardSections(string $module, $record, $oldWizard, $newWizard): void
    {
        if (empty($newWizard) || ! is_array($newWizard)) {
            return;
        }

        foreach ($newWizard as $sectionKey => $sectionData) {
            $oldSection = $oldWizard[$sectionKey] ?? [];

            // Penyesuaian tampilan per-record (sembunyikan & urutkan) dicatat
            // sebagai satu entri bersih, bukan diff field numerik.
            if (in_array($sectionKey, ['hidden_fields', 'field_order'], true)) {
                if (json_encode($oldSection) !== json_encode($sectionData)) {
                    $action = $sectionKey === 'hidden_fields' ? 'fields_hidden_changed' : 'fields_reordered';
                    AuditLog::log($module, $record->id, $action, [
                        $sectionKey => ['old' => $oldSection ?: null, 'new' => $sectionData ?: null],
                    ], $sectionKey);
                }

                continue;
            }

            if (json_encode($oldSection) === json_encode($sectionData)) {
                continue;
            }

            $berubah = [];
            if (is_array($sectionData)) {
                foreach ($sectionData as $field => $value) {
                    $oldVal = $oldSection[$field] ?? null;
                    if (json_encode($oldVal) !== json_encode($value)) {
                        $berubah[$field] = ['old' => $oldVal, 'new' => $value];
                    }
                }
            }
            if (! empty($berubah)) {
                AuditLog::log($module, $record->id, 'answer_added', $berubah, $sectionKey);
            }
        }
    }

    /**
     * DPIA otomatis saat RoPA berisiko tinggi — versi jalur pembaruan.
     *
     * Berbeda dari versi create: sumbernya record yang sudah tersimpan, bukan
     * payload, dan tidak menulis blok informasi_dpia. Dibiarkan terpisah agar
     * perilakunya sama persis dengan sebelumnya.
     */
    private function spawnAutoDpiaOnUpdate(string $module, $record, ModuleWriteContext $ctx): ?string
    {
        if ($module !== 'ropa' || ($record->risk_level ?? null) !== 'high') {
            return null;
        }

        try {
            $dpiaModel = new Dpia;
            if ($dpiaModel->where('ropa_id', $record->id)->first()) {
                return null;
            }

            $autoDpia = $this->codes->createWithRetry($dpiaModel, [
                'org_id' => $record->org_id,
                'category_id' => $record->category_id,
                'registration_number' => $this->codeGenerator->next('DPIA', $dpiaModel, $record->org_id, $record->category_id),
                'ropa_id' => $record->id,
                'risk_level' => 'high',
                'status' => 'draft',
                'description' => 'Auto-generated dari RoPA high-risk: '.$record->processing_activity,
                'risk_assessment' => ['likelihood' => 0, 'impact' => 0, 'risks' => []],
                'mitigation_measures' => [],
                'created_by' => $ctx->actorUserId,
                'assign_group' => $record->assign_group,
                'assignees' => $record->assignees ?? [],
            ], 'registration_number', fn () => $this->codeGenerator->next('DPIA', $dpiaModel, $record->org_id));

            foreach ((array) ($record->assignees ?? []) as $assigneeId) {
                try {
                    NotificationService::dispatch(
                        kind: 'info',
                        severity: 'medium',
                        module: 'dpia',
                        type: 'dpia.assigned',
                        recipient: 'user:'.$assigneeId,
                        orgId: $record->org_id,
                        title: "DPIA {$autoDpia->registration_number} di-assign ke Anda",
                        body: 'DPIA otomatis dari RoPA high-risk '.($record->registration_number ?? '').' — assignment mengikuti RoPA.',
                        actionUrl: "/dpia/{$autoDpia->id}",
                        metadata: ['record_id' => $autoDpia->id, 'ropa_id' => $record->id]
                    );
                } catch (\Throwable $e) {
                    Log::warning('Auto-DPIA assignee notification failed: '.$e->getMessage());
                }
            }

            return $autoDpia->id;
        } catch (\Throwable $e) {
            Log::warning('Auto-DPIA creation for RoPA '.$record->id.' failed: '.$e->getMessage());

            return null;
        }
    }

    // ---------- penyiapan data ----------

    /** @param array<string, mixed> $data */
    private function prepare(string $module, array $data, $model, ModuleWriteContext $ctx): array
    {
        if ($module === 'ropa') {
            $divCode = $this->codeGenerator->divisionCodeForRopa($data, $ctx->orgId);
            $data['registration_number'] = $data['registration_number'] ?? $this->codeGenerator->next(
                'ROPA', $model, $ctx->orgId,
                $data['category_id'] ?? null,
                $data['custom_number'] ?? null,
                $divCode,
            );
            // Harus sebelum pemeriksaan DPIA otomatis — hasilnya yang dibaca.
            $data = $this->applyRopaAutoRisk($data);

            return $data;
        }

        $data['registration_number'] = $data['registration_number'] ?? $this->codeGenerator->next(
            'DPIA', $model, $ctx->orgId,
            $data['category_id'] ?? null,
            $data['custom_number'] ?? null,
        );

        return $data;
    }

    /**
     * Hitung ulang tingkat risiko RoPA dari isian wizard, lalu simpan jejaknya.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function applyRopaAutoRisk(array $data): array
    {
        try {
            $wizard = $data['wizard_data'] ?? [];
            if (! is_array($wizard)) {
                $wizard = [];
            }

            $result = app(RopaRiskCalculator::class)->calculate($wizard);

            $wizard['risk_triggers'] = [
                'level' => $result['level'],
                'triggers' => $result['triggers'],
                'reasons' => $result['reasons'],
                'computed_at' => now()->toIso8601String(),
            ];
            $data['wizard_data'] = $wizard;

            $locked = filter_var($data['risk_level_locked'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (! $locked) {
                $data['risk_level'] = $result['level'];
            }
        } catch (\Throwable $e) {
            // Kegagalan kalkulator tidak boleh menghalangi penyimpanan — biarkan
            // risk_level apa adanya dari pemanggil.
            Log::warning('applyRopaAutoRisk failed, leaving risk_level untouched: '.$e->getMessage());
        }

        // Basis data yang belum menjalankan migrasi risk_level_locked akan
        // melempar galat SQL yang muncul ke pengguna sebagai "simpan gagal".
        if (array_key_exists('risk_level_locked', $data)) {
            try {
                if (! Schema::hasColumn('ropas', 'risk_level_locked')) {
                    unset($data['risk_level_locked']);
                }
            } catch (\Throwable $e) {
                unset($data['risk_level_locked']);
            }
        }

        return $data;
    }

    // ---------- penyimpanan ----------

    /**
     * Dua create hampir bersamaan bisa menghitung nomor yang sama dari max+1.
     * Saat bentrok, nomornya dihitung ulang lalu dicoba lagi (maksimal 3 kali).
     *
     * @param  array<string, mixed>  $data
     */
    private function insertWithCodeRetry(string $module, $model, array $data, ModuleWriteContext $ctx)
    {
        $prefix = $module === 'dpia' ? 'DPIA' : 'ROPA';

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $model->create($data);
            } catch (QueryException $qe) {
                $isDup = $qe->getCode() === '23000'
                    || str_contains($qe->getMessage(), 'Duplicate entry')
                    || str_contains($qe->getMessage(), 'UNIQUE constraint');
                if ($isDup && $attempt < 2) {
                    $data['registration_number'] = $this->codeGenerator->next($prefix, $model, $ctx->orgId);

                    continue;
                }
                throw $qe;
            }
        }

        throw new \RuntimeException('Gagal menghasilkan registration_number unik setelah 3 percobaan.');
    }

    // ---------- sinkronisasi pivot ----------

    private function syncPivots(string $module, $record): void
    {
        if ($module === 'ropa') {
            try {
                $this->syncRopaInformationSystems($record);
            } catch (\Throwable $e) {
                Log::warning('syncRopaInformationSystems on create failed: '.$e->getMessage());
            }
            try {
                $this->syncRopaVendors($record);
            } catch (\Throwable $e) {
                Log::warning('syncRopaVendors on create failed: '.$e->getMessage());
            }

            return;
        }

        try {
            $this->syncDpiaRopas($record);
        } catch (\Throwable $e) {
            Log::warning('syncDpiaRopas on create failed: '.$e->getMessage());
        }
    }

    /** Sinkron `information_system_ropa` dari wizard.detail_pemrosesan.sistem_terkait. */
    public function syncRopaInformationSystems($ropa): void
    {
        $wizard = $ropa->wizard_data ?? [];
        $section = $wizard['detail_pemrosesan'] ?? [];
        $raw = $section['sistem_terkait'] ?? null;
        if ($raw === null) {
            return;
        }

        $ids = collect(is_array($raw) ? $raw : [])
            ->map(fn ($v) => is_array($v) ? ($v['id'] ?? null) : (is_string($v) ? $v : null))
            ->filter()->unique()->values()->all();

        $valid = InformationSystem::whereIn('id', $ids)
            ->where('org_id', $ropa->org_id)
            ->pluck('id')->all();

        $syncData = [];
        foreach ($valid as $id) {
            $syncData[$id] = ['org_id' => $ropa->org_id];
        }
        $ropa->informationSystems()->sync($syncData);
    }

    /**
     * Sinkron `ropa_vendor` beserta PERAN tiap pihak ketiga.
     *
     * Dua sumber: `vendor_links[]` (membawa peran) dan `vendor_ids[]` (daftar
     * UUID polos dari wizard lama/impor/AI, diperlakukan sebagai Prosesor —
     * bagian wizard itu memang "pihak yang memproses").
     */
    public function syncRopaVendors($ropa): void
    {
        $section = ($ropa->wizard_data ?? [])['penggunaan_penyimpanan'] ?? [];
        $links = $section['vendor_links'] ?? null;
        $ids = $section['vendor_ids'] ?? null;
        if ($links === null && $ids === null) {
            return;
        }

        $rows = [];
        foreach (is_array($links) ? $links : [] as $link) {
            $id = is_array($link) ? ($link['id'] ?? null) : null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            $shared = is_array($link['data_shared'] ?? null)
                ? array_values(array_filter($link['data_shared'], 'is_string'))
                : null;
            $rows[$id] = [
                'role' => Vendor::normalizeRole($link['role'] ?? null) ?? Vendor::ROLE_PROCESSOR,
                'purpose' => is_string($link['purpose'] ?? null) ? mb_substr($link['purpose'], 0, 2000) : null,
                // Dibiarkan array: pivot RopaVendor yang meng-encode lewat cast.
                'data_shared' => $shared ?: null,
                'contract_ref' => is_string($link['contract_ref'] ?? null) ? mb_substr($link['contract_ref'], 0, 255) : null,
            ];
        }
        foreach (is_array($ids) ? $ids : [] as $id) {
            if (is_string($id) && $id !== '' && ! isset($rows[$id])) {
                $rows[$id] = ['role' => Vendor::ROLE_PROCESSOR, 'purpose' => null, 'data_shared' => null, 'contract_ref' => null];
            }
        }

        // Hanya pihak ketiga milik org yang sama — tautan lintas tenant tidak pernah dibuat.
        $valid = $rows
            ? Vendor::whereIn('id', array_keys($rows))->where('org_id', $ropa->org_id)->pluck('id')->all()
            : [];

        $syncData = [];
        foreach ($valid as $id) {
            $syncData[$id] = $rows[$id] + ['org_id' => $ropa->org_id];
        }
        $ropa->vendors()->sync($syncData);
    }

    /** Sinkron `dpia_ropa` dari wizard.koneksi_ropa.connected_ropas (+ induk warisan). */
    public function syncDpiaRopas($dpia): void
    {
        $wizard = $dpia->wizard_data ?? [];
        $section = $wizard['koneksi_ropa'] ?? [];
        // Catatan: versi lama memeriksa `! is_array($ids)` di sini. Cabang itu
        // tidak pernah jalan — array_filter selalu mengembalikan array — jadi
        // tidak ikut dibawa. Daftar kosong sudah ditangani sync() di bawah.
        $ids = array_filter(array_unique($section['connected_ropas'] ?? []));

        $valid = Ropa::whereIn('id', $ids)
            ->where('org_id', $dpia->org_id)
            ->pluck('id')->all();

        if ($dpia->ropa_id && ! in_array($dpia->ropa_id, $valid, true)) {
            $exists = Ropa::where('id', $dpia->ropa_id)->where('org_id', $dpia->org_id)->exists();
            if ($exists) {
                $valid[] = $dpia->ropa_id;
            }
        }

        $syncData = [];
        foreach ($valid as $id) {
            $syncData[$id] = ['org_id' => $dpia->org_id];
        }
        $dpia->ropas()->sync($syncData);
    }

    // ---------- audit & notifikasi ----------

    private function writeCreateAudit(string $module, $record): void
    {
        try {
            AuditLog::log($module, $record->id, 'created', [
                'registration_number' => $record->registration_number ?? null,
            ], 'system');
        } catch (\Throwable $e) {
            Log::warning('Audit log failed: '.$e->getMessage());
        }
    }

    /** @param array<string, mixed> $data */
    private function notifyCreated(string $module, $record, array $data): void
    {
        $meta = [
            'ropa' => ['label' => 'RoPA', 'sev' => 'low'],
            'dpia' => ['label' => 'DPIA', 'sev' => 'medium'],
        ][$module] ?? null;

        try {
            if ($meta) {
                $code = $record->registration_number ?? '';
                NotificationService::dispatch(
                    kind: 'info',
                    severity: $meta['sev'],
                    module: $module,
                    type: "{$module}.created",
                    recipient: 'role:dpo,admin',
                    orgId: $record->org_id,
                    title: "{$meta['label']} baru dibuat".($code ? ": {$code}" : ''),
                    body: $record->processing_activity ?? $record->description ?? '',
                    actionUrl: "/{$module}/{$record->id}",
                    metadata: ['record_id' => $record->id]
                );
            }

            if (! empty($data['assignees']) && is_array($data['assignees'])) {
                $label = $meta['label'] ?? strtoupper($module);
                foreach ($data['assignees'] as $uid) {
                    NotificationService::dispatch(
                        kind: 'info',
                        severity: 'low',
                        module: $module,
                        type: "{$module}.assigned",
                        recipient: 'user:'.$uid,
                        orgId: $record->org_id,
                        title: "{$label} {$record->registration_number} di-assign ke Anda",
                        body: $record->processing_activity ?? $record->description ?? '',
                        actionUrl: "/{$module}/{$record->id}",
                        metadata: ['record_id' => $record->id]
                    );
                }
            }
        } catch (\Throwable $e) {
            // \Throwable, bukan \Exception: \Error dari provider yang salah
            // konfigurasi (AI/SMTP/Telegram) tidak boleh menggagalkan create.
            Log::warning('Notification dispatch failed on create: '.$e->getMessage());
        }
    }

    // ---------- pemicu otomatis ----------

    /**
     * RoPA berisiko tinggi menumbuhkan DPIA draf yang mewarisi penugasan induknya.
     *
     * @param  array<string, mixed>  $data
     */
    private function spawnAutoDpia(string $module, $record, array $data, ModuleWriteContext $ctx): ?string
    {
        if ($module !== 'ropa' || ($data['risk_level'] ?? '') !== 'high') {
            return null;
        }

        try {
            $dpiaModel = new Dpia;
            if ($dpiaModel->where('ropa_id', $record->id)->first()) {
                return null;
            }

            $ropaWiz = $data['wizard_data'] ?? [];
            $dpoTeam = $ropaWiz['dpo_team'] ?? [];

            $autoDpia = $this->codes->createWithRetry($dpiaModel, [
                'org_id' => $ctx->orgId,
                'category_id' => $data['category_id'] ?? null,
                'registration_number' => $this->codeGenerator->next('DPIA', $dpiaModel, $ctx->orgId, $data['category_id'] ?? null),
                'ropa_id' => $record->id,
                'risk_level' => 'high',
                'status' => 'draft',
                'description' => 'Auto-generated dari RoPA high-risk: '.($data['processing_activity'] ?? ''),
                'wizard_data' => [
                    'informasi_dpia' => [
                        'description' => $data['processing_activity'] ?? '',
                        'pic_name' => $dpoTeam['pic_name'] ?? '',
                        'dpo_name' => $dpoTeam['dpo_name'] ?? '',
                        'dpo_email' => $dpoTeam['dpo_email'] ?? '',
                        'dpo_phone' => $dpoTeam['dpo_phone'] ?? '',
                    ],
                    'koneksi_ropa' => ['connected_ropas' => [$record->id]],
                    'potensi_risiko' => [],
                ],
                'risk_assessment' => ['likelihood' => 0, 'impact' => 0, 'risks' => []],
                'mitigation_measures' => [],
                'created_by' => $ctx->actorUserId,
                'assign_group' => $data['assign_group'] ?? null,
                'assignees' => $data['assignees'] ?? [],
            ], 'registration_number', fn () => $this->codeGenerator->next('DPIA', $dpiaModel, $ctx->orgId));

            $this->notifyAutoDpia($autoDpia, $record, (array) ($data['assignees'] ?? []));

            return $autoDpia->id;
        } catch (\Throwable $e) {
            Log::warning('Auto-DPIA on RoPA store failed (non-fatal): '.$e->getMessage());

            return null;
        }
    }

    /** @param array<int, mixed> $assignees */
    private function notifyAutoDpia($autoDpia, $record, array $assignees): void
    {
        foreach ($assignees as $assigneeId) {
            try {
                NotificationService::dispatch(
                    kind: 'info',
                    severity: 'medium',
                    module: 'dpia',
                    type: 'dpia.assigned',
                    recipient: 'user:'.$assigneeId,
                    orgId: $record->org_id,
                    title: "DPIA {$autoDpia->registration_number} di-assign ke Anda",
                    body: 'DPIA otomatis dari RoPA high-risk '.($record->registration_number ?? '').' — assignment mengikuti RoPA.',
                    actionUrl: "/dpia/{$autoDpia->id}",
                    metadata: ['record_id' => $autoDpia->id, 'ropa_id' => $record->id]
                );
            } catch (\Throwable $e) {
                Log::warning('Auto-DPIA assignee notification failed: '.$e->getMessage());
            }
        }

        try {
            NotificationService::dispatch(
                kind: 'warning',
                severity: 'high',
                module: 'dpia',
                type: 'dpia.auto_created',
                recipient: 'role:dpo',
                orgId: $record->org_id,
                title: "⚠️ DPIA otomatis: {$autoDpia->registration_number}",
                body: "Dibuat dari RoPA high-risk {$record->registration_number} — review diperlukan.",
                actionUrl: "/dpia/{$autoDpia->id}",
                metadata: ['record_id' => $autoDpia->id, 'ropa_id' => $record->id]
            );
        } catch (\Throwable $e) {
            Log::warning('DPIA auto-create notification failed: '.$e->getMessage());
        }
    }

    /** RoPA berdasar kepentingan sah menumbuhkan LIA draf. */
    private function spawnAutoLia(string $module, $record, ModuleWriteContext $ctx): ?string
    {
        if ($module !== 'ropa') {
            return null;
        }

        try {
            return app(AssessmentAutoTriggerService::class)
                ->fromRopa($record, $ctx->actorUserId)?->id;
        } catch (\Throwable $e) {
            Log::warning('Auto-LIA on RoPA store failed (non-fatal): '.$e->getMessage());

            return null;
        }
    }
}
