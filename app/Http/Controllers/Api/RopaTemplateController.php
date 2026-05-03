<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RopaTemplate;
use Illuminate\Http\Request;

/**
 * RoPA template library — read-only for end users. Seeded with common
 * industry activities; DPO picks a template to prefill the New RoPA wizard
 * instead of starting blank.
 */
class RopaTemplateController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        $query = RopaTemplate::where('is_active', true)
            ->where(function ($q) use ($orgId) {
                $q->where('is_system', true)->whereNull('org_id')
                    ->orWhere('org_id', $orgId);
            })
            ->orderBy('industry')
            ->orderBy('name');

        if ($ind = $request->query('industry')) {
            $query->where('industry', $ind);
        }
        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%")
                    ->orWhere('industry', 'ilike', "%{$search}%");
            });
        }

        $rows = $query->limit(200)->get();

        // Group by industry for easier client render.
        $grouped = $rows->groupBy('industry')->map(function ($items, $ind) {
            return [
                'industry' => $ind,
                'label' => self::industryLabel($ind),
                'templates' => $items->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'activity_code' => $t->activity_code,
                    'description' => $t->description,
                    'usage_count' => $t->usage_count,
                ])->values(),
            ];
        })->values();

        return response()->json(['data' => $grouped, 'total' => $rows->count()]);
    }

    public function show(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $tpl = RopaTemplate::where('is_active', true)
            ->where(function ($q) use ($orgId) {
                $q->where('is_system', true)->whereNull('org_id')
                    ->orWhere('org_id', $orgId);
            })
            ->findOrFail($id);

        // Bump usage counter (non-blocking, best-effort)
        try {
            $tpl->increment('usage_count');
        } catch (\Throwable $e) {
        }

        return response()->json([
            'data' => [
                'id' => $tpl->id,
                'name' => $tpl->name,
                'industry' => $tpl->industry,
                'activity_code' => $tpl->activity_code,
                'description' => $tpl->description,
                'wizard_data' => $tpl->wizard_data,
            ],
        ]);
    }

    private static function industryLabel(string $ind): string
    {
        return match ($ind) {
            'banking' => '🏦 Banking / Perbankan',
            'healthcare' => '🏥 Healthcare / Kesehatan',
            'insurance' => '📋 Insurance / Asuransi',
            'fintech' => '💳 Fintech / P2P Lending',
            'retail' => '🛒 Retail / E-commerce',
            'government' => '🏛️ Government / Public Sector',
            'telco' => '📡 Telecommunications',
            'general' => '🏢 General / HR & Operations',
            default => ucfirst($ind),
        };
    }
}
