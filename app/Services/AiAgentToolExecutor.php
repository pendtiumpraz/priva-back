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
use App\Models\User;
use App\Models\License;
use App\Models\ChatConversation;
use App\Models\AuditLog;

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

            // SuperAdmin tools
            'list_users' => $this->listUsers($args),
            'list_licenses' => $this->listLicenses($args),
            'list_chat_history' => $this->listChatHistory($args),

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

    private function syncRopaWizardData(\App\Models\Ropa $r, array $data): void
    {
        $wizardData = $r->wizard_data ?? [];
        $changed = false;
        if (defined('\App\Models\Ropa::WIZARD_SECTIONS')) {
            foreach (\App\Models\Ropa::WIZARD_SECTIONS as $section) {
                $key = $section['key'];
                if (!isset($wizardData[$key])) $wizardData[$key] = [];
                foreach ($section['fields'] ?? [] as $field) {
                    if (isset($data[$field])) {
                        $wizardData[$key][$field] = $data[$field];
                        $changed = true;
                    }
                }
            }
        }
        if ($changed) {
            $r->wizard_data = $wizardData;
            if (method_exists($r, 'calculateProgress')) {
                $r->progress = $r->calculateProgress();
            }
            $r->save();
        }
    }

    private function createRopa(array $args): array
    {
        $forbidden = ['org_id', 'id'];
        $data = array_diff_key($args, array_flip($forbidden));
        $data['org_id'] = $this->orgId;
        $data['registration_number'] = $data['registration_number'] ?? 'ROPA-AI-' . date('Y') . '-' . rand(100, 999);
        
        // Extract wizard_data before creating (it's a JSON column)
        $wizardData = $data['wizard_data'] ?? null;
        unset($data['wizard_data']);
        
        $r = Ropa::create($data);
        
        // If agent provided wizard_data, write it directly
        if ($wizardData && is_array($wizardData)) {
            $r->wizard_data = $wizardData;
            $r->save();
        } else {
            $this->syncRopaWizardData($r, $data);
        }

        try { 
            AuditLog::create(['module' => 'ropa', 'record_id' => $r->id, 'action' => 'created', 'user_name' => '✨ PRIVASIMU AI Agent', 'user_role' => 'system', 'section' => 'Automated AI Creation']); 
        } catch(\Exception $e) {}
        
        return [$r->fresh()->toArray(), "✏️ Membuat ROPA baru: {$r->processing_activity}"];
    }

    private function updateRopa(array $args): array
    {
        $r = Ropa::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (!$r) return [['error' => 'ROPA tidak ditemukan'], "❌ ROPA tidak ditemukan untuk diupdate"];
        $forbidden = ['org_id', 'id'];
        $data = array_diff_key($args, array_flip($forbidden));
        
        // Extract wizard_data before updating
        $wizardData = $data['wizard_data'] ?? null;
        unset($data['wizard_data']);
        
        $r->update($data);
        
        // If agent provided wizard_data, merge with existing
        if ($wizardData && is_array($wizardData)) {
            $existing = $r->wizard_data ?? [];
            $r->wizard_data = array_replace_recursive($existing, $wizardData);
            $r->save();
        } else {
            $this->syncRopaWizardData($r, $data);
        }

        try { 
            AuditLog::create(['module' => 'ropa', 'record_id' => $r->id, 'action' => 'updated', 'user_name' => '✨ PRIVASIMU AI Agent', 'user_role' => 'system', 'section' => 'AI Automated Edit', 'changes' => array_keys($data)]); 
        } catch(\Exception $e) {}
        
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

    private function syncDpiaWizardData(\App\Models\Dpia $r, array $data): void
    {
        $wizardData = $r->wizard_data ?? [];
        $changed = false;
        
        if (isset($data['description'])) {
            if (!isset($wizardData['informasi_dpia'])) $wizardData['informasi_dpia'] = [];
            $wizardData['informasi_dpia']['description'] = $data['description'];
            $changed = true;
        }
        if (isset($data['ropa_id']) && $data['ropa_id']) {
            if (!isset($wizardData['koneksi_ropa'])) $wizardData['koneksi_ropa'] = [];
            $wizardData['koneksi_ropa']['connected_ropas'] = [$data['ropa_id']];
            $changed = true;
        }
        
        if ($changed) {
            $r->wizard_data = $wizardData;
            $r->save();
        }
    }

    private function createDpia(array $args): array
    {
        $data = array_diff_key($args, array_flip(['org_id', 'id']));
        $data['org_id'] = $this->orgId;
        $data['registration_number'] = $data['registration_number'] ?? 'DPIA-AI-' . date('Y') . '-' . rand(100, 999);
        
        // Extract wizard_data before creating
        $wizardData = $data['wizard_data'] ?? null;
        unset($data['wizard_data']);
        
        $r = Dpia::create($data);
        
        // If agent provided wizard_data (with potensi_risiko), write directly
        if ($wizardData && is_array($wizardData)) {
            $r->wizard_data = $wizardData;
            $r->save();
        } else {
            $this->syncDpiaWizardData($r, $data);
        }

        try { 
            AuditLog::create(['module' => 'dpia', 'record_id' => $r->id, 'action' => 'created', 'user_name' => '✨ PRIVASIMU AI Agent', 'user_role' => 'system', 'section' => 'Automated AI Creation']); 
        } catch(\Exception $e) {}
        
        return [$r->fresh()->toArray(), "✏️ Membuat DPIA baru: {$r->registration_number}"];
    }

    private function updateDpia(array $args): array
    {
        $r = Dpia::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (!$r) return [['error' => 'DPIA tidak ditemukan'], "❌ DPIA tidak ditemukan"];
        $data = array_diff_key($args, array_flip(['org_id', 'id']));
        
        // Extract wizard_data before updating
        $wizardData = $data['wizard_data'] ?? null;
        unset($data['wizard_data']);
        
        $r->update($data);
        
        // If agent provided wizard_data, merge with existing
        if ($wizardData && is_array($wizardData)) {
            $existing = $r->wizard_data ?? [];
            $r->wizard_data = array_replace_recursive($existing, $wizardData);
            $r->save();
        } else {
            $this->syncDpiaWizardData($r, $data);
        }

        try { 
            AuditLog::create(['module' => 'dpia', 'record_id' => $r->id, 'action' => 'updated', 'user_name' => '✨ PRIVASIMU AI Agent', 'user_role' => 'system', 'section' => 'AI Automated Edit', 'changes' => array_keys($data)]); 
        } catch(\Exception $e) {}
        
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
        
        try { 
            AuditLog::create(['module' => 'dsr', 'record_id' => $r->id, 'action' => 'updated', 'user_name' => '✨ PRIVASIMU AI Agent', 'user_role' => 'system', 'section' => 'AI Automated Edit', 'changes' => array_keys($data)]); 
        } catch(\Exception $e) {}
        
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
        $data['incident_code'] = $data['incident_code'] ?? 'BRC-AI-' . date('Y') . '-' . rand(100, 999);
        $r = BreachIncident::create($data);
        
        try { 
            AuditLog::create(['module' => 'breach', 'record_id' => $r->id, 'action' => 'created', 'user_name' => '✨ PRIVASIMU AI Agent', 'user_role' => 'system', 'section' => 'Automated AI Creation']); 
        } catch(\Exception $e) {}
        
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
        $data = $org->toArray();
        unset($data['api_key'], $data['license_key']);
        return [$data, "🏢 Membaca detail organisasi: {$org->name}"];
    }

    private function updateOrganization(array $args): array
    {
        $org = Organization::find($this->orgId);
        if (!$org) return [['error' => 'Organisasi tidak ditemukan'], "❌ Organisasi tidak ditemukan"];
        $safe = ['name', 'address', 'phone', 'industry', 'size', 'website', 'description'];
        $data = array_intersect_key($args, array_flip($safe));
        $org->update($data);
        
        try { 
            AuditLog::create(['module' => 'organization', 'record_id' => $org->id, 'action' => 'updated', 'user_name' => '✨ PRIVASIMU AI Agent', 'user_role' => 'system', 'section' => 'AI Automated Edit', 'changes' => array_keys($data)]); 
        } catch(\Exception $e) {}
        
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
    // SuperAdmin-only tools (read-only)
    // =============================================
    private function listUsers(array $args): array
    {
        $users = User::select('id', 'name', 'role', 'org_id', 'created_at')
            ->orderBy('created_at', 'desc')->limit(50)->get();
        return [$users->toArray(), "👥 Mengambil daftar user... ({$users->count()} user ditemukan)"];
    }

    private function listLicenses(array $args): array
    {
        $licenses = License::select('id', 'license_key', 'org_id', 'package_type', 'status', 'expires_at', 'created_at')
            ->orderBy('created_at', 'desc')->limit(30)->get();
        return [$licenses->toArray(), "🔑 Mengambil daftar license... ({$licenses->count()} license ditemukan)"];
    }

    private function listChatHistory(array $args): array
    {
        $chats = ChatConversation::withCount('messages')
            ->orderBy('last_message_at', 'desc')->limit(30)->get()
            ->map(fn($c) => [
                'id' => $c->id, 'user_name' => $c->user_name, 'user_email' => $c->user_email,
                'org_id' => $c->org_id, 'status' => $c->status, 'messages_count' => $c->messages_count,
                'last_message_at' => $c->last_message_at,
            ]);
        return [$chats->toArray(), "💬 Mengambil riwayat chat... ({$chats->count()} percakapan ditemukan)"];
    }

    // =============================================
    // Tool Definitions for regular users (compliance tools)
    // =============================================
    public static function getToolDefinitions(): array
    {
        return [
            // ROPA
            ['type' => 'function', 'function' => ['name' => 'list_ropa', 'description' => 'List semua ROPA (Records of Processing Activities) milik organisasi. Tidak butuh parameter.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_ropa_detail', 'description' => 'Ambil detail lengkap dari satu ROPA berdasarkan ID.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'description' => 'UUID dari ROPA']], 'required' => ['id']]]],
            ['type' => 'function', 'function' => ['name' => 'create_ropa', 'description' => 'Buat ROPA baru. PENTING: risk_level harus low/medium/high. status harus draft/active/archived. Sertakan wizard_data jika ingin mengisi form wizard.', 'parameters' => ['type' => 'object', 'properties' => [
                'processing_activity' => ['type' => 'string', 'description' => 'Nama aktivitas pemrosesan (wajib)'],
                'entity' => ['type' => 'string'], 'division' => ['type' => 'string'], 'work_unit' => ['type' => 'string'],
                'description' => ['type' => 'string'], 'purpose' => ['type' => 'string'], 'legal_basis' => ['type' => 'string'],
                'risk_level' => ['type' => 'string', 'description' => 'HARUS: low | medium | high'],
                'status' => ['type' => 'string', 'description' => 'HARUS: draft | active | archived'],
                'wizard_data' => ['type' => 'object', 'description' => 'Data wizard 7 section. Gunakan FIELD-FIELD BERIKUT:\n'
                    . 'detail_pemrosesan: {nama_pemrosesan, entitas, divisi, unit_kerja, deskripsi, risk_level}\n'
                    . 'dpo_team: {kategori_pemrosesan: "Pengendali Data Pribadi"|"Pemroses Data Pribadi", dpo_name, dpo_email, dpo_phone}\n'
                    . 'informasi_pemrosesan: {tujuan, penjelasan, jenis_pemrosesan: array dari ["Pemerolehan dan pengumpulan data","Pengolahan dan penganalisisan data","Penyimpanan data","Perbaikan dan pembaruan data","Penampilan, pengumuman, transfer, penyebarluasan, atau pengungkapan data","Penghapusan atau pemusnahan data"], dasar_pemrosesan: pilih 1 dari ["Persetujuan yang Sah Secara Eksplisit","Pemenuhan Kewajiban Perjanjian","Pemenuhan Kewajiban Hukum","Pemenuhan Pelindungan Kepentingan Vital","Pelaksanaan Tugas dalam Rangka Kepentingan Umum","Pemenuhan Kepentingan yang Sah (Legitimate Interest)"], sistem_terkait: array}\n'
                    . 'pengumpulan_data: {sumber_data, jumlah_subjek: "≤ 1.000 subjek"|"> 1.000 subjek", kategori_subjek: array, jenis_data_spesifik: array dari ["Data Kesehatan","Data Biometrik","Data Genetika","Data Catatan Kejahatan","Data Anak","Data Keuangan Pribadi","Data Ras/Etnis","Data Pandangan Politik","Data Agama/Kepercayaan","Data Orientasi Seksual"], jenis_data_umum: array dari ["Nama Lengkap","Jenis Kelamin","Kewarganegaraan","Agama","Status Perkawinan","Alamat","Nomor Telepon","Email","Tanggal Lahir","Pendidikan","Pekerjaan"], jenis_data_pii: array dari ["NIK/KTP","Nomor Paspor","SIM","NPWP","Nomor Rekening","Alamat IP (IP Address)","Cookie ID","Device ID"]}\n'
                    . 'penggunaan_penyimpanan: {pihak_pemroses, kategori_pihak: array dari ["Pengendali Data (Controller)","Pemroses Data (Processor)","Pengendali Bersama (Joint Controller)","Lainnya"], cara_pemrosesan, lokasi_penyimpanan, pihak_ketiga: "Ya"|"Tidak"}\n'
                    . 'pengiriman_data: {ada_penerima: "Ya"|"Tidak", penerima_data, transfer_luar: "Ya"|"Tidak", negara_tujuan, safeguards}\n'
                    . 'retensi_keamanan: {kontrol_keamanan: array dari ["Enkripsi (at-rest & in-transit)","Tokenization / Pseudonymization","Access Control (RBAC)","Backup & Disaster Recovery","Audit Log & Monitoring","Vulnerability Assessment","Penetration Testing"], masa_retensi, prosedur_pemusnahan}'],
            ], 'required' => ['processing_activity']]]],
            ['type' => 'function', 'function' => ['name' => 'update_ropa', 'description' => 'Update field di ROPA yang sudah ada. Harus sertakan id. Bisa sertakan wizard_data untuk update form wizard.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'processing_activity' => ['type' => 'string'], 'description' => ['type' => 'string'], 'risk_level' => ['type' => 'string', 'description' => 'HARUS: low | medium | high'], 'status' => ['type' => 'string', 'description' => 'HARUS: draft | active | archived'], 'purpose' => ['type' => 'string'], 'legal_basis' => ['type' => 'string'], 'wizard_data' => ['type' => 'object', 'description' => 'Sama format dengan create_ropa wizard_data']], 'required' => ['id']]]],

            // DPIA
            ['type' => 'function', 'function' => ['name' => 'list_dpia', 'description' => 'List semua DPIA milik organisasi.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_dpia_detail', 'description' => 'Ambil detail lengkap DPIA berdasarkan ID.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],
            ['type' => 'function', 'function' => ['name' => 'create_dpia', 'description' => 'Buat DPIA baru. risk_level HARUS low/medium/high. status HARUS draft/in_progress/approved. Bisa sertakan wizard_data dengan potensi_risiko = object 21 kategori, setiap kategori: {answer: "sudah"|"sebagian"|"belum"|"tidak_berlaku", description: "..."}. Kategori: Dasar Hukum Pemrosesan, Pemrosesan Data Pribadi yang Sah, Kesesuaian Tujuan Pemrosesan, Minimisasi Data, Keakuratan Data, Pembatasan Penyimpanan, Integritas dan Kerahasiaan, Akuntabilitas, Hak Subjek Data - Akses, Hak Subjek Data - Koreksi, Hak Subjek Data - Hapus, Hak Subjek Data - Portabilitas, Persetujuan dan Consent, Transfer Data Lintas Batas, Enkripsi dan Pseudonymization, Kontrol Akses, Monitoring dan Logging, Retensi Data, Manajemen Insiden, Pelatihan dan Kesadaran, Penilaian Dampak Berkala.', 'parameters' => ['type' => 'object', 'properties' => ['description' => ['type' => 'string'], 'risk_level' => ['type' => 'string', 'description' => 'HARUS: low | medium | high'], 'status' => ['type' => 'string', 'description' => 'HARUS: draft | in_progress | approved'], 'ropa_id' => ['type' => 'string'], 'wizard_data' => ['type' => 'object']], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'update_dpia', 'description' => 'Update DPIA. Bisa sertakan wizard_data.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'description' => ['type' => 'string'], 'risk_level' => ['type' => 'string', 'description' => 'HARUS: low | medium | high'], 'status' => ['type' => 'string'], 'wizard_data' => ['type' => 'object']], 'required' => ['id']]]],

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
            ['type' => 'function', 'function' => ['name' => 'update_dsr', 'description' => 'Update status DSR. status HARUS: new | new_reply | replied | rejected | closed. request_type HARUS: access | rectification | erasure | portability | restriction | objection.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'status' => ['type' => 'string', 'description' => 'HARUS: new | new_reply | replied | rejected | closed'], 'response' => ['type' => 'string']], 'required' => ['id']]]],

            // Breach
            ['type' => 'function', 'function' => ['name' => 'list_breach', 'description' => 'List semua Breach Incidents.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_breach_detail', 'description' => 'Detail Breach Incident.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],
            ['type' => 'function', 'function' => ['name' => 'create_breach', 'description' => 'Catat breach incident baru. severity HARUS: low | medium | high | critical. status HARUS: detected | assessing | containment | notification | closed. source HARUS: manual | automated | external_report | monitoring.', 'parameters' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'description' => ['type' => 'string'], 'severity' => ['type' => 'string', 'description' => 'HARUS: low | medium | high | critical'], 'source' => ['type' => 'string', 'description' => 'HARUS: manual | automated | external_report | monitoring'], 'status' => ['type' => 'string', 'description' => 'HARUS: detected | assessing | containment | notification | closed'], 'affected_subjects_count' => ['type' => 'integer'], 'notification_required' => ['type' => 'boolean']], 'required' => ['title']]]],

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

    // =============================================
    // Tool Definitions for SuperAdmin (admin/read-only tools only)
    // =============================================
    public static function getSuperAdminToolDefinitions(): array
    {
        return [
            ['type' => 'function', 'function' => ['name' => 'list_users', 'description' => 'List semua user di platform (read-only). Menampilkan nama, role, dan organisasi. TIDAK menampilkan email/password.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'list_licenses', 'description' => 'List semua license yang terdaftar. Menampilkan key, package_type, status, dan expired.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'list_chat_history', 'description' => 'List riwayat chat dari semua user. Menampilkan nama user, jumlah pesan, dan waktu terakhir.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_organization', 'description' => 'Ambil detail organisasi (nama, alamat, industri, dll).', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_compliance_summary', 'description' => 'Ringkasan compliance seluruh modul: jumlah ROPA, DPIA, Breach, DSR, GAP score, dll.', 'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
        ];
    }
}

