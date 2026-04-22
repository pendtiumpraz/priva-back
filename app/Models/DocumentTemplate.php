<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentTemplate extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'name', 'description', 'preview_image',
        'config', 'docx_templates', 'is_default', 'is_system', 'usage_count', 'created_by',
    ];

    protected $casts = [
        'config' => 'array',
        'docx_templates' => 'array',
        'is_default' => 'boolean',
        'is_system' => 'boolean',
        'usage_count' => 'integer',
    ];

    /**
     * Default (fallback) config shape — used when tenant has no active
     * template selected. Keeps blade templates from blowing up on null.
     */
    public const DEFAULT_CONFIG = [
        // Page / global
        'primary_color' => '#1e293b',
        'accent_color' => '#3b82f6',
        'font_family' => 'DejaVu Sans',
        'font_size_body' => 11,

        // Header
        'header_enabled' => true,
        'header_show_logo' => true,
        'header_show_org_name' => true,
        'header_text' => null,
        'header_bg' => null,
        'header_border_bottom' => true,

        // Footer
        'footer_enabled' => true,
        'footer_text' => null,
        'footer_show_page_num' => true,
        'footer_show_website' => true,

        // Watermark
        'watermark_enabled' => false,
        'watermark_text' => null,
        'watermark_image' => null,
        'watermark_opacity' => 0.08,
        'watermark_rotate' => -25,

        // Cover page (optional, mainly for full-report)
        'cover_enabled' => false,
        'cover_bg_color' => '#0f172a',
        'cover_bg_image' => null,
        'cover_title_color' => '#ffffff',

        // Table style — applied via CSS class swap
        'table_style' => 'clean',     // clean | striped | bordered | rounded | minimal | modern

        // Signature block
        'signature_block_enabled' => true,
        'signature_block_format' => 'dpo_single', // dpo_single | dpo_plus_director | custom

        // Page size default (can still be overridden per download)
        'page_size' => 'a4',
        'page_margin_top' => 100,
        'page_margin_bottom' => 80,
        'page_margin_left' => 50,
        'page_margin_right' => 50,
    ];

    /**
     * Merge default config + template config so every field has a value
     * even if a tenant template was partially defined.
     */
    public function mergedConfig(): array
    {
        return array_merge(self::DEFAULT_CONFIG, $this->config ?? []);
    }

    /**
     * Find active template for an org. Falls back to system default.
     * Returns a DocumentTemplate (never null) when seeder has run.
     */
    public static function activeForOrg(?string $orgId): ?self
    {
        if ($orgId) {
            $theme = \App\Models\TenantTheme::where('org_id', $orgId)
                ->whereNotNull('active_document_template_id')
                ->first();
            if ($theme && $theme->active_document_template_id) {
                $t = self::where('id', $theme->active_document_template_id)
                    ->where(function ($q) use ($orgId) {
                        $q->where('org_id', $orgId)->orWhereNull('org_id');
                    })
                    ->first();
                if ($t) return $t;
            }
        }
        return self::whereNull('org_id')->where('is_default', true)->first();
    }
}
