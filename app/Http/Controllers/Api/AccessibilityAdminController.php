<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessibilityProvision;
use App\Models\AuditLog;
use App\Models\CapacityAssessment;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentSubject;
use App\Models\DisabilityServiceScope;
use App\Support\AssignmentScope;
use App\Support\KelasSubjek;
use App\Support\KunciPencarian;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Aksesibilitas untuk dashboard tenant — PP 33/2026 Pasal 39.
 *
 * Tiga hal, tiga tabel, tiga makna yang tidak boleh dicampur:
 *
 *   prasarana (accessibility_provisions)  APA yang tersedia di sebuah kanal —
 *                                         dan kapan terakhir DIUJI. "Tersedia"
 *                                         tanpa tanggal uji tetap belum terbukti.
 *   ragam (disability_service_scopes)     SIAPA yang dilayani kanal itu — kanal,
 *                                         BUKAN orang. Tidak ada satu pun kolom
 *                                         yang menunjuk pengguna.
 *   penilaian (capacity_assessments)      hasil penilaian atas SEORANG subjek;
 *                                         hanya "diwakili wali" yang memindahkan
 *                                         keputusan ke wali; alasan wajib.
 *
 * Sub-fitur modul Consent (izin `consent`). Prasarana dan ragam yang terikat
 * titik pengumpulan disaring divisi lewat titik yang terlihat; yang tidak
 * terikat (kanal umum: "Loket Cabang") milik seluruh tenant. Penilaian
 * kapasitas tidak terikat titik — ia tentang orang — dan tersaring tenant saja.
 */
class AccessibilityAdminController extends Controller
{
    // ───────────────────────── ringkasan ─────────────────────────

    public function summary(Request $request)
    {
        $prasarana = $this->prasarana($request)->get();
        $ragam = $this->ragam($request)->get();
        $penilaian = $this->penilaian($request)->get();

        $perHasil = [];
        foreach (CapacityAssessment::HASIL as $h) {
            $perHasil[$h] = $penilaian->where('result', $h)->count();
        }

        return response()->json(['data' => [
            'provisions' => $prasarana->count(),
            'provisions_proven' => $prasarana->filter(fn (AccessibilityProvision $p) => $p->sudahTerbukti())->count(),
            'provisions_stale' => $prasarana->filter(fn (AccessibilityProvision $p) => $p->ujiKedaluwarsa())->count(),
            'channels_served' => $ragam->where('is_served', true)->pluck('channel')->unique()->count(),
            'assessments' => $penilaian->count(),
            'assessments_by_result' => $perHasil,
        ]]);
    }

    // ───────────────────────── prasarana ─────────────────────────

    public function provisions(Request $request)
    {
        $q = $this->prasarana($request)->with('collectionPoint')->orderBy('channel')->orderBy('format');
        if ($request->filled('collection_point_id')) {
            $q->where('collection_point_id', $request->input('collection_point_id'));
        }

        return response()->json([
            'data' => $q->get()->map(fn (AccessibilityProvision $p) => $this->bentukPrasarana($p))->values()->all(),
            'formats' => AccessibilityProvision::LABEL,
        ]);
    }

    public function storeProvision(Request $request)
    {
        $data = $request->validate($this->aturanPrasarana());
        $user = $request->user();
        $cp = $this->titikMilikTenant($request, $data['collection_point_id'] ?? null);

        $ada = AccessibilityProvision::withoutGlobalScope('org')
            ->where('org_id', $user->org_id)
            ->where('channel', $data['channel'])
            ->where('format', $data['format'])
            ->exists();
        if ($ada) {
            return response()->json([
                'error' => 'Format ini sudah terdaftar untuk kanal tersebut — perbarui barisnya, jangan gandakan.',
                'code' => 'SUDAH_ADA',
            ], 409);
        }

        $p = AccessibilityProvision::create(array_merge($data, [
            'org_id' => $user->org_id,
            'collection_point_id' => $cp?->id,
            'is_available' => (bool) ($data['is_available'] ?? true),
            'created_by' => $user->id,
        ]));

        $this->audit($request, 'accessibility_provision.create', $p->id, ['channel' => $p->channel, 'format' => $p->format]);
        $this->segarkanConfig($cp);

        return response()->json(['data' => $this->bentukPrasarana($p->load('collectionPoint'))], 201);
    }

