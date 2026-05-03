<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WizardSchemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns the merged (built-in + org-custom) wizard schema for a module.
 * Read-only — any authenticated user with access to the module can fetch.
 *
 * See CUSTOM_WIZARD_PLAN.md §5.1 / §5.2.
 */
class WizardSchemaController extends Controller
{
    public function __construct(private readonly WizardSchemaService $schema) {}

    public function show(Request $request, string $module): JsonResponse
    {
        if (! in_array($module, WizardSchemaService::SUPPORTED_MODULES, true)) {
            return response()->json(['message' => 'Invalid module'], 422);
        }

        $user = $request->user();
        $orgId = $user?->org_id;
        if (! $orgId) {
            return response()->json(['message' => 'Tenant context missing.'], 403);
        }

        return response()->json([
            'data' => $this->schema->getSchema($orgId, $module),
        ]);
    }
}
