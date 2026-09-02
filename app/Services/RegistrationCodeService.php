<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Single source of truth for auto-generated registration codes
 * (ROPA-YYYY-NNN, DPIA-YYYY-NNN, DSR-YYYY-NNN, BRC-YYYY-NNN, CNT-YYYY-NNN).
 *
 * The unique constraint on these code columns is GLOBAL (not per-org), so the
 * running counter must be derived globally too. Previously three call paths
 * each inlined their own generator and they drifted:
 *   - ModuleCrudController::nextCode (legacy fallback) — ambient org scope
 *   - RopaCsvImportController::nextCode — global
 *   - DsrIntakeService::nextRequestId — filtered ->where('org_id', $orgId)
 *
 * That per-org filter in DsrIntakeService was the F-03 bug: two orgs both
 * computed DSR-YYYY-001 and the collision-retry regenerated the SAME per-org
 * value, so it could never converge. Everything now routes through nextGlobal()
 * so the counter is unambiguously global and codes are unique on the first try.
 */
class RegistrationCodeService
{
    /** Column that stores the code for each prefix. */
    private const CODE_COLUMN = [
        'ROPA' => 'registration_number',
        'DPIA' => 'registration_number',
        'DSR' => 'request_id',
        'CNT' => 'collection_id',
        'BRC' => 'incident_code',
    ];

    /**
     * Next `PREFIX-YYYY-NNN` code, counted GLOBALLY across all orgs so the value
     * satisfies the global-unique constraint regardless of current org context.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function nextGlobal(string $prefix, string $modelClass, ?string $codeColumn = null): string
    {
        $codeColumn ??= self::CODE_COLUMN[$prefix] ?? 'registration_number';
        $year = date('Y');
        $pattern = $prefix.'-'.$year.'-%';

        // Count across every tenant — the BelongsToOrg scope would otherwise
        // silently narrow this to the current org and reintroduce F-03.
        // withoutGlobalScope('org') is a harmless no-op on landlord models.
        $query = $modelClass::query()->withoutGlobalScope('org')->withTrashed();

        $max = 0;
        foreach ($query->where($codeColumn, 'like', $pattern)->pluck($codeColumn) as $code) {
            $num = (int) substr((string) $code, strrpos((string) $code, '-') + 1);
            $max = max($max, $num);
        }

        return $prefix.'-'.$year.'-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Create a record whose code column is globally unique, retrying on a
     * duplicate-key collision by recomputing the code. $regen is called again
     * for each retry. Re-throws a non-duplicate exception or after 3 attempts.
     *
     * @param  Model  $model  a fresh model instance (or query target)
     * @param  array<string, mixed>  $data
     */
    public function createWithRetry(Model $model, array $data, string $codeField, callable $regen)
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $model->newQuery()->create($data);
            } catch (QueryException $qe) {
                $isDup = $qe->getCode() === '23000'
                    || str_contains($qe->getMessage(), 'Duplicate entry')
                    || str_contains(strtolower($qe->getMessage()), 'unique');
                if ($isDup && $attempt < 2) {
                    $data[$codeField] = $regen();

                    continue;
                }
                throw $qe;
            }
        }

        throw new \RuntimeException("Gagal menghasilkan {$codeField} unik setelah 3 percobaan.");
    }
}
