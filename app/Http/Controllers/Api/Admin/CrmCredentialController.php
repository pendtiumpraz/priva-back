<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmCredential;
use App\Services\Crm\CrmConnectorFactory;
use App\Services\TenantContextService;
use Illuminate\Http\Request;

/**
 * Per-org CRM credentials CRUD + probe (test connection).
 *
 * Secrets are stored encrypted (EncryptedString cast) and never echoed in
 * responses — clients see only the masked tail (`••••XXXX`).
 */
class CrmCredentialController extends Controller
{
    public function __construct(private TenantContextService $tenant) {}

    public function index(Request $request)
    {
        $orgId = $this->tenant->currentOrgId();
        $rows = CrmCredential::query()
            ->where('org_id', $orgId)
            ->orderBy('provider')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $orgId = $this->tenant->currentOrgId();
        $data = $this->validateInput($request);

        $cred = CrmCredential::updateOrCreate(
            [
                'org_id' => $orgId,
                'provider' => $data['provider'],
                'label' => $data['label'] ?? null,
            ],
            array_merge($data, ['org_id' => $orgId, 'rotated_at' => now()])
        );

        return response()->json(['data' => $cred], 201);
    }

    public function update(Request $request, string $id)
    {
        $orgId = $this->tenant->currentOrgId();
        $cred = CrmCredential::where('org_id', $orgId)->where('id', $id)->firstOrFail();
        $data = $this->validateInput($request, true);

        // Don't overwrite api_key/api_secret with empty strings — admins clear with explicit null.
        foreach (['api_key', 'api_secret'] as $secret) {
            if (array_key_exists($secret, $data) && $data[$secret] === '') {
                unset($data[$secret]);
            }
        }

        if (isset($data['api_key']) || isset($data['api_secret'])) {
            $data['rotated_at'] = now();
        }

        $cred->update($data);
        return response()->json(['data' => $cred]);
    }

    public function destroy(Request $request, string $id)
    {
        $orgId = $this->tenant->currentOrgId();
        $cred = CrmCredential::where('org_id', $orgId)->where('id', $id)->firstOrFail();
        $cred->delete();
        return response()->json(['ok' => true]);
    }

    public function probe(Request $request, string $id)
    {
        $orgId = $this->tenant->currentOrgId();
        $cred = CrmCredential::where('org_id', $orgId)->where('id', $id)->firstOrFail();

        try {
            $connector = CrmConnectorFactory::make($cred);
            $result = $connector->probe($cred);
            return response()->json(['data' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function validateInput(Request $request, bool $update = false): array
    {
        $req = $update ? 'sometimes' : 'required';

        return $request->validate([
            'provider' => "{$req}|in:".implode(',', CrmCredential::PROVIDERS),
            'label' => 'nullable|string|max:120',
            'is_active' => 'sometimes|boolean',
            'api_key' => 'nullable|string|max:500',
            'api_secret' => 'nullable|string|max:500',
            'endpoint_url' => 'nullable|url|max:500',
            'list_or_object_ref' => 'nullable|string|max:200',
            'extra_config' => 'nullable|array',
        ]);
    }
}
