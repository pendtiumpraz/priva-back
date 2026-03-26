<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Ropa, Dpia, DsrRequest, ConsentCollectionPoint, BreachIncident, InformationSystem, AuditLog};
use Illuminate\Http\Request;

class ModuleCrudController extends Controller
{
    private function getModel(string $module)
    {
        return match ($module) {
                'ropa' => new Ropa,
                'dpia' => new Dpia,
                'dsr' => new DsrRequest,
                'consent' => new ConsentCollectionPoint,
                'breach' => new BreachIncident,
                'data-discovery' => new InformationSystem,
                default => abort(404, 'Module not found'),
            };
    }

    private function nextCode(string $prefix, $model, string $orgId): string
    {
        $year = date('Y');
        $pattern = $prefix . '-' . $year . '-%';
        
        $codeColumn = match ($prefix) {
            'ROPA', 'DPIA' => 'registration_number',
            'DSR' => 'request_id',
            'CNT' => 'collection_id',
            'BRC' => 'incident_code',
            default => 'registration_number',
        };
        
        // Get all existing codes matching the pattern, extract max number
        $codes = $model->withTrashed()
            ->where($codeColumn, 'like', $pattern)
            ->pluck($codeColumn)
            ->toArray();
        
        $maxNum = 0;
        foreach ($codes as $code) {
            $num = (int) substr($code, strrpos($code, '-') + 1);
            if ($num > $maxNum) $maxNum = $num;
        }
        
        return $prefix . '-' . $year . '-' . str_pad($maxNum + 1, 3, '0', STR_PAD_LEFT);
    }

    /**
     * List records
     */
    public function index(Request $request, string $module)
    {
        $model = $this->getModel($module);
        $query = $model->where('org_id', $request->user()->org_id);

        if ($request->get('trash'))
            $query->onlyTrashed();
        if ($request->get('status'))
            $query->where('status', $request->get('status'));

        // Add relationship counts for consent module
        if ($module === 'consent') {
            $query->withCount(['items', 'records']);
        }

        return response()->json(['data' => $query->orderBy('created_at', 'desc')->get()]);
    }

