<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class TestEncryption extends Command
{
    protected $signature = 'pii:test-encryption';
    protected $description = 'Test PII encryption/decryption roundtrip to ensure A → encrypt → decrypt → A';

    public function handle()
    {
        $this->info('🔐 Testing PII Encryption Roundtrip');
        $this->info('   Cipher: ' . config('app.cipher'));
        $this->info('   Key length: ' . strlen(config('app.key')) . ' chars');
        $this->newLine();

        $testCases = [
            'Simple ASCII' => 'Budi Santoso',
            'Email' => 'budi.santoso@perusahaan.com',
            'Phone' => '+6281234567890',
            'Indonesian chars' => 'Jl. Merdeka №12, Jakarta Selatan',
            'Unicode/Emoji' => 'Data Spesifik 🔒 Anak-anak',
            'Long text (SOP)' => str_repeat('Kebijakan privasi perusahaan ini mengatur pemrosesan data pribadi sesuai UU PDP. ', 20),
            'Empty string' => '',
            'Numbers only' => '1234567890',
            'Special chars' => "O'Brien & Co. <script>alert('xss')</script>",
            'Multiline' => "Baris 1\nBaris 2\nBaris 3",
            'JSON-like' => '{"nama":"Budi","email":"b@test.com"}',
        ];

        $passed = 0;
        $failed = 0;

        foreach ($testCases as $label => $original) {
            $encrypted = '';
            $decrypted = '';
            $success = false;

            try {
                if ($original === '') {
                    // Empty string should pass through as-is
                    $this->line("  ✅ [{$label}] (empty string) — PASS (no encryption needed)");
                    $passed++;
                    continue;
                }

                $encrypted = Crypt::encryptString($original);
                $decrypted = Crypt::decryptString($encrypted);

                if ($original === $decrypted) {
                    $encLen = strlen($encrypted);
                    $this->line("  ✅ [{$label}] — PASS");
                    $this->line("     Original:  " . mb_substr($original, 0, 50) . (mb_strlen($original) > 50 ? '...' : ''));
                    $this->line("     Encrypted: " . substr($encrypted, 0, 40) . "... ({$encLen} chars)");
                    $this->line("     Decrypted: " . mb_substr($decrypted, 0, 50) . (mb_strlen($decrypted) > 50 ? '...' : ''));
                    $success = true;
                    $passed++;
                } else {
                    $this->error("  ❌ [{$label}] — FAIL: Output mismatch!");
                    $this->error("     Original:  {$original}");
                    $this->error("     Decrypted: {$decrypted}");
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->error("  ❌ [{$label}] — EXCEPTION: " . $e->getMessage());
                $failed++;
            }
        }

        $this->newLine();

        // Test the EncryptedString Cast class directly
        $this->info('🧪 Testing EncryptedString Cast Class...');
        $cast = new \App\Casts\EncryptedString();
        
        // Test: encrypt → decrypt roundtrip via cast
        $testValue = 'budi@perusahaan.co.id';
        $encrypted = $cast->set(null, 'email', $testValue, []);
        $decrypted = $cast->get(null, 'email', $encrypted, []);
        
        if ($testValue === $decrypted) {
            $this->line("  ✅ [Cast roundtrip] — PASS ({$testValue})");
            $passed++;
        } else {
            $this->error("  ❌ [Cast roundtrip] — FAIL: {$testValue} ≠ {$decrypted}");
            $failed++;
        }

        // Test: plaintext fallback (data lama sebelum enkripsi)
        $plaintext = 'old_plaintext@email.com';
        $fallback = $cast->get(null, 'email', $plaintext, []);
        
        if ($plaintext === $fallback) {
            $this->line("  ✅ [Plaintext fallback] — PASS (old data readable: {$fallback})");
            $passed++;
        } else {
            $this->error("  ❌ [Plaintext fallback] — FAIL");
            $failed++;
        }

        // Test: null/empty handling
        $nullResult = $cast->get(null, 'email', null, []);
        $emptyResult = $cast->get(null, 'email', '', []);
        $nullSet = $cast->set(null, 'email', null, []);
        $emptySet = $cast->set(null, 'email', '', []);
        
        if (is_null($nullResult) && $emptyResult === '' && is_null($nullSet) && $emptySet === '') {
            $this->line("  ✅ [Null/Empty handling] — PASS");
            $passed++;
        } else {
            $this->error("  ❌ [Null/Empty handling] — FAIL");
            $failed++;
        }

        $this->newLine();
        $this->info("═══════════════════════════════════════");
        $this->info("  Results: {$passed} passed, {$failed} failed");
        $this->info("═══════════════════════════════════════");

        if ($failed > 0) {
            $this->error('⚠️  ENCRYPTION TEST FAILED! Do NOT deploy until fixed.');
            return 1;
        }

        $this->info('✅ All encryption tests passed! Safe to deploy.');
        return 0;
    }
}
