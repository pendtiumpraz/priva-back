<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DpiaRiskEventTemplate;
use Illuminate\Http\Request;

/**
 * Read-only library of DPIA risk event templates — DPO picks relevant risks
 * from here and attaches them (with manual Dampak / Probabilitas / Kontrol /
 * Penanganan scoring) to a category within their DPIA wizard.
 *
 * Seeded via DpiaRiskEventTemplateSeeder (~110 entries across 22 buckets).
 */
class DpiaRiskEventTemplateController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        $query = DpiaRiskEventTemplate::where('is_active', true)
            ->where(function ($q) use ($orgId) {
                $q->where('is_system', true)->whereNull('org_id')
                  ->orWhere('org_id', $orgId);
            })
            ->orderBy('category_key')
            ->orderBy('sequence');

        if ($cat = $request->query('category')) {
            $query->where('category_key', $cat);
        }

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('risk_event', 'ilike', "%{$search}%")
                  ->orWhere('category_label', 'ilike', "%{$search}%");
            });
        }

        $rows = $query->limit(500)->get();

        // Group by category for easier frontend consumption.
        $grouped = $rows->groupBy('category_key')->map(function ($items) {
            $first = $items->first();
            return [
                'key' => $first->category_key,
                'label' => $first->category_label,
                'risks' => $items->map(fn ($t) => [
                    'id' => $t->id,
                    'risk_event' => $t->risk_event,
                    'sequence' => $t->sequence,
                    'default_description' => $t->default_description,
                ])->values(),
            ];
        })->values();

        return response()->json([
            'data' => $grouped,
            'total_risks' => $rows->count(),
        ]);
    }
}