    /**
     * Create record
     */
    public function store(Request $request, string $module)
    {
        try {
            $model = $this->getModel($module);
            $data = $request->all();
            $data['org_id'] = $request->user()->org_id;
            $data['created_by'] = $request->user()->id;

            // Ensure boolean fields are properly cast
            if ($module === 'breach') {
                $data['notification_required'] = filter_var($data['notification_required'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $data['is_simulation'] = filter_var($data['is_simulation'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $data['affected_subjects_count'] = (int) ($data['affected_subjects_count'] ?? 0);
            }

            // Auto-generate codes
            switch ($module) {
                case 'ropa':
                    $data['registration_number'] = $data['registration_number'] ?? $this->nextCode('ROPA', $model, $data['org_id']);
                    // Auto-risk: if data_categories contain sensitive types → auto HIGH
                    $sensitiveKeywords = ['kesehatan', 'biometrik', 'genetik', 'anak', 'keuangan', 'ras', 'agama', 'orientasi seksual', 'pandangan politik'];
                    $categories = $data['data_categories'] ?? [];
                    if (is_array($categories)) {
                        $categoriesStr = strtolower(implode(' ', $categories));
                        foreach ($sensitiveKeywords as $keyword) {
                            if (str_contains($categoriesStr, $keyword)) {
                                $data['risk_level'] = 'high';
                                break;
                            }
                        }
                    }
                    break;
                case 'dpia':
                    $data['registration_number'] = $data['registration_number'] ?? $this->nextCode('DPIA', $model, $data['org_id']);
                    break;
                case 'dsr':
                    $data['request_id'] = $data['request_id'] ?? $this->nextCode('DSR', $model, $data['org_id']);
                    $data['deadline_at'] = $data['deadline_at'] ?? now()->addHours(72);
                    break;
                case 'consent':
                    $data['collection_id'] = $data['collection_id'] ?? $this->nextCode('CNT', $model, $data['org_id']);
                    break;
                case 'breach':
                    $data['incident_code'] = $data['incident_code'] ?? $this->nextCode('BRC', $model, $data['org_id']);
                    $data['detected_at'] = $data['detected_at'] ?? now();
                    if ($data['notification_required']) {
                        $data['notification_deadline'] = $data['notification_deadline'] ?? now()->addHours(72);
                    }
                    // Auto-init containment checklist
                    if (empty($data['containment_checklist'])) {
                        $data['containment_checklist'] = [
                            'Isolasi sistem yang terdampak' => false,
                            'Blokir akses yang tidak sah' => false,
                            'Preserve evidence (backup log)' => false,
                            'Ubah credentials yang compromised' => false,
                            'Aktifkan firewall rules tambahan' => false,
                            'Identifikasi root cause' => false,
                            'Hapus malware / tutup vulnerability' => false,
                            'Patch sistem yang terdampak' => false,
                            'Restore data dari backup' => false,
                            'Verifikasi integritas data' => false,
                        ];
                    }
                    // Auto-init timeline
                    if (empty($data['timeline_log'])) {
                        $data['timeline_log'] = [
                            ['time' => now()->format('d/m/Y H:i'), 'event' => '🔴 Insiden terdeteksi — ' . ($data['source'] ?? 'manual')],
                        ];
                    }
                    break;
            }

            $record = $model->create($data);

            // Audit log: record created
            try {
                AuditLog::log($module, $record->id, 'created', [
                    'registration_number' => $record->registration_number ?? $record->request_id ?? $record->incident_code ?? null,
                ], 'system');
            } catch (\Exception $e) {
                // Don't fail the main operation if audit logging fails
                \Log::warning('Audit log failed: ' . $e->getMessage());
            }

            // Auto-trigger: if ROPA risk=high → create draft DPIA
            if ($module === 'ropa' && ($data['risk_level'] ?? '') === 'high') {
                $dpiaModel = $this->getModel('dpia');
                $existingDpia = $dpiaModel->where('ropa_id', $record->id)->first();
                if (!$existingDpia) {
                    $dpiaModel->create([
                        'org_id' => $data['org_id'],
                        'registration_number' => $this->nextCode('DPIA', $dpiaModel, $data['org_id']),
                        'ropa_id' => $record->id,
                        'risk_level' => 'high',
                        'status' => 'draft',
                        'description' => 'Auto-generated dari ROPA high-risk: ' . ($data['processing_activity'] ?? ''),
                        'risk_assessment' => ['likelihood' => 0, 'impact' => 0, 'risks' => []],
                        'mitigation_measures' => [],
                        'created_by' => $data['created_by'],
                    ]);
                }
            }

            return response()->json(['message' => 'Created', 'data' => $record], 201);
        } catch (\Exception $e) {
            \Log::error('ModuleCrud store error: ' . $e->getMessage(), [
                'module' => $module,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Show record
     */
    public function show(string $module, string $id)
    {
        $model = $this->getModel($module);
        $query = $model->withTrashed();

        if ($module === 'consent') {
            $query->with(['items', 'records']);
        }

        return response()->json(['data' => $query->findOrFail($id)]);
    }

    /**
     * Update record
     */
    public function update(Request $request, string $module, string $id)
    {
        $model = $this->getModel($module);
        $record = $model->findOrFail($id);

        // Auto-append timeline for breach status changes
        if ($module === 'breach' && $request->has('status') && $record->status !== $request->input('status')) {
            $statusLabels = [
                'detected' => '🔴 Insiden terdeteksi',
                'assessing' => '📋 Assessment dimulai',
                'containment' => '🛡️ Containment dimulai',
                'notification' => '📨 Fase notifikasi dimulai',
                'closed' => '✅ Insiden ditutup',
            ];
            $newStatus = $request->input('status');
            $timeline = $record->timeline_log ?? [];
            $timeline[] = [
                'time' => now()->format('d/m/Y H:i'),
                'event' => $statusLabels[$newStatus] ?? "Status → {$newStatus}",
            ];
            $request->merge(['timeline_log' => $timeline]);
        }

        // Detect wizard_data changes for audit logging
        $oldWizard = $record->wizard_data ?? [];
        $newWizard = $request->input('wizard_data', []);

        $record->update($request->all());

        // Audit log: detect section-level changes in wizard_data
        if (!empty($newWizard) && is_array($newWizard)) {
            foreach ($newWizard as $sectionKey => $sectionData) {
                $oldSection = $oldWizard[$sectionKey] ?? [];
                if (json_encode($oldSection) !== json_encode($sectionData)) {
                    // Find what changed
                    $changedFields = [];
                    if (is_array($sectionData)) {
                        foreach ($sectionData as $field => $value) {
                            $oldVal = $oldSection[$field] ?? null;
                            if (json_encode($oldVal) !== json_encode($value)) {
                                $changedFields[$field] = ['old' => $oldVal, 'new' => $value];
                            }
                        }
                    }
                    if (!empty($changedFields)) {
                        AuditLog::log($module, $record->id, 'answer_added', $changedFields, $sectionKey);
                    }
                }
            }
        }

        // Auto-trigger DPIA when ROPA risk changes to high
        if ($module === 'ropa' && $request->input('risk_level') === 'high') {
            $dpiaModel = $this->getModel('dpia');
            $existingDpia = $dpiaModel->where('ropa_id', $record->id)->first();
            if (!$existingDpia) {
                $dpiaModel->create([
                    'org_id' => $record->org_id,
                    'registration_number' => $this->nextCode('DPIA', $dpiaModel, $record->org_id),
                    'ropa_id' => $record->id,
                    'risk_level' => 'high',
                    'status' => 'draft',
                    'description' => 'Auto-generated dari ROPA high-risk: ' . $record->processing_activity,
                    'risk_assessment' => ['likelihood' => 0, 'impact' => 0, 'risks' => []],
                    'mitigation_measures' => [],
                    'created_by' => $request->user()->id,
                ]);
            }
        }

        return response()->json(['message' => 'Updated', 'data' => $record->fresh()]);
    }

    /**
     * Soft delete
     */
    public function destroy(string $module, string $id)
    {
        $model = $this->getModel($module);
        $model->findOrFail($id)->delete();
        return response()->json(['message' => 'Moved to trash']);
    }

    /**
     * Restore
     */
    public function restore(string $module, string $id)
    {
        $model = $this->getModel($module);
        $record = $model->onlyTrashed()->findOrFail($id);
        $record->restore();
        return response()->json(['message' => 'Restored', 'data' => $record]);
    }

    /**
     * Force delete
     */
    public function forceDelete(string $module, string $id)
    {
        $model = $this->getModel($module);
        $model->onlyTrashed()->findOrFail($id)->forceDelete();
        return response()->json(['message' => 'Permanently deleted']);
    }

    /**
     * Get audit history for a record
     */
    public function history(string $module, string $id)
    {
        $logs = AuditLog::where('module', $module)
            ->where('record_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $logs]);
    }
}