    public function updateProvision(Request $request, string $id)
    {
        $p = $this->prasarana($request)->find($id);
        if (! $p) {
            abort(404, 'Prasarana tidak ditemukan.');
        }

        $data = $request->validate($this->aturanPrasarana(sometimes: true));
        $cpLama = $p->collectionPoint;
        $cp = array_key_exists('collection_point_id', $data)
            ? $this->titikMilikTenant($request, $data['collection_point_id'])
            : $cpLama;

        $channel = $data['channel'] ?? $p->channel;
        $format = $data['format'] ?? $p->format;
        $duplikat = AccessibilityProvision::withoutGlobalScope('org')
            ->where('org_id', $p->org_id)
            ->where('channel', $channel)->where('format', $format)
            ->where('id', '!=', $p->id)
            ->exists();
        if ($duplikat) {
            return response()->json(['error' => 'Format ini sudah terdaftar untuk kanal tersebut.', 'code' => 'SUDAH_ADA'], 409);
        }

        $p->fill($data);
        if (array_key_exists('collection_point_id', $data)) {
            $p->collection_point_id = $cp?->id;
        }
        $p->save();

        $this->audit($request, 'accessibility_provision.update', $p->id, $data);
        $this->segarkanConfig($cpLama);
        $this->segarkanConfig($cp);

        return response()->json(['data' => $this->bentukPrasarana($p->fresh(['collectionPoint']))]);
    }

    public function destroyProvision(Request $request, string $id)
    {
        $p = $this->prasarana($request)->find($id);
        if (! $p) {
            abort(404, 'Prasarana tidak ditemukan.');
        }

        $cp = $p->collectionPoint;
        $p->delete();
        $this->audit($request, 'accessibility_provision.delete', $id, ['channel' => $p->channel, 'format' => $p->format]);
        $this->segarkanConfig($cp);

        return response()->json(['message' => 'Prasarana dihapus.']);
    }

    // ───────────────────────── ragam dilayani ─────────────────────────

    public function scopes(Request $request)
    {
        $q = $this->ragam($request)->with('collectionPoint')->orderBy('channel')->orderBy('ragam');
        if ($request->filled('collection_point_id')) {
            $q->where('collection_point_id', $request->input('collection_point_id'));
        }

        return response()->json([
            'data' => $q->get()->map(fn (DisabilityServiceScope $s) => $this->bentukRagam($s))->values()->all(),
            'ragam' => DisabilityServiceScope::LABEL,
        ]);
    }

    public function storeScope(Request $request)
    {
        $data = $request->validate($this->aturanRagam());
        $user = $request->user();
        $cp = $this->titikMilikTenant($request, $data['collection_point_id'] ?? null);

        $ada = DisabilityServiceScope::withoutGlobalScope('org')
            ->where('org_id', $user->org_id)
            ->where('channel', $data['channel'])
            ->where('ragam', $data['ragam'])
            ->exists();
        if ($ada) {
            return response()->json(['error' => 'Ragam ini sudah terdaftar untuk kanal tersebut.', 'code' => 'SUDAH_ADA'], 409);
        }

        $s = DisabilityServiceScope::create(array_merge($data, [
            'org_id' => $user->org_id,
            'collection_point_id' => $cp?->id,
            'is_served' => (bool) ($data['is_served'] ?? true),
            'created_by' => $user->id,
        ]));

        $this->audit($request, 'disability_scope.create', $s->id, ['channel' => $s->channel, 'ragam' => $s->ragam]);
        $this->segarkanConfig($cp);

        return response()->json(['data' => $this->bentukRagam($s->load('collectionPoint'))], 201);
    }

    public function updateScope(Request $request, string $id)
    {
        $s = $this->ragam($request)->find($id);
        if (! $s) {
            abort(404, 'Ragam tidak ditemukan.');
        }

        $data = $request->validate($this->aturanRagam(sometimes: true));
        $cpLama = $s->collectionPoint;
        $cp = array_key_exists('collection_point_id', $data)
            ? $this->titikMilikTenant($request, $data['collection_point_id'])
            : $cpLama;

        $channel = $data['channel'] ?? $s->channel;
        $ragam = $data['ragam'] ?? $s->ragam;
        $duplikat = DisabilityServiceScope::withoutGlobalScope('org')
            ->where('org_id', $s->org_id)
            ->where('channel', $channel)->where('ragam', $ragam)
            ->where('id', '!=', $s->id)
            ->exists();
        if ($duplikat) {
            return response()->json(['error' => 'Ragam ini sudah terdaftar untuk kanal tersebut.', 'code' => 'SUDAH_ADA'], 409);
        }

        $s->fill($data);
        if (array_key_exists('collection_point_id', $data)) {
            $s->collection_point_id = $cp?->id;
        }
        $s->save();

        $this->audit($request, 'disability_scope.update', $s->id, $data);
        $this->segarkanConfig($cpLama);
        $this->segarkanConfig($cp);

        return response()->json(['data' => $this->bentukRagam($s->fresh(['collectionPoint']))]);
    }

