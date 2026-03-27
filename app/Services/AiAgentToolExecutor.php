<?php

namespace App\Services;

use App\Models\Ropa;
use App\Models\Dpia;
use App\Models\GapAssessment;
use App\Models\BreachIncident;
use App\Models\BreachSimulation;
use App\Models\DsrRequest;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentRecord;
use App\Models\InformationSystem;
use App\Models\Organization;

/**
 * Executes AI Agent tool calls with strict tenant isolation.
 * Every query is filtered by org_id. No credential access allowed.
 */
class AiAgentToolExecutor
{
    private string $orgId;

    public function __construct(string $orgId)
    {
        $this->orgId = $orgId;
    }

    /**
     * Execute a tool by name with given arguments.
     * Returns [result, step_description]
     */
    public function execute(string $tool, array $args): array
    {
        return match ($tool) {
            // ROPA
            'list_ropa' => $this->listRopa($args),
            'get_ropa_detail' => $this->getRopaDetail($args),
            'create_ropa' => $this->createRopa($args),
            'update_ropa' => $this->updateRopa($args),

            // DPIA
            'list_dpia' => $this->listDpia($args),
            'get_dpia_detail' => $this->getDpiaDetail($args),
            'create_dpia' => $this->createDpia($args),
            'update_dpia' => $this->updateDpia($args),

            // GAP
            'list_gap' => $this->listGap($args),
            'get_gap_detail' => $this->getGapDetail($args),

            // Discovery
            'list_discovery' => $this->listDiscovery($args),
            'get_discovery_detail' => $this->getDiscoveryDetail($args),

            // Consent
            'list_consent' => $this->listConsent($args),
            'get_consent_stats' => $this->getConsentStats($args),

            // DSR
            'list_dsr' => $this->listDsr($args),
            'get_dsr_detail' => $this->getDsrDetail($args),
            'update_dsr' => $this->updateDsr($args),

            // Breach
            'list_breach' => $this->listBreach($args),
            'get_breach_detail' => $this->getBreachDetail($args),
            'create_breach' => $this->createBreach($args),

            // Drill
            'list_drill' => $this->listDrill($args),
            'get_drill_detail' => $this->getDrillDetail($args),

            // Organization
            'get_organization' => $this->getOrganization($args),
            'update_organization' => $this->updateOrganization($args),

            // Summary
            'get_compliance_summary' => $this->getComplianceSummary($args),

            default => [['error' => "Tool '{$tool}' tidak dikenali."], "❌ Tool tidak dikenali: {$tool}"],
        };
    }

