<?php

namespace App\Services\TenantDb;

use App\Models\DatabasePool;
use App\Models\Organization;
use App\Models\TenantChangeRequest;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Provisions a dedicated database for a tenant inside a registered
 * DatabasePool. This is Tier 2 in the BYODB design — Privasimu hosts
 * the cluster, allocates per-tenant database + user there, app connects
 * via 127.0.0.1 / internal VPC.
 *
 * Provisioning is two-phase:
 *   1. Connect to the pool's admin database (`postgres` for pgsql,
 *      `mysql` for MySQL) AS the provisioner user. Run CREATE DATABASE
 *      + CREATE USER + GRANT.
 *   2. Schema migration is delegated to ProvisionTenantDatabaseJob
 *      (which calls `php artisan tenants:migrate` against the new DB).
 *
 * This service performs only the SQL DDL — it does NOT update org state
 * or handle queue lifecycle; that's the job's responsibility.
 *
 * SUPPORTS: Postgres + MySQL. Engine is read from the pool. The DDL
 * SQL is engine-specific so we keep two helper methods.
 */
class PrivasimuHostedProvisioner
{
    public function __construct(private DatabasePoolRegistry $registry) {}

    /**
     * Result returned to the caller. Contains the credentials the runtime
     * connection routing will use; password is plaintext here so the
     * caller can encrypt + persist it on `organizations.tenant_db_config`.
     */
    public function provision(Organization $org, DatabasePool $pool): array
    {
        if ($pool->status !== DatabasePool::STATUS_ACTIVE) {
            throw new \RuntimeException("Pool '{$pool->name}' is not active (status={$pool->status})");
        }
        if (!$pool->isAcceptingTenants()) {
            throw new \RuntimeException("Pool '{$pool->name}' is at capacity ({$pool->current_tenants_count}/{$pool->max_tenants})");
        }

        [$dbName, $username] = $this->namesFor($org);
        $password = $this->generatePassword();

        $admin = $this->registry->connectToPool($pool);

        match ($pool->engine) {
            DatabasePool::ENGINE_PGSQL => $this->createPostgresDatabase($admin, $dbName, $username, $password),
            DatabasePool::ENGINE_MYSQL => $this->createMysqlDatabase($admin, $dbName, $username, $password),
            default => throw new \RuntimeException("Unsupported engine '{$pool->engine}'"),
        };

        return [
            'engine'   => $pool->engine,
            'host'     => $pool->host,
            'port'     => $pool->port,
            'database' => $dbName,
            'username' => $username,
            'password' => $password,
            'sslmode'  => $pool->sslmode ?? 'require',
            'managed_by' => 'privasimu',
            'pool_id'  => $pool->id,
            'pool_name' => $pool->name,
        ];
    }

    /**
     * Drop the tenant database + user. Caller must have already cut over
     * traffic away from this DB (tenant_db_state != 'isolated') because
     * DROP DATABASE will fail if there are open connections to it.
     *
     * Idempotent: missing database/user is silently treated as success.
     */
    public function deprovision(Organization $org, DatabasePool $pool): void
    {
        [$dbName, $username] = $this->namesFor($org);

        $admin = $this->registry->connectToPool($pool);

        match ($pool->engine) {
            DatabasePool::ENGINE_PGSQL => $this->dropPostgresDatabase($admin, $dbName, $username),
            DatabasePool::ENGINE_MYSQL => $this->dropMysqlDatabase($admin, $dbName, $username),
            default => throw new \RuntimeException("Unsupported engine '{$pool->engine}'"),
        };
    }

    /**
     * Derive (database name, role/user name) from an org id. Postgres
     * identifiers cap at 63 chars; the org UUID is 36 chars + prefix
     * 'privasimu_tenant_' (17 chars) = 53 chars after replacing dashes
     * with underscores, so we're under the limit. Lowercase + underscore
     * only — quoted in DDL just in case.
     */
    public function namesFor(Organization $org): array
    {
        $sanitized = strtolower(preg_replace('/[^a-z0-9]/i', '_', (string) $org->id));
        $dbName = 'privasimu_tenant_' . $sanitized;
        $username = 'tenant_' . substr($sanitized, 0, 50);
        return [$dbName, $username];
    }

    private function generatePassword(): string
    {
        return bin2hex(random_bytes(32));   // 64 hex chars
    }

    // ─── Postgres ──────────────────────────────────────────────────────────

    private function createPostgresDatabase(Connection $admin, string $dbName, string $username, string $password): void
    {
        // CREATE USER first — needs to exist before we GRANT on the new DB.
        // Quote password literal carefully (DBA-controlled value but still).
        $admin->statement(
            'CREATE USER "' . $this->ident($username) . '" WITH PASSWORD ' . $admin->getPdo()->quote($password)
            . ' NOSUPERUSER NOCREATEDB NOCREATEROLE LOGIN'
        );

        $admin->statement(
            'CREATE DATABASE "' . $this->ident($dbName) . '" '
            . 'WITH OWNER = "' . $this->ident($username) . '" '
            . "ENCODING = 'UTF8' "
            . 'TEMPLATE = template0'
        );

        // OWNER grant from CREATE DATABASE is already enough on Postgres,
        // but we add the explicit privilege grant to be defensive across
        // managed services that strip default privileges (RDS).
        $admin->statement(
            'GRANT ALL PRIVILEGES ON DATABASE "' . $this->ident($dbName) . '" TO "' . $this->ident($username) . '"'
        );
    }

    private function dropPostgresDatabase(Connection $admin, string $dbName, string $username): void
    {
        // Terminate any lingering connections so DROP doesn't fail
        try {
            $admin->statement(
                "SELECT pg_terminate_backend(pid) FROM pg_stat_activity "
                . "WHERE datname = " . $admin->getPdo()->quote($dbName)
                . " AND pid <> pg_backend_pid()"
            );
        } catch (\Throwable $e) { /* best-effort */ }

        $admin->statement('DROP DATABASE IF EXISTS "' . $this->ident($dbName) . '"');
        $admin->statement('DROP USER IF EXISTS "' . $this->ident($username) . '"');
    }

    // ─── MySQL ──────────────────────────────────────────────────────────────

    private function createMysqlDatabase(Connection $admin, string $dbName, string $username, string $password): void
    {
        $admin->statement(
            'CREATE DATABASE `' . $this->ident($dbName) . '` '
            . 'CHARACTER SET utf8mb4 '
            . 'COLLATE utf8mb4_unicode_ci'
        );

        // MySQL CREATE USER + GRANT
        $admin->statement(
            "CREATE USER '" . $this->ident($username) . "'@'%' IDENTIFIED BY " . $admin->getPdo()->quote($password)
        );

        $admin->statement(
            'GRANT ALL PRIVILEGES ON `' . $this->ident($dbName) . "`.* TO '" . $this->ident($username) . "'@'%'"
        );

        $admin->statement('FLUSH PRIVILEGES');
    }

    private function dropMysqlDatabase(Connection $admin, string $dbName, string $username): void
    {
        $admin->statement('DROP DATABASE IF EXISTS `' . $this->ident($dbName) . '`');
        try {
            $admin->statement("DROP USER IF EXISTS '" . $this->ident($username) . "'@'%'");
        } catch (\Throwable $e) { /* best-effort */ }
        $admin->statement('FLUSH PRIVILEGES');
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Strip everything except [a-zA-Z0-9_] from an identifier. Caller still
     * wraps in quotes/backticks at the call site — this is a defense-in-depth
     * against a malformed name reaching the SQL.
     */
    private function ident(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
    }
}
