<?php

namespace App\Services\Consent;

use App\Jobs\FireConsentWebhookJob;
use App\Jobs\PushConsentToCrmJob;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentLog;
use App\Models\Organization;

/**
 * Penyebaran satu baris ledger ke webhook tenant dan CRM — SETELAH gerbang
 * aturan consent (ConsentOutboundGate) memutuskan boleh.
 *
 * Dipakai jalur wali (persetujuan lewat tautan) dan jalur peralihan
 * (penarikan saat dewasa). Dua jalur tangkap yang lebih tua
 * (ConsentLogController, ConsentApiV1Controller) masih membawa salinannya
 * sendiri — payload webhook mereka sudah dipegang integrasi tenant dan tidak
 * disentuh di sini; menyatukannya adalah pekerjaan tersendiri.
 */
final class PenyebarConsent
{
    /**
     * @param  array<string, mixed>  $tambahan  bidang ekstra untuk penerima webhook
     */
    public function sebarkan(ConsentCollectionPoint $cp, ConsentLog $log, string $sumber, array $tambahan = []): void
    {
        $gate = app(ConsentOutboundGate::class)->decide($cp, (string) $log->user_identifier, $sumber);

        if ($gate !== null && $gate->blocked) {
            return;
        }

        if ($cp->webhook_url) {
            FireConsentWebhookJob::dispatch(
                $cp->webhook_url,
                $cp->collection_id,
                array_merge([
                    'event' => 'consent.captured',
                    'source' => $sumber,
                    'collection_id' => $cp->collection_id,
                    'user_identifier' => $log->user_identifier,
                    'consented_items' => $log->consented_items,
                    'consented_items_labeled' => $log->labeledConsentedItems(),
                    'consented_purposes' => $log->grantedPurposeTitles(),
                    'policy_version' => $log->policy_version,
                    'ip_address' => $log->ip_address,
                    'timestamp' => $log->created_at,
                    'subject_class' => $log->subject_class,
                ], $tambahan, $gate ? ['decision' => ConsentOutboundGate::payload($gate)] : []),
            );
        }

        $org = Organization::find($cp->org_id);
        foreach (($org?->settings['crm_connections'] ?? []) as $providerId => $config) {
            PushConsentToCrmJob::dispatch($providerId, (array) $config, $log->id);
        }
    }
}
