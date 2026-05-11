<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Tamper-evident hash chain untuk audit_logs.
 *
 *   content_hash[i] = sha256(canonical_serialize(row[i]) + prev_hash[i])
 *   prev_hash[i]    = content_hash[i-1]   (atau null untuk row pertama)
 *
 * Properties:
 *   - Insert-only: hapus / edit row apapun akan break chain dari titik itu
 *     ke depan saat di-verify ulang.
 *   - Admin DB tidak bisa silent-edit row tanpa juga update hash —
 *     dan menyusun ulang hash dari titik tertentu butuh akses ke seluruh
 *     row downstream (sulit di hide).
 *
 * Caveat:
 *   - Tidak protect terhadap admin DB yang bisa MENGHAPUS row paling akhir
 *     + adjust prev_hash di row baru — yang ini butuh anchor eksternal
 *     (mis. notarisasi blockchain) untuk full immutability. Untuk MVP,
 *     hash chain sudah cukup deter tamper casual.
 *
 * Default OFF (opt-in via security.audit_log_hash_chain_enabled). Saat
 * pertama kali enable, jalankan `php artisan audit-logs:rebuild-chain`
 * untuk hash row-row existing.
 */
class AuditLogChainService
{
    public function isEnabled(): bool
    {
        return (bool) config('security.audit_log_hash_chain_enabled', false);
    }

    /**
     * Compute hash untuk row tertentu dengan prev_hash yang sudah di-supply.
     * Pakai serialization canonical (sorted keys) supaya hash determinable.
     */
    public function computeHash(AuditLog $log, ?string $prevHash): string
    {
        $payload = [
            'id' => $log->id,
            'module' => $log->module,
            'record_id' => $log->record_id,
            'action' => $log->action,
            'user_id' => $log->user_id,
            'user_name' => $log->user_name,
            'user_role' => $log->user_role,
            'section' => $log->section,
            'field' => $log->field,
            'changes' => $log->changes,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->toIso8601String(),
            'prev_hash' => $prevHash,
        ];
        // ksort untuk canonical — supaya order key tidak mempengaruhi hash
        $this->canonicalize($payload);
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Stamp hash ke row baru SAAT INSERT. Dipanggil dari AuditLog::booted
     * (lifecycle creating).
     *
     * Lookup prev_hash via row terakhir di DB dengan ORDER BY created_at DESC,
     * id DESC (id sebagai tiebreaker untuk insert di timestamp yang sama).
     */
    public function stampOnCreate(AuditLog $log): void
    {
        if (! $this->isEnabled()) return;

        // Cari last row (BEFORE this one) — exclude current id supaya gak
        // self-reference saat re-save.
        $last = AuditLog::query()
            ->where('id', '!=', $log->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $log->prev_hash = $last?->content_hash;
        $log->content_hash = $this->computeHash($log, $log->prev_hash);
    }

    /**
     * Verify chain dari row pertama sampai akhir. Return:
     *   ['valid' => bool, 'broken_at' => ?id, 'total' => int, 'checked' => int, 'reason' => ?string]
     *
     * O(n) — untuk audit log jutaan rows, jalankan offline (mis. via cron).
     */
    public function verify(?int $limit = null): array
    {
        $query = AuditLog::query()->orderBy('created_at')->orderBy('id');
        if ($limit !== null) $query->limit($limit);

        $total = AuditLog::count();
        $prevHash = null;
        $checked = 0;

        foreach ($query->cursor() as $log) {
            $checked++;
            $expected = $this->computeHash($log, $prevHash);
            if ($log->content_hash !== $expected) {
                return [
                    'valid' => false,
                    'broken_at' => $log->id,
                    'broken_at_created' => $log->created_at?->toIso8601String(),
                    'total' => $total,
                    'checked' => $checked,
                    'reason' => 'content_hash mismatch — row mungkin di-tamper atau prev_hash chain rusak',
                ];
            }
            if ($log->prev_hash !== $prevHash) {
                return [
                    'valid' => false,
                    'broken_at' => $log->id,
                    'broken_at_created' => $log->created_at?->toIso8601String(),
                    'total' => $total,
                    'checked' => $checked,
                    'reason' => 'prev_hash tidak match dengan content_hash row sebelumnya — row sebelumnya mungkin di-delete',
                ];
            }
            $prevHash = $log->content_hash;
        }

        return [
            'valid' => true,
            'broken_at' => null,
            'total' => $total,
            'checked' => $checked,
            'reason' => null,
        ];
    }

    /**
     * One-time seed: hash semua row existing sesuai chain order.
     * Idempotent — bisa di-run berulang, hasil sama selama data gak berubah.
     *
     * @return array{updated:int,total:int}
     */
    public function rebuild(): array
    {
        $prevHash = null;
        $updated = 0;

        AuditLog::query()
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor()
            ->each(function (AuditLog $log) use (&$prevHash, &$updated) {
                $newHash = $this->computeHash($log, $prevHash);
                if ($log->content_hash !== $newHash || $log->prev_hash !== $prevHash) {
                    DB::table('audit_logs')->where('id', $log->id)->update([
                        'prev_hash' => $prevHash,
                        'content_hash' => $newHash,
                    ]);
                    $updated++;
                }
                $prevHash = $newHash;
            });

        return [
            'updated' => $updated,
            'total' => AuditLog::count(),
        ];
    }

    /** Recursive ksort untuk canonical JSON. */
    private function canonicalize(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$v) {
            if (is_array($v)) $this->canonicalize($v);
        }
    }
}
