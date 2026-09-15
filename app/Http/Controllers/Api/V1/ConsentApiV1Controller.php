<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\FireConsentWebhookJob;
use App\Jobs\PushConsentToCrmJob;
use App\Models\ConsentLog;
use App\Models\Guardian;
use App\Models\GuardianConsent;
use App\Models\Organization;
use App\Services\Consent\ConsentOutboundGate;
use App\Services\Consent\GerbangWali;
use App\Services\Consent\LayananWali;
use Illuminate\Http\Request;

/**
 * Consent Partner API v1 — server-to-server (alternative to embed widget).
 *
 * Auth: middleware `consent.api_key` (HMAC-SHA256 signed body).
 *
 * Endpoints:
 *   POST /api/v1/consent/capture           — record consent decision
 *   GET  /api/v1/consent/state             — query latest consent state for user
 *   GET  /api/v1/consent/items             — list active consent items + categories
 *   POST /api/v1/consent/guardian/request  — ajukan verifikasi wali (Pasal 38)
 *   GET  /api/v1/consent/guardian/{id}     — status kewenangan wali
 *
 * Consent anak (PP 33/2026 Pasal 38): `capture` dengan `subject_class=anak`
 * WAJIB menyertakan `guardian_consent_id` yang sah — terverifikasi, belum
 * dicabut, milik tenant ini, dan memang untuk `user_identifier` yang sama.
 * Tanpa itu: 422 `KEWENANGAN_WALI_WAJIB`, dan tidak ada baris ledger.
 */
class ConsentApiV1Controller extends Controller
{
    /**
     * Ajukan verifikasi wali dari sisi server tenant. Tautan tetap dikirim ke
     * surel wali oleh Privasimu; tenant memantau lewat `guardian/{id}` atau
     * webhook `consent.captured` (source: guardian_verify).
     */
    public function guardianRequest(Request $request)
    {
        $cp = $request->consentCollection;
        if (! $cp) {
            return response()->json(['error' => 'Collection not resolved'], 500);
        }

        $data = $request->validate([
            'user_identifier' => 'required|string|max:200',
            'subject_class' => 'required|in:anak,disabilitas',
            'consented_items' => 'required|array',
            'policy_version' => 'nullable|string|max:32',
            'guardian' => 'required|array',
            'guardian.name' => 'required|string|max:120',
            'guardian.contact' => 'required|string|max:200',
            'guardian.relationship' => 'required|in:'.implode(',', Guardian::HUBUNGAN),
            'guardian.relationship_note' => 'nullable|string|max:255',
            'transition_date' => 'nullable|date|after:today',
            'subject_own_channel' => 'nullable|string|max:200',
            'external_user_ref' => 'nullable|string|max:120',
            'source_form' => 'nullable|string|max:120',
        ]);

        $kw = app(LayananWali::class)->ajukan($cp, $data, (string) $request->ip(), $request->userAgent(), 'partner_api');

        return response()->json([
            'message' => 'Tautan persetujuan telah dikirim ke wali.',
            'status' => 'menunggu_wali',
            'guardian_consent_id' => $kw->id,
            'expires_at' => $kw->verification_expires_at?->toIso8601String(),
        ], 202);
    }

    public function guardianStatus(Request $request, string $id)
    {
        $cp = $request->consentCollection;
        if (! $cp) {
            return response()->json(['error' => 'Collection not resolved'], 500);
        }

        $kw = GuardianConsent::withoutGlobalScope('org')
            ->where('org_id', $cp->org_id)
            ->with(['consentSubject', 'guardian'])
            ->find($id);

        if (! $kw) {
            return response()->json(['error' => 'Kewenangan wali tidak ditemukan.'], 404);
        }

        $status = match (true) {
            $kw->revoked_at !== null => 'dicabut',
            $kw->verified_at !== null => 'terverifikasi',
            default => 'menunggu_wali',
        };

        return response()->json([
            'guardian_consent_id' => $kw->id,
            'status' => $status,
            'subject_class' => $kw->consentSubject?->subject_class,
            'relationship' => $kw->guardian?->relationship,
            'verification_method_code' => $kw->verification_method_code,
            'verification_confidence' => $kw->verification_confidence,
            'verified_at' => $kw->verified_at?->toIso8601String(),
            'revoked_at' => $kw->revoked_at?->toIso8601String(),
            'revoke_reason' => $kw->revoke_reason,
            'expires_at' => $kw->verification_expires_at?->toIso8601String(),
            'transition_date' => $kw->consentSubject?->transition_date?->toDateString(),
        ]);
    }

