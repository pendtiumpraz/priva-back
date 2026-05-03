<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;

/**
 * Seeds default system_settings rows. Idempotent — uses updateOrCreate so
 * re-running won't clobber values an admin has already saved through the UI.
 *
 * Sensitive defaults (passwords, API keys, AWS secrets) are seeded as null
 * with is_encrypted=true so the admin UI shows them as "not configured" and
 * an admin must enter the real value via the encrypted save path.
 */
class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->defaults() as $row) {
            $value = $row['value'];
            $isEncrypted = $row['is_encrypted'];

            // Encrypt sensitive non-null values at seed time so the at-rest
            // shape matches the runtime save path. Null stays null.
            if ($isEncrypted && $value !== null && $value !== '') {
                $value = Crypt::encryptString((string) $value);
            }

            SystemSetting::updateOrCreate(
                ['key' => $row['key']],
                [
                    'value' => $value,
                    'is_encrypted' => $isEncrypted,
                    'section' => $row['section'],
                ]
            );
        }
    }

    /**
     * @return array<int, array{key: string, value: mixed, is_encrypted: bool, section: string}>
     */
    private function defaults(): array
    {
        return [
            // Deployment
            ['key' => 'deployment.mode', 'value' => 'saas', 'is_encrypted' => false, 'section' => 'deployment'],

            // Infrastructure
            ['key' => 'infrastructure.hosting_tier', 'value' => 'vps', 'is_encrypted' => false, 'section' => 'infrastructure'],
            ['key' => 'infrastructure.queue_driver', 'value' => 'database', 'is_encrypted' => false, 'section' => 'infrastructure'],
            ['key' => 'infrastructure.cache_driver', 'value' => 'file', 'is_encrypted' => false, 'section' => 'infrastructure'],
            ['key' => 'infrastructure.session_driver', 'value' => 'database', 'is_encrypted' => false, 'section' => 'infrastructure'],

            // Infrastructure → SQS (only used when queue_driver=sqs; encrypted creds).
            // S3 / object storage is NOT here — see StoragePool model for that.
            ['key' => 'infrastructure.sqs_access_key', 'value' => null, 'is_encrypted' => true, 'section' => 'infrastructure'],
            ['key' => 'infrastructure.sqs_secret_key', 'value' => null, 'is_encrypted' => true, 'section' => 'infrastructure'],
            ['key' => 'infrastructure.sqs_region', 'value' => 'ap-southeast-1', 'is_encrypted' => false, 'section' => 'infrastructure'],
            ['key' => 'infrastructure.sqs_queue_url', 'value' => null, 'is_encrypted' => false, 'section' => 'infrastructure'],

            // Redis
            ['key' => 'redis.host', 'value' => '127.0.0.1', 'is_encrypted' => false, 'section' => 'redis'],
            ['key' => 'redis.port', 'value' => 6379, 'is_encrypted' => false, 'section' => 'redis'],
            ['key' => 'redis.password', 'value' => null, 'is_encrypted' => true, 'section' => 'redis'],
            ['key' => 'redis.database', 'value' => 0, 'is_encrypted' => false, 'section' => 'redis'],

            // AI — operational toggles only. Provider credentials live in the
            // ai_providers table (see AiProviderSeeder) and are managed via the
            // /settings/ai-providers page, not here.
            ['key' => 'ai.jobs_enabled', 'value' => true, 'is_encrypted' => false, 'section' => 'ai'],
            ['key' => 'ai.local_llm_url', 'value' => null, 'is_encrypted' => false, 'section' => 'ai'],
            ['key' => 'ai.max_concurrent_per_user', 'value' => 5, 'is_encrypted' => false, 'section' => 'ai'],
            ['key' => 'ai.history_retention_days', 'value' => 30, 'is_encrypted' => false, 'section' => 'ai'],

            // OCR fallback to DeepSeek Vision (DeepInfra). Default OFF — opt-in
            // because it costs per-page tokens. When enabled, OcrScannerService
            // routes to deepseek-vision provider whenever tesseract/pdfparser
            // returns less than `visual_ocr_text_threshold` characters.
            // Visual OCR fallback (opt-in). Provider/model resolved via active
            // "document" mode in ai_active_selections — admin pilih DeepSeek-OCR,
            // VL2, atau provider vision lain via standar provider config UI.
            ['key' => 'ai.use_visual_ocr_fallback', 'value' => false, 'is_encrypted' => false, 'section' => 'ai'],
            ['key' => 'ai.visual_ocr_text_threshold', 'value' => 100, 'is_encrypted' => false, 'section' => 'ai'],
            ['key' => 'ai.visual_ocr_max_pages', 'value' => 10, 'is_encrypted' => false, 'section' => 'ai'],

            // Mail
            ['key' => 'mail.smtp_host', 'value' => null, 'is_encrypted' => false, 'section' => 'mail'],
            ['key' => 'mail.smtp_port', 'value' => 587, 'is_encrypted' => false, 'section' => 'mail'],
            ['key' => 'mail.smtp_username', 'value' => null, 'is_encrypted' => false, 'section' => 'mail'],
            ['key' => 'mail.smtp_password', 'value' => null, 'is_encrypted' => true, 'section' => 'mail'],
        ];
    }
}