    // =============================================
    // ROPA
    // =============================================
    private function listRopa(array $args): array
    {
        $records = Ropa::where('org_id', $this->orgId)
            ->select('id', 'registration_number', 'processing_activity', 'status', 'risk_level', 'progress', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();
        return [$records->toArray(), "🔍 Mengambil daftar ROPA... ({$records->count()} record ditemukan)"];
    }

    private function getRopaDetail(array $args): array
    {
        $r = Ropa::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (!$r) return [['error' => 'ROPA tidak ditemukan'], "❌ ROPA dengan ID tersebut tidak ditemukan"];
        return [$r->toArray(), "📋 Membaca detail ROPA: {$r->processing_activity}"];
    }

    private function createRopa(array $args): array
    {
        $forbidden = ['org_id', 'id'];
        $data = array_diff_key($args, array_flip($forbidden));
        $data['org_id'] = $this->orgId;
        $r = Ropa::create($data);
        return [$r->toArray(), "✏️ Membuat ROPA baru: {$r->processing_activity}"];
    }

    private function updateRopa(array $args): array
    {
        $r = Ropa::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (!$r) return [['error' => 'ROPA tidak ditemukan'], "❌ ROPA tidak ditemukan untuk diupdate"];
        $forbidden = ['org_id', 'id'];
        $data = array_diff_key($args, array_flip($forbidden));
        $r->update($data);
        return [$r->fresh()->toArray(), "✅ ROPA berhasil diupdate: {$r->processing_activity}"];
    }

    // =============================================
    // DPIA
    // =============================================
    private function listDpia(array $args): array
    {
        $records = Dpia::where('org_id', $this->orgId)
            ->select('id', 'registration_number', 'risk_level', 'status', 'progress', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();
        return [$records->toArray(), "🔍 Mengambil daftar DPIA... ({$records->count()} record ditemukan)"];
    }

    private function getDpiaDetail(array $args): array
    {
        $r = Dpia::where('org_id', $this->orgId)->with('ropa:id,processing_activity')->find($args['id'] ?? '');
        if (!$r) return [['error' => 'DPIA tidak ditemukan'], "❌ DPIA tidak ditemukan"];
        return [$r->toArray(), "⚠️ Membaca detail DPIA: {$r->registration_number}"];
    }

    private function createDpia(array $args): array
    {
        $data = array_diff_key($args, array_flip(['org_id', 'id']));
        $data['org_id'] = $this->orgId;
        $r = Dpia::create($data);
        return [$r->toArray(), "✏️ Membuat DPIA baru: {$r->registration_number}"];
    }

    private function updateDpia(array $args): array
    {
        $r = Dpia::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (!$r) return [['error' => 'DPIA tidak ditemukan'], "❌ DPIA tidak ditemukan"];
        $data = array_diff_key($args, array_flip(['org_id', 'id']));
        $r->update($data);
        return [$r->fresh()->toArray(), "✅ DPIA diupdate: {$r->registration_number}"];
    }

    // =============================================
    // GAP Assessment
    // =============================================
    private function listGap(array $args): array
    {
        $records = GapAssessment::where('org_id', $this->orgId)
            ->select('id', 'version', 'overall_score', 'compliance_level', 'progress', 'created_at')
            ->orderBy('created_at', 'desc')->limit(10)->get();
        return [$records->toArray(), "🔍 Mengambil daftar GAP Assessment... ({$records->count()} ditemukan)"];
    }

    private function getGapDetail(array $args): array
    {
        $r = GapAssessment::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (!$r) return [['error' => 'GAP Assessment tidak ditemukan'], "❌ GAP Assessment tidak ditemukan"];
        return [$r->toArray(), "📊 Membaca GAP Assessment v{$r->version} (skor: {$r->overall_score}%)"];
    }

    // =============================================
    // Data Discovery
    // =============================================
    private function listDiscovery(array $args): array
    {
        $records = InformationSystem::where('org_id', $this->orgId)
            ->select('id', 'name', 'source_type', 'scanning_status', 'pdp_alert_count', 'pii_alert_count', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();
        return [$records->toArray(), "🔍 Mengambil daftar Information Systems... ({$records->count()} ditemukan)"];
    }

    private function getDiscoveryDetail(array $args): array
    {
        $r = InformationSystem::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (!$r) return [['error' => 'Sistem tidak ditemukan'], "❌ Sistem informasi tidak ditemukan"];
        return [$r->toArray(), "📊 Membaca detail sistem: {$r->name}"];
    }

    // =============================================
    // Consent
    // =============================================
    private function listConsent(array $args): array
    {
        $records = ConsentCollectionPoint::where('org_id', $this->orgId)
            ->select('id', 'name', 'channel', 'is_active', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();
        return [$records->toArray(), "🔍 Mengambil daftar Consent Collection Points... ({$records->count()} ditemukan)"];
    }

    private function getConsentStats(array $args): array
    {
        $points = ConsentCollectionPoint::where('org_id', $this->orgId)->pluck('id');
        $total = ConsentRecord::whereIn('collection_point_id', $points)->count();
        $granted = ConsentRecord::whereIn('collection_point_id', $points)->where('is_granted', true)->count();
        $revoked = ConsentRecord::whereIn('collection_point_id', $points)->where('is_granted', false)->count();
        return [
            ['total_records' => $total, 'granted' => $granted, 'revoked' => $revoked, 'collection_points' => $points->count()],
            "📊 Menghitung statistik consent... ({$total} total records)"
        ];
    }

    // =============================================
    // DSR
    // =============================================
    private function listDsr(array $args): array
    {
        $records = DsrRequest::where('org_id', $this->orgId)
            ->select('id', 'request_id', 'request_type', 'requester_name', 'status', 'deadline_at', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();
        return [$records->toArray(), "🔍 Mengambil daftar DSR Requests... ({$records->count()} ditemukan)"];
    }

    private function getDsrDetail(array $args): array
    {
        $r = DsrRequest::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (!$r) return [['error' => 'DSR tidak ditemukan'], "❌ DSR Request tidak ditemukan"];
        return [$r->toArray(), "📩 Membaca DSR: {$r->request_id} ({$r->request_type})"];
    }

    private function updateDsr(array $args): array
    {
        $r = DsrRequest::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (!$r) return [['error' => 'DSR tidak ditemukan'], "❌ DSR tidak ditemukan"];
        $data = array_diff_key($args, array_flip(['org_id', 'id']));
        $r->update($data);
        return [$r->fresh()->toArray(), "✅ DSR diupdate: {$r->request_id}"];
    }

    // =============================================
    // Breach
    // =============================================
    private function listBreach(array $args): array
    {
        $records = BreachIncident::where('org_id', $this->orgId)
            ->select('id', 'incident_code', 'title', 'severity', 'status', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();
        return [$records->toArray(), "🔍 Mengambil daftar Breach Incidents... ({$records->count()} ditemukan)"];
    }

    private function getBreachDetail(array $args): array
    {
        $r = BreachIncident::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (!$r) return [['error' => 'Breach tidak ditemukan'], "❌ Breach Incident tidak ditemukan"];
        return [$r->toArray(), "🚨 Membaca detail breach: {$r->title}"];
    }

    private function createBreach(array $args): array
    {
        $data = array_diff_key($args, array_flip(['org_id', 'id']));
        $data['org_id'] = $this->orgId;
        $r = BreachIncident::create($data);
        return [$r->toArray(), "✏️ Breach incident baru dicatat: {$r->title}"];
    }

    // =============================================
    // Fire Drill
    // =============================================
    private function listDrill(array $args): array
    {
        $records = BreachSimulation::where('org_id', $this->orgId)
            ->select('id', 'scenario_type', 'scenario_title', 'overall_score', 'status', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();
        return [$records->toArray(), "🔍 Mengambil daftar Fire Drill... ({$records->count()} ditemukan)"];
    }

    private function getDrillDetail(array $args): array
    {
        $r = BreachSimulation::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (!$r) return [['error' => 'Drill tidak ditemukan'], "❌ Fire Drill tidak ditemukan"];
        return [$r->toArray(), "🔥 Membaca detail drill: {$r->scenario_title}"];
    }

    // =============================================
    // Organization
    // =============================================
    private function getOrganization(array $args): array
    {
        $org = Organization::find($this->orgId);
        if (!$org) return [['error' => 'Organisasi tidak ditemukan'], "❌ Organisasi tidak ditemukan"];
        // Strip sensitive fields
        $data = $org->toArray();
        unset($data['api_key'], $data['license_key']);
        return [$data, "🏢 Membaca detail organisasi: {$org->name}"];
    }

    private function updateOrganization(array $args): array
    {
        $org = Organization::find($this->orgId);
        if (!$org) return [['error' => 'Organisasi tidak ditemukan'], "❌ Organisasi tidak ditemukan"];
        // Only allow safe fields
        $safe = ['name', 'address', 'phone', 'industry', 'size', 'website', 'description'];
        $data = array_intersect_key($args, array_flip($safe));
        $org->update($data);
        return [$org->fresh()->makeHidden(['api_key', 'license_key'])->toArray(), "✅ Organisasi diupdate: {$org->name}"];
    }

    // =============================================
    // Compliance Summary
    // =============================================
    private function getComplianceSummary(array $args): array
    {
        $ropaCount = Ropa::where('org_id', $this->orgId)->count();
        $dpiaCount = Dpia::where('org_id', $this->orgId)->count();
        $breachCount = BreachIncident::where('org_id', $this->orgId)->count();
        $dsrCount = DsrRequest::where('org_id', $this->orgId)->count();
        $dsrPending = DsrRequest::where('org_id', $this->orgId)->where('status', 'pending')->count();
        $gap = GapAssessment::where('org_id', $this->orgId)->orderBy('created_at', 'desc')->first();
        $gapScore = $gap ? $gap->overall_score : null;
        $drillCount = BreachSimulation::where('org_id', $this->orgId)->count();

        return [[
            'ropa_count' => $ropaCount,
            'dpia_count' => $dpiaCount,
            'breach_count' => $breachCount,
            'dsr_total' => $dsrCount,
            'dsr_pending' => $dsrPending,
            'latest_gap_score' => $gapScore,
            'drill_count' => $drillCount,
        ], "📈 Mengumpulkan ringkasan compliance dari semua modul..."];
    }

    // =============================================
    // Tool Definitions (for DeepSeek function calling)
    // =============================================
    public static function getToolDefinitions(): array
    {
        return [
            // ROPA
            ['type' => 'function', 'function' => ['name' => 'list_ropa', 'description' => 'List semua ROPA (Records of Processing Activities) milik organisasi. Tidak butuh parameter.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_ropa_detail', 'description' => 'Ambil detail lengkap dari satu ROPA berdasarkan ID.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'description' => 'UUID dari ROPA']], 'required' => ['id']]]],
            ['type' => 'function', 'function' => ['name' => 'create_ropa', 'description' => 'Buat ROPA baru. Field: processing_activity (wajib), entity, division, work_unit, description, risk_level (low/medium/high/critical), purpose, legal_basis, status (draft/active/archived).', 'parameters' => ['type' => 'object', 'properties' => ['processing_activity' => ['type' => 'string'], 'entity' => ['type' => 'string'], 'division' => ['type' => 'string'], 'work_unit' => ['type' => 'string'], 'description' => ['type' => 'string'], 'risk_level' => ['type' => 'string'], 'purpose' => ['type' => 'string'], 'legal_basis' => ['type' => 'string'], 'status' => ['type' => 'string']], 'required' => ['processing_activity']]]],
            ['type' => 'function', 'function' => ['name' => 'update_ropa', 'description' => 'Update field di ROPA yang sudah ada. Harus sertakan id.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'processing_activity' => ['type' => 'string'], 'description' => ['type' => 'string'], 'risk_level' => ['type' => 'string'], 'status' => ['type' => 'string'], 'purpose' => ['type' => 'string'], 'legal_basis' => ['type' => 'string']], 'required' => ['id']]]],

            // DPIA
            ['type' => 'function', 'function' => ['name' => 'list_dpia', 'description' => 'List semua DPIA milik organisasi.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_dpia_detail', 'description' => 'Ambil detail lengkap DPIA berdasarkan ID.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],
            ['type' => 'function', 'function' => ['name' => 'create_dpia', 'description' => 'Buat DPIA baru. Field: description, risk_level, status, ropa_id (optional link ke ROPA).', 'parameters' => ['type' => 'object', 'properties' => ['description' => ['type' => 'string'], 'risk_level' => ['type' => 'string'], 'status' => ['type' => 'string'], 'ropa_id' => ['type' => 'string']], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'update_dpia', 'description' => 'Update DPIA.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'description' => ['type' => 'string'], 'risk_level' => ['type' => 'string'], 'status' => ['type' => 'string']], 'required' => ['id']]]],

            // GAP
            ['type' => 'function', 'function' => ['name' => 'list_gap', 'description' => 'List semua GAP Assessment milik organisasi.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_gap_detail', 'description' => 'Ambil detail GAP Assessment (termasuk skor, jawaban, rekomendasi).', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],

            // Discovery
            ['type' => 'function', 'function' => ['name' => 'list_discovery', 'description' => 'List semua Information Systems (Data Discovery).', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_discovery_detail', 'description' => 'Detail Information System beserta scan results.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],

            // Consent
            ['type' => 'function', 'function' => ['name' => 'list_consent', 'description' => 'List semua Consent Collection Points.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_consent_stats', 'description' => 'Statistik consent: total records, granted, revoked.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],

            // DSR
            ['type' => 'function', 'function' => ['name' => 'list_dsr', 'description' => 'List semua DSR (Data Subject Request).', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_dsr_detail', 'description' => 'Detail DSR Request.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],
            ['type' => 'function', 'function' => ['name' => 'update_dsr', 'description' => 'Update status DSR. Field: status (pending/in_progress/completed/rejected), response, rejection_reason.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'status' => ['type' => 'string'], 'response' => ['type' => 'string']], 'required' => ['id']]]],

            // Breach
            ['type' => 'function', 'function' => ['name' => 'list_breach', 'description' => 'List semua Breach Incidents.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_breach_detail', 'description' => 'Detail Breach Incident.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],
            ['type' => 'function', 'function' => ['name' => 'create_breach', 'description' => 'Catat breach incident baru. Field: title (wajib), description, severity (low/medium/high/critical), source, status.', 'parameters' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'description' => ['type' => 'string'], 'severity' => ['type' => 'string'], 'source' => ['type' => 'string']], 'required' => ['title']]]],

            // Drill
            ['type' => 'function', 'function' => ['name' => 'list_drill', 'description' => 'List semua Fire Drill / Breach Simulation.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_drill_detail', 'description' => 'Detail Fire Drill beserta skor.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],

            // Organization
            ['type' => 'function', 'function' => ['name' => 'get_organization', 'description' => 'Ambil detail organisasi (nama, alamat, industri, dll). Tidak bisa mengakses credentials.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'update_organization', 'description' => 'Update info organisasi. Field yang diizinkan: name, address, phone, industry, size, website, description. TIDAK BISA mengubah credentials/email/password.', 'parameters' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'address' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'industry' => ['type' => 'string'], 'size' => ['type' => 'string'], 'website' => ['type' => 'string'], 'description' => ['type' => 'string']], 'required' => []]]],

            // Summary
            ['type' => 'function', 'function' => ['name' => 'get_compliance_summary', 'description' => 'Ringkasan compliance seluruh modul: jumlah ROPA, DPIA, Breach, DSR, GAP score, dll.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
        ];
    }
}
