<?php

namespace App\Services\TenantDb;

use App\Models\Organization;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the right Laravel DB connection name for a given tenant at
 * request time. Mirror of TenantStorageService for the database side.
 *
 * Routing logic — driven by `organizations.tenant_db_provider` and
 * `tenant_db_state`:
 *
 *   tenant_db_provider = 'shared'                  → use 'landlord' connection
 *   tenant_db_state    != 'isolated'               → use 'landlord' (still
 *                                                     mid-migration; writes
 *                                                     stay on shared until
 *                                                     cutover finishes)
 *   tenant_db_provider in ('pool', 'byodb')
 *   AND tenant_db_state = 'isolated'               → build a per-tenant
 *                                                     connection from the
 *                                                     encrypted tenant_db_config
 *                                                     and return its name
 *
 * Per-request cache: connection configs only get registered once per
 * request via the `$cached` static array. php-fpm rebuilds the container
 * each request so this is naturally bounded; for Octane / queue workers,
 * call clearCache() between requests/jobs.
 */
class TenantDatabaseService
{
    private static array $cached = [];

    /**
     * Return the connection name to use for queries belonging to this org.
     * Caller should pass the result to DB::setDefaultConnection().
     */
    public function getConnection(Organization $org): string
    {
        // Tier 1: shared / not yet isolated → use landlord default
        if ($org->tenant_db_provider === 'shared'
            || $org->tenant_db_state !== 'isolated'
            || empty($org->tenant_db_config)) {
            return $this->landlordConnectionName();
        }

        $name = "tenant_{$org->id}";

        if (!isset(self::$cached[$name])) {
            $config = $this->decryptConfig($org->tenant_db_config);
            if ($config === null) {
                \Log::warning("TenantDatabase: failed to decrypt config for org {$org->id}; falling back to landlord");
                return $this->landlordConnectionName();
            }

            $engine = $config['engine'] ?? 'pgsql';
            Config::set("database.connections.{$name}", $this->buildConnection($engine, $config));
            DB::purge($name);  // ensure fresh resolver picks up new config
            self::$cached[$name] = true;
        }

        return $name;
    }

    /**
     * Build a Laravel connection config array from a decrypted config
     * payload. Used for both runtime routing AND probe (testConnectionWithConfig).
     */
    public function buildConnection(string $engine, array $config): array
    {
        $base = [
            'driver'   => $engine,
            'host'     => $config['host'] ?? '127.0.0.1',
            'port'     => $config['port'] ?? ($engine === 'pgsql' ? 5432 : 3306),
            'database' => $config['database'] ?? '',
            'username' => $config['username'] ?? '',
            'password' => $config['password'] ?? '',
            'charset'  => $config['charset'] ?? ($engine === 'pgsql' ? 'utf8' : 'utf8mb4'),
            'prefix'   => '',
            'prefix_indexes' => true,
        ];

        if ($engine === 'pgsql') {
            $base['schema']  = $config['schema'] ?? 'public';
            $base['sslmode'] = $config['sslmode'] ?? 'prefer';
        } else {
            $base['collation'] = $config['collation'] ?? 'utf8mb4_unicode_ci';
            $base['strict']    = true;
            $base['engine']    = $config['mysql_engine'] ?? null;
        }

        return $base;
    }

    public function decryptConfig(string $encrypted): ?array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true);
        } catch (\Throwable $e) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    public function encryptConfig(array $config): string
    {
        return Crypt::encryptString(json_encode($config));
    }

    /**
     * Probe a connection config without persisting it. Used by the
     * "Test Connection" button in the BYODB form and by the provisioner
     * to verify a freshly-created DB before cutover.
     *
     * Writes to a transient probe table, reads it back, then drops it.
     * Returns ['success' => bool, 'message' => string].
     */
    public function testConnectionWithConfig(string $engine, array $config): array
    {
        $probeName = 'probe_' . bin2hex(random_bytes(4));

        try {
            Config::set("database.connections.{$probeName}", $this->buildConnection($engine, $config));
            DB::purge($probeName);
            $conn = DB::connection($probeName);

            // Cheap probe: SELECT 1
            $result = $conn->select('SELECT 1 AS ok');
            if (empty($result) || ($result[0]->ok ?? null) != 1) {
                return ['success' => false, 'message' => 'Connection opened but SELECT 1 returned unexpected result.'];
            }

            // Slightly deeper: write+read+drop a probe table to verify CREATE/INSERT/DROP rights
            $probeTable = '_privasimu_probe_' . bin2hex(random_bytes(3));
            $conn->statement("CREATE TABLE {$probeTable} (k VARCHAR(64))");
            $conn->statement("INSERT INTO {$probeTable} (k) VALUES ('ok')");
            $row = $conn->select("SELECT k FROM {$probeTable} WHERE k = 'ok'");
            $conn->statement("DROP TABLE {$probeTable}");

            if (empty($row)) {
                return ['success' => false, 'message' => 'Probe table write succeeded but read returned no rows.'];
            }

            return ['success' => true, 'message' => "Berhasil terhubung ke {$engine} di {$config['host']}.", 'engine' => $engine];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Gagal terhubung: ' . $e->getMessage()];
        } finally {
            DB::purge($probeName);
        }
    }

    /**
     * Reset the per-request cache. Call this between jobs in Octane /
     * queue workers; not needed in php-fpm.
     */
    public function clearCache(): void
    {
        foreach (array_keys(self::$cached) as $name) {
            DB::purge($name);
        }
        self::$cached = [];
    }

    /**
     * The connection name for the landlord (shared) database. Currently
     * the same as Laravel's default connection. Centralized here so we
     * can rename later without touching every caller.
     */
    public function landlordConnectionName(): string
    {
        return config('database.default');
    }
}
