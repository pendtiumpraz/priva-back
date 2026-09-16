<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentLog;
use App\Models\ConsentSubject;
use App\Models\GuardianConsent;
use App\Services\Consent\LayananPeralihan;
use App\Services\Consent\LayananWali;
use App\Support\AssignmentScope;
use App\Support\KunciPencarian;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Kewenangan wali untuk dashboard tenant — PP 33/2026 Pasal 38 & 39.
 *
 * Sub-fitur modul Consent (izin `consent`): datanya adalah bagian dari ledger
 * consent, dan nama modul tampilannya masih menunggu keputusan CEO. Saat nama
 * itu turun, yang berubah hanya label menu — bukan endpoint ini.
 *
 * DUA LAPIS PENYARINGAN, keduanya wajib:
 *   1. org_id — selalu, eksplisit, walau global scope `org` juga aktif.
 *   2. divisi — lewat titik pengumpulan yang TERLIHAT oleh pengguna
 *      (AssignmentVisibility pada ConsentCollectionPoint). Staf divisi HR
 *      tidak melihat kewenangan yang diajukan lewat formulir milik Finance.
 *      Admin/DPO yang melihat seluruh tenant tidak disaring.
 *
 * Yang TIDAK pernah keluar dari sini: hash token tautan dan pilihan yang
 * menunggu (keduanya $hidden di model). Yang keluar dari verifikasi adalah
 * hasilnya — metode, keyakinan, kapan — bukan datanya.
 */
class GuardianConsentAdminController extends Controller
{
    public function index(Request $request)
    {
        $q = $this->dasar($request)
            ->with(['consentSubject', 'guardian', 'collectionPoint'])
            ->withCount('consentLogs')
            ->orderByDesc('created_at');

        match ($request->input('status')) {
            'menunggu_wali' => $q->whereNull('revoked_at')->whereNull('verified_at'),
            'terverifikasi' => $q->whereNull('revoked_at')->whereNotNull('verified_at'),
            'dicabut' => $q->whereNotNull('revoked_at'),
            default => null,
        };

        if ($request->filled('collection_point_id')) {
            $q->where('collection_point_id', $request->input('collection_point_id'));
        }

        // Pencarian lewat HASH ternormalkan — kontak wali dan penanda subjek
        // sama-sama tersandi, jadi LIKE atas kolomnya tidak akan pernah cocok.
        // Konsekuensinya: pencarian harus persis (setelah normalisasi), bukan
        // sebagian. Itu disebut di placeholder kolom pencarian.
        if ($request->filled('search')) {
            $hash = KunciPencarian::hash($request->input('search'));
            if ($hash !== null) {
                $q->where(function ($w) use ($hash) {
                    $w->whereHas('guardian', fn ($g) => $g->where('contact_hash', $hash))
                        ->orWhereHas('consentSubject', fn ($s) => $s->where('subject_hash', $hash));
                });
            }
        }

        return response()->json([
            'data' => $q->limit(500)->get()->map(fn (GuardianConsent $kw) => $this->bentuk($kw))->values()->all(),
        ]);
    }

    public function show(Request $request, string $id)
    {
        $kw = $this->temukan($request, $id);

        $logs = ConsentLog::withoutGlobalScope('org')
            ->where('org_id', $kw->org_id)
            ->where('guardian_consent_id', $kw->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (ConsentLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at?->toIso8601String(),
                'subject_class' => $log->subject_class,
                'source_form' => $log->source_form,
                'ip_address' => $log->ip_address,
                'consented_items_labeled' => $log->labeledConsentedItems(),
                'policy_version' => $log->policy_version,
            ])
            ->all();

        return response()->json(['data' => $this->bentuk($kw) + ['logs' => $logs]]);
    }

