<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\DsrRequest;
use App\Models\GuardianConsent;
use App\Models\User;
use App\Services\Dsr\BuktiWali;
use App\Services\RegistrationCodeService;
use App\Support\AssignmentScope;
use App\Support\ModulSubjek;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dua modul per SUBJEK — Consent Wali (anak) dan Consent Aksesibilitas
 * (disabilitas) — masing-masing LENGKAP: titik pengumpulan (CRUD penuh),
 * kewenangan wali, dan DSR atas nama subjek kelas itu.
 *
 * Satu controller, dua awalan rute; modulnya dibaca dari default rute
 * (`modul`) dan kelas subjeknya mengikuti (ModulSubjek::kelas). Datanya
 * tetap SATU — consent_collection_points, consent_logs, dsr_requests —
 * yang dipisah adalah pintu, izin, dan pandangan: pengguna berizin
 * `consent_guardian` melihat titik milik modul itu dan DSR berkelas anak;
 * bukan titik umum milik Consent, bukan DSR disabilitas.
 *
 * Titik yang dibuat dari sini lahir dengan preset modulnya: pemilih subjek
 * menyala (`guardian_mode`) dan kelas bawaannya kelas modul
 * (`subject_class_default`), sehingga widget langsung menempuh jalur yang
 * benar. Nomor `CNT-YYYY-NNN` dan `DSR-YYYY-NNN` memakai RegistrationCodeService
 * yang sama dengan universal CRUD, dengan percobaan ulang saat tabrakan —
 * unique-nya global (temuan F-03).
 */
class ModulSubjekController extends Controller
{
    // ───────────────────────── ringkasan ─────────────────────────

    public function summary(Request $request)
    {
        $modul = ModulSubjek::dariRequest($request);
        $kelas = ModulSubjek::kelas($modul);

        $kewenangan = fn () => $this->kewenangan($request, $kelas);
        $dsr = fn () => $this->dsr($request, $kelas);

        return response()->json(['data' => [
            'module' => $modul,
            'subject_class' => $kelas,
            'collection_points' => $this->titik($request)->count(),
            'guardian_pending' => $kewenangan()->whereNull('revoked_at')->whereNull('verified_at')->count(),
            'guardian_verified' => $kewenangan()->whereNull('revoked_at')->whereNotNull('verified_at')->count(),
            'dsr_open' => $dsr()->whereNotIn('status', ['completed', 'rejected', 'cancelled', 'closed'])->count(),
            'dsr_awaiting_proof' => $dsr()->where('guardian_proof_status', DsrRequest::BUKTI_MENUNGGU)->count(),
        ]]);
    }

    // ───────────────────────── titik pengumpulan ─────────────────────────

    public function collectionPoints(Request $request)
    {
        $q = $this->titik($request)->withCount(['items', 'logs as records_count'])->orderByDesc('created_at');

        if ($request->filled('q')) {
            $cari = (string) $request->input('q');
            $q->where(fn ($w) => $w->where('name', 'like', "%{$cari}%")
                ->orWhere('collection_id', 'like', "%{$cari}%")
                ->orWhere('domain', 'like', "%{$cari}%"));
        }

        return response()->json([
            'data' => $q->get()->map(fn (ConsentCollectionPoint $cp) => $this->bentukTitik($cp))->values()->all(),
        ]);
    }

