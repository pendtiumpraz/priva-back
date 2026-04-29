<?php

namespace App\Services\TenantDb;

use App\Models\DatabasePool;
use App\Models\Organization;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Manages the registry of Postgres/MySQL clusters Privasimu can provision
 * tenant databases into. Used by the provisioning job to:
 *
 *   1. Pick an active pool (auto, or a specific one chosen by superadmin)
 *   2. Open a connection AS THE PROVISIONER user (the one with CREATE
 *      DATABASE privilege) so the job can run CREATE/DROP statements
 *   3. Track headcount on the pool (current_tenants_count) so the UI can
 *      show capacity and the resolver can avoid full pools
 *
 * NOTE: pool credentials (provisioner_password, ca_cert) are decrypted by
 * the DatabasePool model via Eloquent accessors, so callers here see
 * plaintext when they read those fields.
 */
class DatabasePoolRegistry
{
    /**
     * Return an active pool that's accepting new tenants, optionally
     * filtered by region. Lower current_tenants_count wins so we spread
     * load. Returns null if none available — caller decides whether to
     * fail the request or fall back to shared.
     */
    public function findActivePool(?string $region = null): ?DatabasePool
    {
        $query = DatabasePool::query()
            ->where('status', DatabasePool::STATUS_ACTIVE)
            ->whereNull('deleted_at');

        if ($region) {
            $query->where('region', $region);
        }

        return $query
            ->orderBy('current_tenants_count')
            ->get()
            ->first(fn ($pool) => $pool->isAcceptingTenants());
    }

    /**
     * Mark a tenant as belonging to a pool: persist FK + increment counter.
     * Idempotent — calling twice on the same (org, pool) won't double-count.
     */
    public function assignTenantToPool(Organization $org, DatabasePool $pool): void
    {
        DB::transaction(function () use ($org, $pool) {
            if ((string) $org->db_pool_id === (string) $pool->id) {
                return;  // already assigned
            }

            // Decrement old pool if any
            if ($org->db_pool_id) {
                DatabasePool::where('id', $org->db_pool_id)
                    ->where('current_tenants_count', '>', 0)
                    ->decrement('current_tenants_count');
            }

            $org->db_pool_id = $pool->id;
            $org->save();

            DatabasePool::where('id', $pool->id)->increment('current_tenants_count');
        });
    }

    /**
     * Detach tenant from pool (e.g. tenant moved to BYODB or deprovisioned).
     * Only decrements the counter — does NOT drop the actual database.
     * Drop is the provisioner's job.
     */
    public function removeTenantFromPool(Organization $org): void
    {
        if (!$org->db_pool_id) return;

        DB::transaction(function () use ($org) {
            DatabasePool::where('id', $org->db_pool_id)
                ->where('current_tenants_count', '>', 0)
                ->decrement('current_tenants_count');

            $org->db_pool_id = null;
            $org->save();
        });
    }

    /**
     * Open a connection to a pool's cluster AS THE PROVISIONER user.
     * Used by the provisioning job to run CREATE DATABASE / CREATE USER.
     *
     * The connection is registered under a per-pool name and reused
     * within the same request. Connecting to `postgres` (or `mysql`) the
     * default admin DB so we can create new ones from there.
     */
    public function connectToPool(DatabasePool $pool): Connection
    {
        $connectionName = "pool_{$pool->id}_admin";

        if (!Config::has("database.connections.{$connectionName}")) {
            Config::set("database.connections.{$connectionName}", [
                'driver'   => $pool->engine,
                'host'     => $pool->host,
                'port'     => $pool->port,
                'database' => $pool->engine === DatabasePool::ENGINE_PGSQL ? 'postgres' : 'mysql',
                'username' => $pool->provisioner_user,
                'password' => $pool->provisioner_password,  // decrypted by accessor
                'charset'  => $pool->engine === DatabasePool::ENGINE_PGSQL ? 'utf8' : 'utf8mb4',
                'sslmode'  => $pool->sslmode ?? 'require',
                'options'  => $this->buildSslOptions($pool),
            ]);
        }

        // Force a fresh resolver in case Config was changed mid-request
        DB::purge($connectionName);

        return DB::connection($connectionName);
    }

    /**
     * If the pool has a CA cert configured, write it to a temp file and
     * point the PDO driver at it. Caller is responsible for cleanup of
     * temp files (kept in tmp until process exits).
     */
    private function buildSslOptions(DatabasePool $pool): array
    {
        $cert = $pool->ca_cert;  // decrypted by accessor
        if (!$cert) return [];

        $tmpPath = sys_get_temp_dir() . '/privasimu_pool_ca_' . $pool->id . '.pem';
        if (!file_exists($tmpPath)) {
            file_put_contents($tmpPath, $cert);
            chmod($tmpPath, 0600);
        }

        return [
            \PDO::MYSQL_ATTR_SSL_CA => $tmpPath,
        ];
    }
}
