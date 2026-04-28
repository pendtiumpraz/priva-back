<?php

namespace App\Services\Crm;

use App\Models\CrmCredential;

/**
 * Salesforce Bulk API 2.0 — STUB. Salesforce auth is non-trivial (OAuth +
 * refresh token + per-org instance URL). Returns a clear error so the admin
 * UI surfaces the limitation without crashing.
 *
 * To enable, implement the OAuth dance + the `/services/data/v59.0/jobs/ingest`
 * job lifecycle (create job, upload CSV, close, poll). Tracked separately.
 */
class SalesforceConnector implements CrmConnectorContract
{
    public function push(array $records, CrmCredential $credential): array
    {
        return [
            'success' => 0,
            'failure' => count($records),
            'errors' => ['Salesforce connector not yet implemented. Use webhook target with your custom Apex receiver, or contact support.'],
            'refs' => [],
        ];
    }

    public function probe(CrmCredential $credential): array
    {
        throw new \RuntimeException('Salesforce connector is a stub.');
    }
}