    public function storeCollectionPoint(Request $request)
    {
        $modul = ModulSubjek::dariRequest($request);
        $kelas = ModulSubjek::kelas($modul);
        $user = $request->user();
        $data = $request->validate($this->aturanTitik());

        $settings = array_merge([
            // Pemilih "persetujuan ini untuk siapa?" selalu tampil, dan kelas
            // modul dipilih lebih dulu — titik anak menempuh jalur wali tanpa
            // pengguna harus mengaturnya sendiri.
            'guardian_mode' => true,
            'subject_class_default' => $kelas,
            'guardian_label' => $data['guardian_label'] ?? null,
            'guardian_relation_options' => $data['guardian_relation_options'] ?? null,
        ], $this->settingsDariMasukan($data));

        $isi = [
            'org_id' => $user->org_id,
            'name' => $data['name'],
            'kind' => ConsentCollectionPoint::KIND_APP,
            'domain' => $data['domain'] ?? null,
            'redirect_url' => $data['redirect_url'] ?? null,
            'webhook_url' => $data['webhook_url'] ?? null,
            'locale' => $data['locale'] ?? 'id',
            'allowed_domains' => $data['allowed_domains'] ?? [],
            'auth_methods' => [
                'widget' => (bool) ($data['widget_enabled'] ?? true),
                'api_key' => (bool) ($data['api_key_enabled'] ?? false),
            ],
            'owner_module' => $modul,
            'settings' => $settings,
            'created_by' => $user->id,
        ] + ConsentCollectionPoint::presetForKind(ConsentCollectionPoint::KIND_APP);

        $cp = $this->buatDenganNomor(ConsentCollectionPoint::class, 'CNT', 'collection_id', $isi);

        // Kunci API diminta sejak awal: terbitkan sekarang. Server key kembali
        // SEKALI — di respons ini — sama seperti regenerate; setelah itu server
        // hanya menyimpan hash-nya dan tidak bisa menampilkannya lagi.
        $serverKeyBaru = null;
        if ($cp->isApiKeyEnabled() === false && ($data['api_key_enabled'] ?? false)) {
            [$clientKey, $serverKeyBaru] = ConsentCollectionPoint::generateApiKeyPair();
            $cp->update(['client_key' => $clientKey, 'server_key' => $serverKeyBaru, 'api_keys_last_rotated_at' => now()]);
        }

        $this->audit($request, 'collection_point.create', $cp->id, ['module' => $modul, 'collection_id' => $cp->collection_id, 'name' => $cp->name]);

        return response()->json([
            'data' => $this->bentukTitik($cp->fresh()->loadCount(['items', 'logs as records_count'])),
            'server_key' => $serverKeyBaru,
        ], 201);
    }

    public function showCollectionPoint(Request $request, string $id)
    {
        $cp = $this->titikMilik($request, $id)->loadCount(['items', 'logs as records_count'])->load('items');

        return response()->json(['data' => $this->bentukTitik($cp, true)]);
    }

    public function updateCollectionPoint(Request $request, string $id)
    {
        $cp = $this->titikMilik($request, $id);
        $data = $request->validate($this->aturanTitik(sometimes: true));

        $cp->fill(collect($data)->only(['name', 'domain', 'redirect_url', 'webhook_url', 'locale', 'allowed_domains'])->all());

        if (array_key_exists('widget_enabled', $data) || array_key_exists('api_key_enabled', $data)) {
            $auth = $cp->auth_methods ?? ['widget' => true, 'api_key' => false];
            if (array_key_exists('widget_enabled', $data)) {
                $auth['widget'] = (bool) $data['widget_enabled'];
            }
            if (array_key_exists('api_key_enabled', $data)) {
                $auth['api_key'] = (bool) $data['api_key_enabled'];
            }
            $cp->auth_methods = $auth;
        }

        // settings di-MERGE (bukan ditimpa): logo_url, warna, dan kunci lain
        // yang diatur dari tempat lain tidak boleh hilang. Dua kunci modul
        // tidak bisa dimatikan dari sini — itu identitas titiknya.
        $settings = array_merge($cp->settings ?? [], $this->settingsDariMasukan($data));
        foreach (['guardian_label', 'guardian_relation_options'] as $k) {
            if (array_key_exists($k, $data)) {
                $settings[$k] = $data[$k];
            }
        }
        $settings['guardian_mode'] = true;
        $settings['subject_class_default'] = ModulSubjek::kelas(ModulSubjek::dariRequest($request));
        $cp->settings = $settings;
        $cp->save();

        $this->audit($request, 'collection_point.update', $cp->id, ['fields' => array_keys($data)]);

        return response()->json(['data' => $this->bentukTitik($cp->fresh()->loadCount(['items', 'logs as records_count'])->load('items'), true)]);
    }

