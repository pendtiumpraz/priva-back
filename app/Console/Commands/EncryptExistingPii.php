<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EncryptExistingPii extends Command
{
    protected $signature = 'pii:encrypt-existing {--dry-run : Tampilkan preview tanpa mengubah data} {--model= : Encrypt model tertentu saja (misal: User)}';
    protected $description = 'Encrypt existing plaintext PII data in database using AES-256-CBC';

    /**
     * Map: Model class => [columns to encrypt]
     */
    private function getEncryptionMap(): array
    {
        return [
            \App\Models\User::class => ['name', 'phone'],
            \App\Models\Organization::class => ['phone', 'address'],
            \App\Models\DsrRequest::class => ['requester_name', 'requester_email', 'requester_phone', 'description'],
            \App\Models\ConsentRecord::class => ['subject_identifier', 'subject_name', 'ip_address'],
            \App\Models\BreachIncident::class => ['pic_name', 'description'],
            \App\Models\Vendor::class => ['contact_name', 'contact_email'],
            \App\Models\OrganizationApp::class => ['staging_db_username', 'staging_db_password', 'prod_db_username', 'prod_db_password'],
            \App\Models\TenantSso::class => ['client_secret'],
            \App\Models\Webhook::class => ['secret'],
            \App\Models\LicenseActivation::class => ['ip_address'],
        ];
    }

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $onlyModel = $this->option('model');

        // Validate encryption is available
        try {
            $test = Crypt::encryptString('test');
            $back = Crypt::decryptString($test);
            if ($back !== 'test') {
                $this->error('❌ Encryption roundtrip failed! APP_KEY mungkin salah.');
                return 1;
            }
        } catch (\Throwable $e) {
            $this->error('❌ Encryption tidak tersedia: ' . $e->getMessage());
            $this->error('   Pastikan APP_KEY di .env valid (format: base64:xxxxx, 32 bytes)');
            return 1;
        }

        $this->info('🔐 PII Encryption Migration' . ($dryRun ? ' [DRY RUN]' : ''));
        $this->info('   Cipher: ' . config('app.cipher'));
        $this->newLine();

        $map = $this->getEncryptionMap();
        $totalEncrypted = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($map as $modelClass => $columns) {
            $shortName = class_basename($modelClass);

            // Filter by model name if specified
            if ($onlyModel && strtolower($shortName) !== strtolower($onlyModel)) {
                continue;
            }

            $this->info("📋 {$shortName}");

            // Check if model class exists
            if (!class_exists($modelClass)) {
                $this->warn("   ⏩ Model class not found, skipping.");
                continue;
            }

            // Get table name
            $table = (new $modelClass)->getTable();

            // Use raw DB query to avoid cast auto-decrypt/encrypt
            $rows = DB::table($table)->select(array_merge(['id'], $columns))->get();

            if ($rows->isEmpty()) {
                $this->line("   (kosong — 0 rows)");
                continue;
            }

            $encrypted = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($rows as $row) {
                $updates = [];

                foreach ($columns as $col) {
                    $value = $row->$col ?? null;

                    if (is_null($value) || $value === '') {
                        continue;
                    }

                    // Check if already encrypted (starts with eyJ = base64 JSON from Laravel Crypt)
                    if ($this->isAlreadyEncrypted($value)) {
                        $skipped++;
                        continue;
                    }

                    // Encrypt the plaintext value
                    try {
                        $encryptedValue = Crypt::encryptString($value);

                        // Verify roundtrip before writing
                        $decrypted = Crypt::decryptString($encryptedValue);
                        if ($decrypted !== $value) {
                            $this->error("   ❌ Roundtrip FAILED for {$shortName}.{$col} (id: {$row->id})");
                            $this->error("      Original:  " . mb_substr($value, 0, 30));
                            $this->error("      Decrypted: " . mb_substr($decrypted, 0, 30));
                            $errors++;
                            continue;
                        }

                        $updates[$col] = $encryptedValue;
                        $encrypted++;
                    } catch (\Throwable $e) {
                        $this->error("   ❌ Error encrypting {$shortName}.{$col} (id: {$row->id}): {$e->getMessage()}");
                        $errors++;
                    }
                }

                if (!empty($updates) && !$dryRun) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            }

            $this->line("   ✅ {$encrypted} encrypted, ⏩ {$skipped} already encrypted, ❌ {$errors} errors");

            $totalEncrypted += $encrypted;
            $totalSkipped += $skipped;
            $totalErrors += $errors;
        }

        $this->newLine();
        $this->info("═══════════════════════════════════════");
        $this->info("  Total: {$totalEncrypted} encrypted, {$totalSkipped} skipped, {$totalErrors} errors");
        $this->info("═══════════════════════════════════════");

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN — tidak ada data yang diubah. Jalankan tanpa --dry-run untuk encrypt.');
        } elseif ($totalErrors > 0) {
            $this->error("⚠️  Ada {$totalErrors} error. Periksa log di atas.");
            return 1;
        } else {
            $this->info('✅ Semua data PII berhasil dienkripsi!');
        }

        return 0;
    }

    /**
     * Check if a value is already encrypted by Laravel Crypt.
     * Encrypted values are base64 JSON with 'iv', 'value', 'mac' keys.
     */
    private function isAlreadyEncrypted(string $value): bool
    {
        // Laravel encrypted strings start with 'eyJ' (base64 of '{"')
        if (!str_starts_with($value, 'eyJ')) {
            return false;
        }

        // Try to decode and verify structure
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        $json = json_decode($decoded, true);
        return is_array($json) && isset($json['iv']) && isset($json['value']) && isset($json['mac']);
    }
}
