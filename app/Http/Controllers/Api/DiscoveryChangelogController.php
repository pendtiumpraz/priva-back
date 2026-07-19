<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiscoveryChangelog;
use App\Models\InformationSystem;
use Illuminate\Http\Request;

class DiscoveryChangelogController extends Controller
{
    public function index($systemId)
    {
        $changelogs = DiscoveryChangelog::where('information_system_id', $systemId)
            ->orderBy('scan_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json([
            'changelogs' => $changelogs,
        ]);
    }

    public function store(Request $request, $systemId)
    {
        $request->validate([
            'scan_date' => 'required|date',
            'total_changes' => 'required|integer',
            'logs_data' => 'nullable|array',
            'status' => 'nullable|string',
        ]);

        $system = InformationSystem::findOrFail($systemId);

        $changelog = DiscoveryChangelog::updateOrCreate(
            [
                'information_system_id' => $systemId,
                'scan_date' => $request->scan_date,
            ],
            [
                'org_id' => $system->org_id,
                'total_changes' => $request->total_changes,
                'logs_data' => $request->logs_data,
                'status' => $request->status ?? 'success',
            ]
        );

        return response()->json([
            'message' => 'Changelog saved successfully.',
            'changelog' => $changelog,
        ]);
    }

    public function saveConfig(Request $request, $systemId)
    {
        $request->validate([
            'enabled' => 'required|boolean',
            'frequency' => 'required|string',
            'time' => 'required|string',
            'scope' => 'required|string',
        ]);

        $system = InformationSystem::findOrFail($systemId);

        $settings = is_array($system->settings) ? $system->settings : [];
        $settings['ai_patrol_config'] = $request->only(['enabled', 'frequency', 'time', 'scope']);

        $system->settings = $settings;
        $system->save();

        return response()->json([
            'message' => 'AI Patrol config saved successfully!',
            'config' => $settings['ai_patrol_config'],
        ]);
    }
}