    public function destroyCollectionPoint(Request $request, string $id)
    {
        $cp = $this->titikMilik($request, $id);
        $cp->delete();

        $this->audit($request, 'collection_point.delete', $id, ['collection_id' => $cp->collection_id, 'name' => $cp->name]);

        return response()->json(['message' => 'Titik pengumpulan dihapus.']);
    }

    // ── item persetujuan: kepemilikan diperiksa di sini, aturannya milik ConsentItemController ──

    public function storeItem(Request $request, string $id)
    {
        $cp = $this->titikMilik($request, $id);
        $request->merge(['collection_point_id' => $cp->id]);

        return app(ConsentItemController::class)->store($request);
    }

    public function updateItem(Request $request, string $id, string $itemId)
    {
        $cp = $this->titikMilik($request, $id);
        ConsentItem::where('collection_point_id', $cp->id)->findOrFail($itemId);

        return app(ConsentItemController::class)->update($request, $itemId);
    }

    public function destroyItem(Request $request, string $id, string $itemId)
    {
        $cp = $this->titikMilik($request, $id);
        ConsentItem::where('collection_point_id', $cp->id)->findOrFail($itemId);

        return app(ConsentItemController::class)->destroy($request, $itemId);
    }

    // ── kunci API, embed, konfigurasi widget: kepemilikan diperiksa, lalu diteruskan ──

    public function regenerateApiKeys(Request $request, string $id)
    {
        $cp = $this->titikMilik($request, $id);

        return app(ConsentCollectionController::class)->regenerateApiKeys($request, $cp->id);
    }

    public function regenerateEmbedToken(Request $request, string $id)
    {
        $cp = $this->titikMilik($request, $id);

        return app(ConsentCollectionController::class)->regenerateEmbedToken($request, $cp->id);
    }

    public function embedSnippet(Request $request, string $id)
    {
        $cp = $this->titikMilik($request, $id);

        return app(ConsentCollectionController::class)->embedSnippet($request, $cp->id);
    }

    public function widgetConfig(Request $request, string $id)
    {
        $cp = $this->titikMilik($request, $id);

        return app(ConsentCollectionController::class)->widgetConfig($request, $cp->id);
    }

    public function saveWidgetConfig(Request $request, string $id)
    {
        $cp = $this->titikMilik($request, $id);

        return app(ConsentCollectionController::class)->saveWidgetConfig($request, $cp->id);
    }

    // ───────────────────────── DSR atas nama subjek kelas ini ─────────────────────────

    public function dsrIndex(Request $request)
    {
        $kelas = ModulSubjek::kelas(ModulSubjek::dariRequest($request));
        $q = $this->dsr($request, $kelas)->with('app:id,name,app_code')->orderByDesc('created_at');

        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('proof')) {
            $q->where('guardian_proof_status', $request->input('proof'));
        }

