<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantTheme extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'name', 'palette', 'logo_url', 'favicon_url',
        'layout_preset', 'font_family', 'is_active', 'created_by',
        'active_document_template_id', 'active_template_map',
    ];

    protected $casts = [
        'palette' => 'array',
        'active_template_map' => 'array',
        'is_active' => 'boolean',
    ];

    public static function defaultPalette(): array
    {
        return [
            'primary' => '#6366f1',
            'accent' => '#06b6d4',
            'bg' => '#0f172a',
            'card_bg' => '#1e293b',
            'text' => '#f1f5f9',
            'text_muted' => '#94a3b8',
            'border' => '#334155',
            'danger' => '#ef4444',
            'success' => '#22c55e',
        ];
    }
}