    public function revoke(Request $request, string $id)
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);
        $kw = $this->temukan($request, $id);

        if ($kw->revoked_at !== null) {
            return response()->json([
                'error' => 'Kewenangan wali ini sudah dicabut sebelumnya.',
                'code' => 'SUDAH_DICABUT',
            ], 409);
        }

        $user = $request->user();
        $kw->cabut('manual', $data['note'] ?? null, $user->id);

        AuditLog::create([
            'module' => 'consent',
            'record_id' => $kw->id,
            'action' => 'guardian_consent.revoke',
            'user_id' => $user->id,
            'user_name' => $user->name ?? null,
            'user_role' => $user->role ?? null,
            'changes' => ['revoke_reason' => 'manual', 'revoke_note' => $data['note'] ?? null],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['data' => $this->bentuk($kw->fresh(['consentSubject', 'guardian', 'collectionPoint']))]);
    }

    public function resend(Request $request, string $id)
    {
        $kw = $this->temukan($request, $id);

        // Menolak terbuka (422/409/429) lewat HttpResponseException dari layanan.
        app(LayananWali::class)->kirimUlang($kw);

        $user = $request->user();
        AuditLog::create([
            'module' => 'consent',
            'record_id' => $kw->id,
            'action' => 'guardian_consent.resend',
            'user_id' => $user->id,
            'user_name' => $user->name ?? null,
            'user_role' => $user->role ?? null,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Tautan persetujuan baru telah dikirim ke wali.',
            'expires_at' => $kw->fresh()?->verification_expires_at?->toIso8601String(),
        ]);
    }

    public function stats(Request $request)
    {
        $dasar = fn () => $this->dasar($request);

        return response()->json(['data' => [
            'menunggu_wali' => $dasar()->whereNull('revoked_at')->whereNull('verified_at')->count(),
            'terverifikasi' => $dasar()->whereNull('revoked_at')->whereNotNull('verified_at')->count(),
            'dicabut' => $dasar()->whereNotNull('revoked_at')->count(),
            // Anak yang akan genap 18 dalam 30 hari dan belum disentuh antrean
            // peralihan — Pasal 38 ayat (8). Dihitung per ORANG, bukan per wali.
            'peralihan_segera' => $dasar()
                ->whereNull('revoked_at')
                ->whereHas('consentSubject', fn ($s) => $s
                    ->whereNull('transition_state')
                    ->whereBetween('transition_date', [Carbon::today(), Carbon::today()->addDays(30)]))
                ->distinct('consent_subject_id')
                ->count('consent_subject_id'),
        ]]);
    }

    /**
     * Kirim ulang tautan keputusan peralihan (Pasal 38 ayat 8) ke kanal MILIK
     * subjek — untuk antrean kerja yang subjeknya belum menanggapi. Subjek
     * tanpa kanal, atau yang tidak sedang menunggu, ditolak terbuka.
     */
    public function transitionResend(Request $request, string $subjectId)
    {
        $user = $request->user();

        // Subjek yang terlihat pengguna = subjek yang kewenangannya (apa pun
        // keadaannya) lewat titik pengumpulan yang terlihat.
        $subjek = ConsentSubject::withoutGlobalScope('org')
            ->where('org_id', $user->org_id)
            ->whereIn('id', $this->dasar($request)->select('consent_subject_id'))
            ->find($subjectId);

        if (! $subjek) {
            abort(404, 'Subjek tidak ditemukan.');
        }

        app(LayananPeralihan::class)->kirimUlang($subjek);

        AuditLog::create([
            'module' => 'consent',
            'record_id' => $subjek->id,
            'action' => 'consent_subject.transition_resend',
            'user_id' => $user->id,
            'user_name' => $user->name ?? null,
            'user_role' => $user->role ?? null,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Tautan keputusan dikirim ulang ke kanal milik subjek.',
            'expires_at' => $subjek->fresh()?->transition_token_expires_at?->toIso8601String(),
        ]);
    }

    // ───────────────────────── internal ─────────────────────────

    /** @return Builder<GuardianConsent> */
    private function dasar(Request $request): Builder
    {
        $user = $request->user();

        $q = GuardianConsent::withoutGlobalScope('org')->where('org_id', $user->org_id);

        if (! AssignmentScope::melihatSeluruhTenant($user)) {
            $terlihat = ConsentCollectionPoint::query()
                ->where('org_id', $user->org_id)
                ->visibleTo($user)
                ->select('id');

            $q->whereIn('collection_point_id', $terlihat);
        }

        return $q;
    }

    private function temukan(Request $request, string $id): GuardianConsent
    {
        $kw = $this->dasar($request)
            ->with(['consentSubject', 'guardian', 'collectionPoint'])
            ->find($id);

        if (! $kw) {
            abort(404, 'Kewenangan wali tidak ditemukan.');
        }

        return $kw;
    }

    /** @return array<string, mixed> */
    private function bentuk(GuardianConsent $kw): array
    {
        $subjek = $kw->consentSubject;
        $wali = $kw->guardian;
        $cp = $kw->collectionPoint;

        $status = match (true) {
            $kw->revoked_at !== null => 'dicabut',
            $kw->verified_at !== null => 'terverifikasi',
            default => 'menunggu_wali',
        };

        return [
            'id' => $kw->id,
            'status' => $status,
            'subject' => [
                'id' => $subjek?->id,
                'label' => $subjek?->subject_label,
                'class' => $subjek?->subject_class,
                'transition_date' => $subjek?->transition_date?->toDateString(),
                'transition_state' => $subjek?->transition_state,
                // Pasal 38 ayat (8): kapan tautan dikirim, kapan subjek memutuskan.
                // Menunggu + notified NULL = tidak punya kanal = antrean kerja.
                'transition_notified_at' => $subjek?->transition_notified_at?->toIso8601String(),
                'transition_confirmed_at' => $subjek?->transition_confirmed_at?->toIso8601String(),
                'has_own_channel' => (bool) ($subjek?->subject_own_channel),
            ],
            'guardian' => [
                'name' => $wali?->name,
                'contact' => $wali?->contact,
                'contact_type' => $wali?->contact_type,
                'relationship' => $wali?->relationship,
                'relationship_note' => $wali?->relationship_note,
            ],
            'collection_point' => $cp ? [
                'id' => $cp->id,
                'name' => $cp->name,
                'collection_id' => $cp->collection_id,
            ] : null,
            'verification' => [
                'method_code' => $kw->verification_method_code,
                'driver' => $kw->verification_driver,
                'confidence' => $kw->verification_confidence,
                'verified_at' => $kw->verified_at?->toIso8601String(),
                'expires_at' => $kw->verification_expires_at?->toIso8601String(),
                'has_pending' => ($kw->pending_capture ?? []) !== [],
            ],
            'statement_shown' => $kw->statement_shown,
            'ip_address' => $kw->ip_address,
            'revoked_at' => $kw->revoked_at?->toIso8601String(),
            'revoke_reason' => $kw->revoke_reason,
            'revoke_note' => $kw->revoke_note,
            'revoked_by' => $kw->revoked_by,
            'logs_count' => (int) ($kw->getAttribute('consent_logs_count') ?? 0),
            'created_at' => $kw->created_at?->toIso8601String(),
            'updated_at' => $kw->updated_at?->toIso8601String(),
        ];
    }
}