        return response()->json([
            'data' => $q->limit(500)->get()->map(fn (DsrRequest $d) => $this->bentukDsr($d))->values()->all(),
            'statuses' => DsrRequest::VALID_STATUSES,
            'request_types' => DsrRequest::REQUEST_TYPES,
        ]);
    }

    public function dsrShow(Request $request, string $id)
    {
        return response()->json(['data' => $this->bentukDsr($this->dsrMilik($request, $id)->load('app:id,name,app_code'), true)]);
    }

    /**
     * Entri manual oleh petugas — permohonan yang datang lewat loket, surat,
     * atau telepon. Diverifikasi oleh petugas yang mencatatnya (bukan tautan
     * surel), kelasnya kelas modul, dan bukti wali ditentukan seketika.
     */
    public function dsrStore(Request $request)
    {
        $modul = ModulSubjek::dariRequest($request);
        $kelas = ModulSubjek::kelas($modul);
        $user = $request->user();

        $data = $request->validate([
            'request_type' => ['required', Rule::in(DsrRequest::REQUEST_TYPES)],
            'requester_name' => 'required|string|max:150',
            'requester_email' => 'required|email|max:200',
            'requester_phone' => 'nullable|string|max:40',
            'description' => 'nullable|string|max:5000',
            'requester_type' => ['nullable', Rule::in(DsrRequest::PEMOHON)],
            'requester_relation' => 'nullable|string|max:120',
            'subject_identifier' => 'nullable|string|max:200|required_if:requester_type,'.DsrRequest::PEMOHON_WALI,
        ]);

        // Anak tidak mengajukan sendiri: bawaan pemohon di modul wali adalah
        // wali. Penyandang disabilitas mengajukan sendiri (Pasal 39 ayat 5).
        $pemohon = $data['requester_type'] ?? ($modul === ModulSubjek::GUARDIAN ? DsrRequest::PEMOHON_WALI : DsrRequest::PEMOHON_SUBJEK);

        $dsr = $this->buatDenganNomor(DsrRequest::class, 'DSR', 'request_id', [
            'org_id' => $user->org_id,
            'request_type' => $data['request_type'],
            'requester_name' => $data['requester_name'],
            'requester_email' => $data['requester_email'],
            'requester_phone' => $data['requester_phone'] ?? null,
            'description' => $data['description'] ?? null,
            'requester_type' => $pemohon,
            'requester_relation' => $data['requester_relation'] ?? null,
            'subject_class' => $kelas,
            'subject_identifier' => $data['subject_identifier'] ?? null,
            'status' => 'verified',
            'verification_status' => 'verified',
            'verified_at' => now(),
            'verification_method' => 'manual_dpo',
            'deadline_at' => now()->addHours(72),
            'assigned_to' => $user->id,
        ]);

        app(BuktiWali::class)->tentukan($dsr);

        $this->audit($request, 'dsr.create', $dsr->id, ['module' => $modul, 'request_id' => $dsr->request_id, 'request_type' => $dsr->request_type, 'requester_type' => $pemohon]);

        return response()->json(['data' => $this->bentukDsr($dsr->fresh(), true)], 201);
    }

    public function dsrUpdate(Request $request, string $id)
    {
        $dsr = $this->dsrMilik($request, $id);
        $user = $request->user();

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(DsrRequest::VALID_STATUSES)],
            'response' => 'sometimes|nullable|string|max:10000',
            'rejection_reason' => 'sometimes|nullable|string|max:2000',
            'assigned_to' => 'sometimes|nullable|uuid',
        ]);

        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== null) {
            $adaDiOrg = User::where('org_id', $user->org_id)->where('id', $data['assigned_to'])->exists();
            if (! $adaDiOrg) {
                return response()->json(['error' => 'Penanggung jawab tidak ditemukan di organisasi ini.', 'code' => 'PENANGGUNG_JAWAB_TIDAK_DIKENAL'], 422);
            }
        }

        $dsr->fill($data);
        if (array_key_exists('response', $data) && $data['response'] !== null && $dsr->responded_at === null) {
            $dsr->responded_at = now();
        }
        if (in_array($dsr->status, ['completed', 'rejected', 'cancelled'], true) && $dsr->closed_at === null) {
            $dsr->closed_at = now();
        }
        // Gerbang bukti wali hidup di hook `saving` DsrRequest: status eksekusi
        // atas hak yang merusak tanpa bukti diterima → 422, dari pintu mana pun.
        $dsr->save();

        $this->audit($request, 'dsr.update', $dsr->id, ['fields' => array_keys($data), 'status' => $dsr->status]);

        return response()->json(['data' => $this->bentukDsr($dsr->fresh()->load('app:id,name,app_code'), true)]);
    }

    public function dsrGuardianProof(Request $request, string $id)
    {
        $dsr = $this->dsrMilik($request, $id);

        return app(DsrVerificationController::class)->guardianProof($request, $dsr->id);
    }

    // ───────────────────────── internal ─────────────────────────

    /** @return Builder<ConsentCollectionPoint> */
    private function titik(Request $request): Builder
    {
        $user = $request->user();
        $q = ConsentCollectionPoint::query()
            ->where('org_id', $user->org_id)
            ->milikModul(ModulSubjek::dariRequest($request));

        if (! AssignmentScope::melihatSeluruhTenant($user)) {
            $q->visibleTo($user);
        }

        return $q;
    }

    private function titikMilik(Request $request, string $id): ConsentCollectionPoint
    {
        $cp = $this->titik($request)->find($id);
        if (! $cp) {
            abort(404, 'Titik pengumpulan tidak ditemukan di modul ini.');
        }

        return $cp;
    }

    /** @return Builder<GuardianConsent> */
    private function kewenangan(Request $request, string $kelas): Builder
    {
        $user = $request->user();
        $q = GuardianConsent::withoutGlobalScope('org')
            ->where('org_id', $user->org_id)
            ->whereHas('consentSubject', fn ($s) => $s->where('subject_class', $kelas));

        if (! AssignmentScope::melihatSeluruhTenant($user)) {
            $terlihat = ConsentCollectionPoint::query()->where('org_id', $user->org_id)->visibleTo($user)->select('id');
            $q->whereIn('collection_point_id', $terlihat);
        }

        return $q;
    }

    /** @return Builder<DsrRequest> */
    private function dsr(Request $request, string $kelas): Builder
    {
        $user = $request->user();
        $q = DsrRequest::query()->where('org_id', $user->org_id)->where('subject_class', $kelas);

        if (! AssignmentScope::melihatSeluruhTenant($user)) {
            $q->visibleTo($user);
        }

        return $q;
    }

    private function dsrMilik(Request $request, string $id): DsrRequest
    {
        $dsr = $this->dsr($request, ModulSubjek::kelas(ModulSubjek::dariRequest($request)))->find($id);
        if (! $dsr) {
            abort(404, 'Permohonan DSR tidak ditemukan di modul ini.');
        }

        return $dsr;
    }

    /**
     * Buat dengan nomor global (CNT-YYYY-NNN / DSR-YYYY-NNN) dan percobaan
     * ulang saat tabrakan unique — pola yang sama dengan universal CRUD.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $isi
     * @return TModel
     */
    private function buatDenganNomor(string $model, string $awalan, string $kolom, array $isi)
    {
        for ($percobaan = 0; $percobaan < 3; $percobaan++) {
            $isi[$kolom] = app(RegistrationCodeService::class)->nextGlobal($awalan, $model);
            try {
                return $model::create($isi);
            } catch (QueryException $e) {
                $tabrakan = $e->getCode() === '23000'
                    || str_contains($e->getMessage(), 'Duplicate entry')
                    || str_contains($e->getMessage(), 'UNIQUE constraint');
                if (! $tabrakan || $percobaan === 2) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Nomor tidak dapat dibuat.');
    }

    /** @return array<string, mixed> */
    private function aturanTitik(bool $sometimes = false): array
    {
        $s = $sometimes ? 'sometimes|' : '';

        return [
            'name' => $s.'required|string|max:160',
            'domain' => 'sometimes|nullable|string|max:255',
            'redirect_url' => 'sometimes|nullable|url|max:500',
            'webhook_url' => 'sometimes|nullable|url|max:500',
            'locale' => ['sometimes', 'nullable', Rule::in(['id', 'en'])],
            'allowed_domains' => 'sometimes|nullable|array|max:50',
            'allowed_domains.*' => 'string|max:255',
            'widget_enabled' => 'sometimes|boolean',
            'api_key_enabled' => 'sometimes|boolean',
            'guardian_label' => 'sometimes|nullable|string|max:120',
            'guardian_relation_options' => 'sometimes|nullable|string|max:500',
            'primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'modal_intro_text' => 'sometimes|nullable|string|max:2000',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function settingsDariMasukan(array $data): array
    {
        return collect($data)->only(['primary_color', 'accent_color', 'modal_intro_text'])->all();
    }

    /** @return array<string, mixed> */
    private function bentukTitik(ConsentCollectionPoint $cp, bool $lengkap = false): array
    {
        $s = $cp->settings ?? [];

        $isi = [
            'id' => $cp->id,
            'collection_id' => $cp->collection_id,
            'name' => $cp->name,
            'kind' => $cp->kind,
            'domain' => $cp->domain,
            'redirect_url' => $cp->redirect_url,
            'webhook_url' => $cp->webhook_url,
            'locale' => $cp->locale,
            'owner_module' => $cp->owner_module,
            'subject_class_default' => $s['subject_class_default'] ?? null,
            'guardian_label' => $s['guardian_label'] ?? null,
            'guardian_relation_options' => $s['guardian_relation_options'] ?? null,
            'embed_token' => $cp->embed_token,
            'client_key' => $cp->client_key,
            'widget_enabled' => $cp->isWidgetEnabled(),
            'api_key_enabled' => $cp->isApiKeyEnabled(),
            'api_keys_last_rotated_at' => $cp->api_keys_last_rotated_at?->toIso8601String(),
            'allowed_domains' => $cp->allowed_domains ?? [],
            'items_count' => $cp->items_count ?? null,
            'records_count' => $cp->records_count ?? null,
            'created_at' => $cp->created_at?->toIso8601String(),
            'updated_at' => $cp->updated_at?->toIso8601String(),
        ];

        if ($lengkap) {
            $isi['primary_color'] = $s['primary_color'] ?? null;
            $isi['accent_color'] = $s['accent_color'] ?? null;
            $isi['modal_intro_text'] = $s['modal_intro_text'] ?? null;
            $isi['items'] = $cp->relationLoaded('items')
                ? $cp->items->map(fn (ConsentItem $it) => [
                    'id' => $it->id,
                    'title' => $it->title,
                    'description' => $it->description,
                    'full_text' => $it->full_text,
                    'category' => $it->category,
                    'version' => $it->version,
                    'is_required' => (bool) $it->is_required,
                    'is_active' => (bool) $it->is_active,
                ])->values()->all()
                : [];
        }

        return $isi;
    }

    /** @return array<string, mixed> */
    private function bentukDsr(DsrRequest $d, bool $lengkap = false): array
    {
        $isi = [
            'id' => $d->id,
            'request_id' => $d->request_id,
            'request_type' => $d->request_type,
            'status' => $d->status,
            'verification_status' => $d->verification_status,
            'requester_name' => $d->requester_name,
            'requester_email' => $d->requester_email,
            'requester_type' => $d->requester_type,
            'requester_relation' => $d->requester_relation,
            'subject_class' => $d->subject_class,
            'subject_identifier' => $d->subject_identifier,
            'guardian_proof_status' => $d->guardian_proof_status,
            'guardian_proof_required' => $d->butuhBuktiWali(),
            'destructive' => in_array((string) $d->request_type, DsrRequest::HAK_MERUSAK, true),
            'deadline_at' => $d->deadline_at?->toIso8601String(),
            'responded_at' => $d->responded_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
            'app' => $d->relationLoaded('app') && $d->app ? ['id' => $d->app->id, 'name' => $d->app->name, 'app_code' => $d->app->app_code] : null,
        ];

        if ($lengkap) {
            $isi += [
                'requester_phone' => $d->requester_phone,
                'description' => $d->description,
                'response' => $d->response,
                'rejection_reason' => $d->rejection_reason,
                'guardian_proof_reason' => $d->guardian_proof_reason,
                'guardian_proof_verified_at' => $d->guardian_proof_verified_at?->toIso8601String(),
                'guardian_consent_id' => $d->guardian_consent_id,
                'assigned_to' => $d->assigned_to,
                'closed_at' => $d->closed_at?->toIso8601String(),
            ];
        }

        return $isi;
    }

    /** @param  array<string, mixed>  $perubahan */
    private function audit(Request $request, string $aksi, string $recordId, array $perubahan = []): void
    {
        $user = $request->user();
        AuditLog::create([
            'module' => ModulSubjek::dariRequest($request),
            'record_id' => $recordId,
            'action' => $aksi,
            'user_id' => $user->id,
            'user_name' => $user->name ?? null,
            'user_role' => $user->role ?? null,
            'changes' => $perubahan,
            'ip_address' => $request->ip(),
        ]);
    }
}
