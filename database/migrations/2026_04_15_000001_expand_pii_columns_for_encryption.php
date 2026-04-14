<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand PII columns from varchar(255) to TEXT to accommodate AES-256-CBC ciphertext.
 * Handles MySQL index constraints: drops indexes on columns before converting to TEXT.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // Helper: For MySQL, drop any index that includes the given column before altering to TEXT
        $dropIndexes = function (string $table, string $column) use ($driver) {
            if ($driver !== 'mysql') return;

            $dbName = DB::getDatabaseName();
            $indexes = DB::select("
                SELECT DISTINCT INDEX_NAME 
                FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
                AND INDEX_NAME != 'PRIMARY'
            ", [$dbName, $table, $column]);

            foreach ($indexes as $idx) {
                try {
                    DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$idx->INDEX_NAME}`");
                } catch (\Throwable $e) {
                    // Index might already be dropped, ignore
                }
            }
        };

        // Helper: safely alter column to TEXT
        $toText = function (string $table, string $column, bool $nullable = true) use ($driver, $dropIndexes) {
            if (!Schema::hasColumn($table, $column)) return;
            
            $dropIndexes($table, $column);

            $null = $nullable ? 'NULL' : 'NOT NULL';
            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` TEXT {$null}");
            } else {
                // PostgreSQL
                DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE TEXT");
            }
        };

        // ──── Users ────
        $toText('users', 'name', false);
        $toText('users', 'phone');

        // ──── Organizations ────
        $toText('organizations', 'phone');
        $toText('organizations', 'address');

        // ──── DSR Requests ────
        $toText('dsr_requests', 'requester_name');
        $toText('dsr_requests', 'requester_email');
        $toText('dsr_requests', 'requester_phone');

        // ──── Consent Records ────
        $toText('consent_records', 'subject_identifier');
        $toText('consent_records', 'subject_name');
        $toText('consent_records', 'ip_address');

        // ──── Breach Incidents ────
        $toText('breach_incidents', 'pic_name');

        // ──── Vendors ────
        $toText('vendors', 'contact_name');
        $toText('vendors', 'contact_email');

        // ──── Organization Apps (credentials) ────
        $toText('organization_apps', 'staging_db_username');
        $toText('organization_apps', 'staging_db_password');
        $toText('organization_apps', 'prod_db_username');
        $toText('organization_apps', 'prod_db_password');

        // ──── Tenant SSO ────
        $toText('tenant_ssos', 'client_secret');

        // ──── Webhooks ────
        $toText('webhooks', 'secret');

        // ──── License Activations ────
        $toText('license_activations', 'ip_address');
    }

    public function down(): void
    {
        // Cannot safely revert — ciphertext may exceed varchar(255)
    }
};
