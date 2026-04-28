<?php

namespace App\Services\Crm;

use App\Models\CrmCredential;

/**
 * Contract every CRM connector implements. Stays sync; the caller (job)
 * handles batching + retry semantics.
 */
interface CrmConnectorContract
{
    /**
     * Push a batch of consent records to the upstream CRM.
     *
     * @param  array<int, array{email: string, name?: string, phone?: string, purposes: array<string>, captured_at: string, source_form?: string, country?: string}>  $records
     * @return array{success: int, failure: int, errors: array<string>, refs: array<string>}
     */
    public function push(array $records, CrmCredential $credential): array;

    /** Optional health-check / connection test. Throw on failure. */
    public function probe(CrmCredential $credential): array;
}