    public function destroyScope(Request $request, string $id)
    {
        $s = $this->ragam($request)->find($id);
        if (! $s) {
            abort(404, 'Ragam tidak ditemukan.');
        }

        $cp = $s->collectionPoint;
        $s->delete();
        $this->audit($request, 'disability_scope.delete', $id, ['channel' => $s->channel, 'ragam' => $s->ragam]);
        $this->segarkanConfig($cp);

        return response()->json(['message' => 'Ragam dihapus.']);
    }

    // ───────────────────────── penilaian kapasitas ─────────────────────────

    public function assessments(Request $request)
    {
        $q = $this->penilaian($request)->with('consentSubject')->orderByDesc('assessed_at')->orderByDesc('created_at');

        if ($request->filled('subject')) {
            $hash = KunciPencarian::hash($request->input('subject'));
            $q->whereHas('consentSubject', fn ($s) => $s->where('subject_hash', $hash));
        }

        return response()->json([
            'data' => $q->limit(300)->get()->map(fn (CapacityAssessment $a) => $this->bentukPenilaian($a))->values()->all(),
            'results' => CapacityAssessment::HASIL,
        ]);
    }

    public function storeAssessment(Request $request)
    {
        $data = $request->validate([
            'subject' => 'required|string|max:200',
            'subject_class' => ['sometimes', Rule::in(KelasSubjek::DILINDUNGI)],
            'result' => ['required', Rule::in(CapacityAssessment::HASIL)],
            // Wajib. Penilaian tanpa alasan tertulis bukan penilaian — ia
            // keputusan yang tak bisa ditinjau, dibantah, atau diaudit.
            'reason' => 'required|string|min:10|max:1000',
            'assessed_at' => 'nullable|date',
            'dpia_id' => 'nullable|uuid',
        ]);
        $user = $request->user();

        $subjek = ConsentSubject::temukanAtauBuat($user->org_id, $data['subject'], [
            'subject_class' => $data['subject_class'] ?? KelasSubjek::DISABILITAS,
        ]);

        $a = CapacityAssessment::create([
            'org_id' => $user->org_id,
            'consent_subject_id' => $subjek->id,
            'subject_class' => $subjek->subject_class,
            'result' => $data['result'],
            'reason' => $data['reason'],
            'assessed_by' => $user->id,
            'assessed_at' => $data['assessed_at'] ?? now(),
            'dpia_id' => $data['dpia_id'] ?? null,
        ]);

        $this->audit($request, 'capacity_assessment.create', $a->id, ['result' => $a->result, 'subject_class' => $a->subject_class]);

        return response()->json(['data' => $this->bentukPenilaian($a->load('consentSubject'))], 201);
    }

    // ───────────────────────── internal ─────────────────────────