    public function capture(Request $request)
    {
        $cp = $request->consentCollection;
        if (! $cp) {
            return response()->json(['error' => 'Collection not resolved'], 500);
        }

        $data = $request->validate([
            'user_identifier' => 'required|string|max:200',
            'consented_items' => 'required|array',
            'policy_version' => 'nullable|string|max:32',
            'channel' => 'nullable|string|max:64', // klien's source: 'web' | 'mobile' | 'cs_form' | etc
            // PP 33/2026 Pasal 38 — diperiksa GerbangWali sebelum ledger ditulis.
            'subject_class' => 'nullable|in:dewasa,anak,disabilitas',
            'guardian_consent_id' => 'nullable|uuid',
        ]);

        // Gerbang yang SAMA dengan jalur widget, dan sama-sama SEBELUM tulis.
        $wali = app(GerbangWali::class)->periksa(
            $cp,
            $data['user_identifier'],
            $data['subject_class'] ?? null,
            $data['guardian_consent_id'] ?? null,
        );

        $log = ConsentLog::create([
            'org_id' => $cp->org_id,
            'collection_id' => $cp->id,
            'user_identifier' => $data['user_identifier'],
            'subject_class' => $wali['subject_class'],
            'guardian_consent_id' => $wali['guardian_consent_id'],
            'consented_items' => $data['consented_items'],
            'policy_version' => $data['policy_version'] ?? '1.0',
            'ip_address' => $request->ip(),
            'user_agent' => 'partner_api:'.($data['channel'] ?? 'unknown'),
        ]);

        // Gerbang yang sama dengan jalur widget. Pintu masuk yang berbeda tidak
        // boleh menghasilkan penjagaan yang berbeda — kalau partner API lolos
        // sementara widget dijaga, aturannya hanya menyulitkan tenant yang
        // jujur.
        $gate = app(ConsentOutboundGate::class)->decide(
            $cp,
            (string) $log->user_identifier,
            'partner_api'
        );

        if ($gate === null || ! $gate->blocked) {
            if ($cp->webhook_url) {
                FireConsentWebhookJob::dispatch(
                    $cp->webhook_url,
                    $cp->collection_id,
                    array_merge([
                        'event' => 'consent.captured',
                        'source' => 'partner_api',
                        'collection_id' => $cp->collection_id,
                        'user_identifier' => $log->user_identifier,
                        'consented_items' => $log->consented_items,
                        'policy_version' => $log->policy_version,
                        'timestamp' => $log->created_at,
                    ], $gate ? ['decision' => ConsentOutboundGate::payload($gate)] : [])
                );
            }

            $org = Organization::find($cp->org_id);
            $crms = $org?->settings['crm_connections'] ?? [];
            foreach ($crms as $providerId => $config) {
                PushConsentToCrmJob::dispatch($providerId, (array) $config, $log->id);
            }
        }

        return response()->json([
            'message' => 'Consent captured.',
            'log_id' => $log->id,
            'created_at' => $log->created_at?->toIso8601String(),
        ], 201);
    }

    public function state(Request $request)
    {
        $cp = $request->consentCollection;
        $data = $request->validate(['user_identifier' => 'required|string|max:200']);

        $latest = ConsentLog::where('collection_id', $cp->id)
            ->where('user_identifier', $data['user_identifier'])
            ->latest()->first();

        return response()->json([
            'has_record' => (bool) $latest,
            'consented_items' => $latest?->consented_items ?? [],
            'policy_version' => $latest?->policy_version,
            'last_updated' => $latest?->created_at?->toIso8601String(),
        ]);
    }

    public function items(Request $request)
    {
        $cp = $request->consentCollection;
        $items = $cp->items()->where('is_active', true)
            ->orderBy('category')->orderBy('title')
            ->get(['id', 'title', 'description', 'category', 'cookie_keys', 'version', 'is_required']);

        return response()->json([
            'collection' => [
                'name' => $cp->name,
                'collection_id' => $cp->collection_id,
                'display_mode' => $cp->display_mode,
                'audience' => $cp->audience,
            ],
            'items' => $items,
        ]);
    }
}
