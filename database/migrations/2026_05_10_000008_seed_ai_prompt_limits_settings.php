<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed AI prompt size limits ke section "security" di system_settings.
 *
 * Tujuan: cegah abuse + hemat biaya. AI provider charge per token; tanpa
 * limit, attacker yang udah login bisa kirim prompt 100K char untuk drain
 * credit tenant atau bikin tagihan provider membengkak. Credit gating ada,
 * tapi credit gating itu post-hoc — kita REJECT prompt besar SEBELUM
 * dipanggil ke provider.
 *
 * Default value-nya konservatif tapi bukan agresif:
 *   - max_prompt_chars 24000 ≈ 6000 token (system + user combined)
 *   - max_message_chars 4000 (single user message di chat — match validator existing di AiAgentController)
 *   - max_attachment_chars 12000 (parsed file text — match hardcoded existing)
 *
 * Editable via /platform-admin/system-settings → Security → AI Limits.
 * Di UI ada hint "≈ X token" supaya admin gampang reasoning soal cost.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'security.ai_max_prompt_chars' => 24000,
        'security.ai_max_message_chars' => 4000,
        'security.ai_max_attachment_chars' => 12000,
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::DEFAULTS as $key => $value) {
            $exists = DB::table('system_settings')->where('key', $key)->exists();
            if ($exists) continue;

            DB::table('system_settings')->insert([
                'key' => $key,
                'value' => json_encode($value),
                'is_encrypted' => false,
                'section' => 'security',
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', array_keys(self::DEFAULTS))->delete();
    }
};