    /** @return array<string, mixed> */
    private function aturanPrasarana(bool $sometimes = false): array
    {
        $s = $sometimes ? 'sometimes|' : '';

        return [
            'channel' => $s.'required|string|max:120',
            'collection_point_id' => 'sometimes|nullable|uuid',
            'format' => [$sometimes ? 'sometimes' : 'required', Rule::in(AccessibilityProvision::FORMAT)],
            'format_note' => 'nullable|string|max:255',
            'is_available' => 'sometimes|boolean',
            'evidence_ref' => 'nullable|string|max:255',
            'last_tested_at' => 'nullable|date',
            'next_review_at' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /** @return array<string, mixed> */
    private function aturanRagam(bool $sometimes = false): array
    {
        $s = $sometimes ? 'sometimes|' : '';

        return [
            'channel' => $s.'required|string|max:120',
            'collection_point_id' => 'sometimes|nullable|uuid',
            'ragam' => [$sometimes ? 'sometimes' : 'required', Rule::in(DisabilityServiceScope::RAGAM)],
            'is_served' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /** @return Builder<AccessibilityProvision> */
    private function prasarana(Request $request): Builder
    {
        return $this->batasiDivisi($request, AccessibilityProvision::withoutGlobalScope('org')
            ->where('org_id', $request->user()->org_id));
    }

    /** @return Builder<DisabilityServiceScope> */
    private function ragam(Request $request): Builder
    {
        return $this->batasiDivisi($request, DisabilityServiceScope::withoutGlobalScope('org')
            ->where('org_id', $request->user()->org_id));
    }

    /** @return Builder<CapacityAssessment> */
    private function penilaian(Request $request): Builder
    {
        return CapacityAssessment::withoutGlobalScope('org')->where('org_id', $request->user()->org_id);
    }

    /**
     * Baris yang terikat titik pengumpulan hanya terlihat bila titiknya
     * terlihat (AssignmentVisibility). Baris tanpa titik — kanal umum seperti
     * "Loket Cabang" — milik seluruh tenant.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     * @return Builder<TModel>
     */
    private function batasiDivisi(Request $request, Builder $q): Builder
    {
        $user = $request->user();
        if (AssignmentScope::melihatSeluruhTenant($user)) {
            return $q;
        }

        $terlihat = ConsentCollectionPoint::query()
            ->where('org_id', $user->org_id)
            ->visibleTo($user)
            ->select('id');

        return $q->where(fn ($w) => $w->whereNull('collection_point_id')->orWhereIn('collection_point_id', $terlihat));
    }

    /** Titik pengumpulan milik tenant ini DAN terlihat pengguna — atau 422. */
    private function titikMilikTenant(Request $request, ?string $id): ?ConsentCollectionPoint
    {
        if ($id === null || $id === '') {
            return null;
        }

        $user = $request->user();
        $q = ConsentCollectionPoint::query()->where('org_id', $user->org_id);
        if (! AssignmentScope::melihatSeluruhTenant($user)) {
            $q->visibleTo($user);
        }
        $cp = $q->find($id);

        if (! $cp) {
            abort(response()->json([
                'error' => 'Titik pengumpulan tidak ditemukan atau tidak terlihat oleh Anda.',
                'errors' => ['collection_point_id' => ['Titik pengumpulan tidak ditemukan.']],
            ], 422));
        }

        return $cp;
    }

    /**
     * Config publik widget di-cache 5 menit per (penanda titik | filter).
     * Prasarana yang berubah harus segera terlihat widget — kalau tidak,
     * format yang baru saja dinonaktifkan masih dijanjikan sampai 5 menit.
     */
    private function segarkanConfig(?ConsentCollectionPoint $cp): void
    {
        if (! $cp) {
            return;
        }

        foreach (array_filter([$cp->collection_id, $cp->id, $cp->embed_token]) as $kunci) {
            Cache::forget('consent:config:'.sha1((string) $kunci));
            foreach (['all', 'app', 'cookie'] as $filter) {
                Cache::forget('consent:config:'.sha1($kunci.'|'.$filter));
            }
        }
    }

    /** @param  array<string, mixed>  $perubahan */
    private function audit(Request $request, string $aksi, string $recordId, array $perubahan = []): void
    {
        $user = $request->user();
        AuditLog::create([
            'module' => 'consent',
            'record_id' => $recordId,
            'action' => $aksi,
            'user_id' => $user->id,
            'user_name' => $user->name ?? null,
            'user_role' => $user->role ?? null,
            'changes' => $perubahan,
            'ip_address' => $request->ip(),
        ]);
    }

    /** @return array<string, mixed> */
    private function bentukPrasarana(AccessibilityProvision $p): array
    {
        $cp = $p->collectionPoint;

        return [
            'id' => $p->id,
            'channel' => $p->channel,
            'collection_point' => $cp ? ['id' => $cp->id, 'name' => $cp->name, 'collection_id' => $cp->collection_id] : null,
            'format' => $p->format,
            'format_label' => AccessibilityProvision::LABEL[$p->format] ?? $p->format,
            'format_note' => $p->format_note,
            'is_available' => (bool) $p->is_available,
            'evidence_ref' => $p->evidence_ref,
            'last_tested_at' => $p->last_tested_at?->toDateString(),
            'next_review_at' => $p->next_review_at?->toDateString(),
            // Dua keadaan yang berbeda dan tidak boleh dicampur (lihat model).
            'sudah_terbukti' => $p->sudahTerbukti(),
            'uji_kedaluwarsa' => $p->ujiKedaluwarsa(),
            'notes' => $p->notes,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function bentukRagam(DisabilityServiceScope $s): array
    {
        $cp = $s->collectionPoint;

        return [
            'id' => $s->id,
            'channel' => $s->channel,
            'collection_point' => $cp ? ['id' => $cp->id, 'name' => $cp->name, 'collection_id' => $cp->collection_id] : null,
            'ragam' => $s->ragam,
            'ragam_label' => DisabilityServiceScope::LABEL[$s->ragam] ?? $s->ragam,
            'is_served' => (bool) $s->is_served,
            'boleh_mandiri' => DisabilityServiceScope::bolehMandiri((string) $s->ragam),
            'notes' => $s->notes,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function bentukPenilaian(CapacityAssessment $a): array
    {
        $subjek = $a->consentSubject;

        return [
            'id' => $a->id,
            'subject' => $subjek ? ['id' => $subjek->id, 'label' => $subjek->subject_label, 'class' => $subjek->subject_class] : null,
            'subject_class' => $a->subject_class,
            'result' => $a->result,
            'subjek_memutuskan_sendiri' => $a->subjekMemutuskanSendiri(),
            'reason' => $a->reason,
            'assessed_by' => $a->assessed_by,
            'assessed_at' => $a->assessed_at?->toIso8601String(),
            'dpia_id' => $a->dpia_id,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
