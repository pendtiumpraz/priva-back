<?php

namespace App\Services;

use App\Models\BreachIncident;
use App\Models\CrossBorderTransfer;
use App\Models\Dpia;
use App\Models\DsrRequest;
use App\Models\InformationSystem;
use App\Models\PostureFinding;
use App\Models\Ropa;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 3b — Materialize posture findings from real data into actionable
 * tickets. Each detector returns an array of "candidate findings", and
 * the service either creates a new finding or bumps `last_seen_at` on
 * an existing one (dedup via stable source_key).
 *
 * If a previously-open finding is no longer present in the candidate
 * list (e.g. operator added the protection assessment), it auto-resolves
 * with `resolution_notes = 'auto-resolved: condition no longer present'`.
 *
 * Idempotent — safe to run on every scan, every cron tick, every
 * upstream record change.
 */
class PostureFindingService
{
    public function materialize(string $orgId): array
    {
        $candidates = array_merge(
            $this->detectSensitiveProtection($orgId),
            $this->detectClassificationCoverage($orgId),
            $this->detectSchemaDrift($orgId),
            $this->detectDpiaCompliance($orgId),
            $this->detectRtpHygiene($orgId),
            $this->detectVendorRisk($orgId),
            $this->detectCrossBorderBasis($orgId),
            $this->detectBreachReadiness($orgId),
            $this->detectDsrCompliance($orgId),
            $this->detectExcessiveAccess($orgId),       // Phase 3c
            $this->detectEncryptionGaps($orgId),        // Phase 3c
        );

        $now = now();
        $candidateKeys = array_column($candidates, 'source_key');

        $created = 0;
        $bumped = 0;
        $autoResolved = 0;

        // Upsert: create new or bump last_seen_at on existing
        foreach ($candidates as $c) {
            $existing = PostureFinding::query()
                ->withoutGlobalScope('org')
                ->where('org_id', $orgId)
                ->where('source_key', $c['source_key'])
                ->first();

            if ($existing) {
                // If user already resolved/accepted/dismissed, don't reopen automatically.
                // Only bump last_seen_at if still open.
                if (in_array($existing->status, [PostureFinding::STATUS_OPEN, PostureFinding::STATUS_IN_PROGRESS], true)) {
                    $existing->last_seen_at = $now;
                    // Severity may escalate — let it.
                    if ($this->severityRank($c['severity']) > $this->severityRank($existing->severity)) {
                        $existing->severity = $c['severity'];
                        $existing->sla_due_at = $now->copy()->addDays(PostureFinding::SLA_DAYS[$c['severity']]);
                    }
                    $existing->save();
                    $bumped++;
                }
            } else {
                try {
                    PostureFinding::create([
                        'org_id' => $orgId,
                        'source_pillar' => $c['source_pillar'],
                        'source_key' => $c['source_key'],
                        'source_type' => $c['source_type'] ?? null,
                        'source_id' => $c['source_id'] ?? null,
                        'source_detail' => $c['source_detail'] ?? null,
                        'severity' => $c['severity'],
                        'title' => $c['title'],
                        'description' => $c['description'] ?? null,
                        'regulation_ref' => $c['regulation_ref'] ?? null,
                        'metadata' => $c['metadata'] ?? null,
                        'status' => PostureFinding::STATUS_OPEN,
                        'sla_due_at' => $now->copy()->addDays(PostureFinding::SLA_DAYS[$c['severity']]),
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                    ]);
                    $created++;
                } catch (Throwable $e) {
                    Log::warning('Failed to create posture finding', [
                        'org_id' => $orgId,
                        'source_key' => $c['source_key'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Auto-resolve: open findings whose source_key is NOT in current candidates
        // (= problem no longer exists)
        $stale = PostureFinding::query()
            ->withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->whereIn('status', [PostureFinding::STATUS_OPEN, PostureFinding::STATUS_IN_PROGRESS])
            ->whereNotIn('source_key', $candidateKeys)
            ->get();

        foreach ($stale as $f) {
            $f->status = PostureFinding::STATUS_RESOLVED;
            $f->resolved_at = $now;
            $f->resolution_notes = 'auto-resolved: kondisi sudah tidak terdeteksi pada materialization terakhir';
            $f->save();
            $autoResolved++;
        }

        return [
            'created' => $created,
            'bumped' => $bumped,
            'auto_resolved' => $autoResolved,
            'candidates' => count($candidates),
        ];
    }

    private function severityRank(string $sev): int
    {
        return match ($sev) {
            'critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, default => 0,
        };
    }

    // ─── Detectors ───────────────────────────────────────────────────────

    /**
     * PII column dengan kategori spesifik (Pasal 4) tapi tanpa
     * protection_assessment (encryption / access_control / masking /
     * tokenization). Critical severity karena Pasal 4 + Pasal 39.
     */
    private function detectSensitiveProtection(string $orgId): array
    {
        $candidates = [];
        $systems = InformationSystem::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('scan_results')
            ->get(['id', 'name', 'scan_results', 'protection_assessments']);

        foreach ($systems as $sys) {
            $tables = $sys->scan_results['tables'] ?? [];
            $protections = $sys->protection_assessments ?? [];
            foreach ($tables as $t) {
                $tableName = $t['name'] ?? '';
                foreach (($t['columns'] ?? []) as $c) {
                    if (($c['pdp_category'] ?? null) !== 'spesifik') {
                        continue;
                    }
                    $colName = $c['name'] ?? '';
                    $key = "{$tableName}.{$colName}";
                    $a = $protections[$key] ?? null;
                    $protected = $a && (
                        ! empty($a['encryption']) || ! empty($a['access_control']) ||
                        ! empty($a['masking']) || ! empty($a['tokenization'])
                    );
                    if (! $protected) {
                        $candidates[] = [
                            'source_pillar' => 'sensitive_protection',
                            'source_key' => "sensitive_protection:{$sys->id}:{$key}",
                            'source_type' => 'information_system',
                            'source_id' => $sys->id,
                            'source_detail' => "{$sys->name} · {$key}",
                            'severity' => 'critical',
                            'title' => "Data spesifik tanpa kontrol: {$key}",
                            'description' => "Kolom {$key} di sistem {$sys->name} terdeteksi sebagai data spesifik (UU PDP Pasal 4 ayat 2) — ".($c['reason'] ?? 'PII spesifik').'. Belum ada protection assessment (enkripsi, kontrol akses, masking, atau tokenisasi) yang tercatat.',
                            'regulation_ref' => 'UU PDP Pasal 4 ayat 2 + Pasal 39',
                            'metadata' => [
                                'system_id' => $sys->id,
                                'table' => $tableName,
                                'column' => $colName,
                                'pii_reason' => $c['reason'] ?? null,
                            ],
                        ];
                    }
                }
            }
        }

        return $candidates;
    }

    /**
     * PII column biasa (umum) tanpa assessment apapun. High severity.
     */
    private function detectClassificationCoverage(string $orgId): array
    {
        $candidates = [];
        $systems = InformationSystem::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('scan_results')
            ->get(['id', 'name', 'scan_results', 'protection_assessments']);

        foreach ($systems as $sys) {
            $tables = $sys->scan_results['tables'] ?? [];
            $protections = $sys->protection_assessments ?? [];
            foreach ($tables as $t) {
                $tableName = $t['name'] ?? '';
                foreach (($t['columns'] ?? []) as $c) {
                    // Skip spesifik (covered by detectSensitiveProtection)
                    if (($c['pdp_category'] ?? null) === 'spesifik') {
                        continue;
                    }
                    if (! ($c['pii_detected'] ?? false)) {
                        continue;
                    }

                    $colName = $c['name'] ?? '';
                    $key = "{$tableName}.{$colName}";
                    if (empty($protections[$key])) {
                        $candidates[] = [
                            'source_pillar' => 'classification_coverage',
                            'source_key' => "classification_coverage:{$sys->id}:{$key}",
                            'source_type' => 'information_system',
                            'source_id' => $sys->id,
                            'source_detail' => "{$sys->name} · {$key}",
                            'severity' => 'high',
                            'title' => "PII belum di-assess: {$key}",
                            'description' => "Kolom {$key} terdeteksi PII (".($c['reason'] ?? 'PII umum').') tapi belum diisi protection assessment.',
                            'regulation_ref' => 'UU PDP Pasal 39',
                            'metadata' => ['system_id' => $sys->id, 'table' => $tableName, 'column' => $colName],
                        ];
                    }
                }
            }
        }

        return $candidates;
    }

    /**
     * Schema drift alerts dari scan_results (e.g. "PII column added to
     * users table"). Each alert = high severity finding.
     */
    private function detectSchemaDrift(string $orgId): array
    {
        $candidates = [];
        $systems = InformationSystem::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('scan_results')
            ->get(['id', 'name', 'scan_results', 'last_scanned_at']);

        foreach ($systems as $sys) {
            $alerts = $sys->scan_results['diff_alerts'] ?? [];
            foreach ($alerts as $i => $alert) {
                $isCritical = stripos((string) $alert, 'WARNING') !== false || stripos((string) $alert, 'PII') !== false;
                $candidates[] = [
                    'source_pillar' => 'schema_drift',
                    'source_key' => "schema_drift:{$sys->id}:".md5((string) $alert),
                    'source_type' => 'information_system',
                    'source_id' => $sys->id,
                    'source_detail' => $sys->name,
                    'severity' => $isCritical ? 'critical' : 'medium',
                    'title' => 'Schema drift: '.substr((string) $alert, 0, 80),
                    'description' => (string) $alert,
                    'regulation_ref' => 'UU PDP Pasal 39',
                    'metadata' => ['system_id' => $sys->id, 'detected_at' => optional($sys->last_scanned_at)->toIso8601String()],
                ];
            }
        }

        return $candidates;
    }

    /**
     * RoPA HIGH-risk tanpa DPIA approved (Pasal 35).
     */
    private function detectDpiaCompliance(string $orgId): array
    {
        $candidates = [];
        $rows = Ropa::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->where('risk_level', 'high')
            ->get(['id', 'registration_number', 'processing_activity']);

        foreach ($rows as $r) {
            $hasApproved = Dpia::query()->withoutGlobalScope('org')
                ->where('ropa_id', $r->id)
                ->where('status', 'approved')
                ->whereNull('deleted_at')
                ->exists();
            if (! $hasApproved) {
                $candidates[] = [
                    'source_pillar' => 'dpia_compliance',
                    'source_key' => "dpia_compliance:{$r->id}",
                    'source_type' => 'ropa',
                    'source_id' => $r->id,
                    'source_detail' => $r->registration_number,
                    'severity' => 'high',
                    'title' => "DPIA wajib untuk RoPA HIGH-risk: {$r->registration_number}",
                    'description' => "RoPA HIGH-risk '{$r->processing_activity}' belum punya DPIA approved. UU PDP Pasal 35 mensyaratkan Penilaian Dampak Perlindungan Data untuk pemrosesan berisiko tinggi.",
                    'regulation_ref' => 'UU PDP Pasal 35',
                    'metadata' => ['ropa_id' => $r->id],
                ];
            }
        }

        return $candidates;
    }

    /**
     * RTP items overdue from Dpia.mitigation_tracking.
     */
    private function detectRtpHygiene(string $orgId): array
    {
        $candidates = [];
        $today = Carbon::today();
        $dpias = Dpia::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('mitigation_tracking')
            ->get(['id', 'registration_number', 'mitigation_tracking']);

        foreach ($dpias as $d) {
            foreach (($d->mitigation_tracking ?? []) as $idx => $it) {
                $status = $it['status'] ?? 'planned';
                if (in_array($status, ['verified', 'cancelled', 'on_hold'], true)) {
                    continue;
                }
                if (empty($it['due_date'])) {
                    continue;
                }
                try {
                    $due = Carbon::parse($it['due_date']);
                    if (! $due->lt($today)) {
                        continue;
                    }
                } catch (Throwable $e) {
                    continue;
                }

                $daysOverdue = (int) $today->diffInDays($due);
                $sev = $daysOverdue >= 30 ? 'critical' : ($daysOverdue >= 7 ? 'high' : 'medium');
                $itemKey = $it['id'] ?? "idx_{$idx}";

                // RTP item (Dpia::buildRtpItem) TIDAK punya key 'title'/'description'.
                // Label risiko = 'risk_event'; rencana kerja = 'action'; catatan = 'notes'.
                $label = trim((string) ($it['risk_event'] ?? ''));
                if ($label === '') {
                    $label = 'mitigation item';
                }
                // title/source_detail = varchar(255); risk_event bisa sampai 500 char.
                $label = mb_substr($label, 0, 160);
                $detail = trim((string) ($it['action'] ?? ''));
                if ($detail === '') {
                    $detail = trim((string) ($it['notes'] ?? ''));
                }
                if ($detail === '') {
                    $detail = 'Risk treatment item lewat deadline.';
                }

                $candidates[] = [
                    'source_pillar' => 'rtp_hygiene',
                    'source_key' => "rtp_hygiene:{$d->id}:{$itemKey}",
                    'source_type' => 'dpia',
                    'source_id' => $d->id,
                    'source_detail' => "{$d->registration_number} · ".$label,
                    'severity' => $sev,
                    'title' => "RTP overdue {$daysOverdue} hari: ".$label,
                    'description' => $detail.' · Due: '.$due->format('d M Y'),
                    'regulation_ref' => 'UU PDP Pasal 35',
                    'metadata' => ['dpia_id' => $d->id, 'item_index' => $idx, 'days_overdue' => $daysOverdue],
                ];
            }
        }

        return $candidates;
    }

    /**
     * Vendor overdue for re-assessment (Phase 2 next_assessment_due_at).
     */
    private function detectVendorRisk(string $orgId): array
    {
        $candidates = [];
        $vendors = Vendor::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('next_assessment_due_at')
            ->where('next_assessment_due_at', '<', now()->toDateString())
            ->get(['id', 'name', 'risk_level', 'next_assessment_due_at']);

        foreach ($vendors as $v) {
            $daysOverdue = (int) now()->diffInDays($v->next_assessment_due_at);
            $sev = match ($v->risk_level) {
                'critical' => 'critical',
                'high' => 'high',
                default => 'medium',
            };
            $candidates[] = [
                'source_pillar' => 'vendor_risk',
                'source_key' => "vendor_risk:{$v->id}",
                'source_type' => 'vendor',
                'source_id' => $v->id,
                'source_detail' => $v->name,
                'severity' => $sev,
                'title' => "Vendor overdue re-assess {$daysOverdue} hari: {$v->name}",
                'description' => "Vendor {$v->name} (risk_level={$v->risk_level}) sudah lewat jadwal re-assessment {$daysOverdue} hari. UU PDP Pasal 51 mensyaratkan kontrol berkelanjutan atas prosesor.",
                'regulation_ref' => 'UU PDP Pasal 51',
                'metadata' => ['vendor_id' => $v->id, 'days_overdue' => $daysOverdue],
            ];
        }

        return $candidates;
    }

    /**
     * Cross-border transfer dengan legal_basis = none atau null.
     */
    private function detectCrossBorderBasis(string $orgId): array
    {
        $candidates = [];
        $rows = CrossBorderTransfer::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('legal_basis')->orWhere('legal_basis', 'none')->orWhere('legal_basis', '');
            })
            ->get(['id', 'destination_entity', 'destination_country']);

        foreach ($rows as $cbt) {
            $candidates[] = [
                'source_pillar' => 'cross_border_basis',
                'source_key' => "cross_border_basis:{$cbt->id}",
                'source_type' => 'cross_border_transfer',
                'source_id' => $cbt->id,
                'source_detail' => "{$cbt->destination_entity} ({$cbt->destination_country})",
                'severity' => 'critical',
                'title' => "Transfer tanpa dasar hukum: {$cbt->destination_entity}",
                'description' => "Cross-border transfer ke {$cbt->destination_entity} di {$cbt->destination_country} belum punya legal_basis Pasal 56 yang valid. Tanpa adequacy/SCCs/BCR/persetujuan eksplisit, transfer ini berisiko ilegal.",
                'regulation_ref' => 'UU PDP Pasal 56',
                'metadata' => ['cross_border_id' => $cbt->id],
            ];
        }

        return $candidates;
    }

    /**
     * Active breach yang belum di-notify dalam 72h (Pasal 46).
     */
    private function detectBreachReadiness(string $orgId): array
    {
        $candidates = [];
        $rows = BreachIncident::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotIn('status', ['closed', 'resolved'])
            ->whereNotNull('detected_at')
            ->get(['id', 'incident_code', 'title', 'detected_at', 'notified_komdigi_at']);

        foreach ($rows as $b) {
            $hoursOpen = (int) Carbon::parse($b->detected_at)->diffInHours(now());
            $notified = ! empty($b->notified_komdigi_at);
            $sev = $hoursOpen > 72 && ! $notified ? 'critical' : ($hoursOpen > 48 && ! $notified ? 'high' : 'medium');
            $candidates[] = [
                'source_pillar' => 'breach_readiness',
                'source_key' => "breach_readiness:{$b->id}",
                'source_type' => 'breach_incident',
                'source_id' => $b->id,
                'source_detail' => $b->incident_code,
                'severity' => $sev,
                'title' => "Breach aktif {$hoursOpen}h".($notified ? '' : ' (belum notif KOMDIGI)').": {$b->incident_code}",
                'description' => "Breach {$b->incident_code} ('{$b->title}') terdeteksi {$hoursOpen} jam yang lalu. ".($notified ? 'Sudah notif KOMDIGI.' : 'BELUM notif KOMDIGI — Pasal 46 batas 72h.'),
                'regulation_ref' => 'UU PDP Pasal 46',
                'metadata' => ['breach_id' => $b->id, 'hours_open' => $hoursOpen, 'notified_komdigi' => $notified],
            ];
        }

        return $candidates;
    }

    /**
     * DSR closed setelah deadline_at lewat.
     */
    private function detectDsrCompliance(string $orgId): array
    {
        $candidates = [];
        $rows = DsrRequest::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->where('status', 'closed')
            ->whereNotNull('closed_at')->whereNotNull('deadline_at')
            ->whereColumn('closed_at', '>', 'deadline_at')
            ->where('closed_at', '>=', now()->subDays(60))   // only surface recent ones
            ->get(['id', 'request_id', 'closed_at', 'deadline_at', 'request_type']);

        foreach ($rows as $d) {
            $hoursLate = (int) Carbon::parse($d->deadline_at)->diffInHours(Carbon::parse($d->closed_at));
            $candidates[] = [
                'source_pillar' => 'dsr_compliance',
                'source_key' => "dsr_compliance:{$d->id}",
                'source_type' => 'dsr_request',
                'source_id' => $d->id,
                'source_detail' => $d->request_id,
                'severity' => $hoursLate > 72 ? 'high' : 'medium',
                'title' => "DSR telat {$hoursLate}h: {$d->request_id}",
                'description' => "DSR {$d->request_id} (type={$d->request_type}) ditutup {$hoursLate} jam setelah deadline. UU PDP Pasal 8-10 mensyaratkan respons hak subjek dalam waktu yang reasonable.",
                'regulation_ref' => 'UU PDP Pasal 8-10',
                'metadata' => ['dsr_id' => $d->id, 'hours_late' => $hoursLate],
            ];
        }

        return $candidates;
    }

    /**
     * Phase 3c — Excessive access on tables yang punya PII columns.
     *
     * Heuristic: any non-admin/dpo/app role that has SELECT or higher on
     * a table with pii_detected=true columns gets a finding. Severity
     * scales with privilege type:
     *   - DELETE / TRUNCATE / DROP on PII table  → critical
     *   - UPDATE / INSERT on PII table           → high
     *   - SELECT on Pasal 4 spesifik table       → high
     *   - SELECT on PII umum table               → medium
     */
    private function detectExcessiveAccess(string $orgId): array
    {
        $candidates = [];
        $systems = InformationSystem::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('scan_results')
            ->get(['id', 'name', 'scan_results']);

        // Roles considered "expected" — these don't trigger findings even
        // with broad privilege. Operator can fine-tune later.
        $expectedRoles = ['postgres', 'root', 'admin', 'dba', 'app', 'application', 'service_account'];
        $expectedPattern = '/^('.implode('|', $expectedRoles).')(@|_|$)/i';

        foreach ($systems as $sys) {
            $accessPaths = $sys->scan_results['access_paths'] ?? null;
            $tables = $sys->scan_results['tables'] ?? [];
            if (! $accessPaths || empty($accessPaths['grants'])) {
                continue;
            }

            // Build map: table_name → has spesifik PII / has umum PII
            $tablePii = [];
            foreach ($tables as $t) {
                $tableName = $t['name'] ?? '';
                $hasSpesifik = false;
                $hasPii = false;
                foreach (($t['columns'] ?? []) as $c) {
                    if ($c['pii_detected'] ?? false) {
                        $hasPii = true;
                    }
                    if (($c['pdp_category'] ?? null) === 'spesifik') {
                        $hasSpesifik = true;
                    }
                }
                if ($hasPii) {
                    $tablePii[$tableName] = ['spesifik' => $hasSpesifik, 'pii' => true];
                }
            }

            // Group grants per (grantee, table) to summarize privileges
            $byUserTable = [];
            foreach ($accessPaths['grants'] as $g) {
                $grantee = $g['grantee'];
                $table = $g['table'];
                $priv = strtoupper((string) $g['privilege']);
                if (! isset($tablePii[$table])) {
                    continue;
                }                  // not a PII table
                if (preg_match($expectedPattern, $grantee)) {
                    continue;
                }      // expected role
                $key = $grantee.'|'.$table;
                $byUserTable[$key] ??= ['grantee' => $grantee, 'table' => $table, 'privileges' => []];
                $byUserTable[$key]['privileges'][] = $priv;
            }

            foreach ($byUserTable as $entry) {
                $privs = array_values(array_unique($entry['privileges']));
                $hasWrite = (bool) array_intersect(['DELETE', 'TRUNCATE', 'DROP'], $privs);
                $hasModify = (bool) array_intersect(['UPDATE', 'INSERT'], $privs);
                $hasSelect = in_array('SELECT', $privs, true);
                if (! $hasWrite && ! $hasModify && ! $hasSelect) {
                    continue;
                }

                $isSpesifik = $tablePii[$entry['table']]['spesifik'];
                $severity = match (true) {
                    $hasWrite => 'critical',
                    $hasModify => 'high',
                    $isSpesifik => 'high',
                    default => 'medium',
                };

                $privList = implode(', ', $privs);
                $candidates[] = [
                    'source_pillar' => 'access_path',
                    'source_key' => "access_path:{$sys->id}:{$entry['grantee']}:{$entry['table']}",
                    'source_type' => 'information_system',
                    'source_id' => $sys->id,
                    'source_detail' => "{$sys->name} · {$entry['grantee']} → {$entry['table']}",
                    'severity' => $severity,
                    'title' => "Akses berlebih: {$entry['grantee']} punya {$privList} pada {$entry['table']}",
                    'description' => "User '{$entry['grantee']}' punya privilege [{$privList}] pada tabel '{$entry['table']}' yang mengandung ".($isSpesifik ? 'data spesifik (Pasal 4 ayat 2)' : 'PII')." di sistem '{$sys->name}'. Verifikasi kebutuhan akses dan terapkan least-privilege.",
                    'regulation_ref' => 'UU PDP Pasal 39 + ISO 27001 A.9.4',
                    'metadata' => [
                        'system_id' => $sys->id,
                        'grantee' => $entry['grantee'],
                        'table' => $entry['table'],
                        'privileges' => $privs,
                        'is_spesifik' => $isSpesifik,
                    ],
                ];
            }
        }

        return $candidates;
    }

    /**
     * Phase 3c — Encryption gaps. Sensitive table tanpa signal enkripsi.
     *
     * Triggers:
     *   - Postgres: tabel punya kolom PII spesifik DAN tidak ada kolom
     *     bytea-encrypted DAN ssl_in_use=false → critical
     *   - MySQL: tabel punya kolom PII spesifik DAN tablespace.encrypted=false
     *     DAN tidak ada column-level encryption → critical
     *   - Either: PII umum tanpa SSL connection → medium
     */
    private function detectEncryptionGaps(string $orgId): array
    {
        $candidates = [];
        $systems = InformationSystem::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('scan_results')
            ->get(['id', 'name', 'scan_results']);

        foreach ($systems as $sys) {
            $enc = $sys->scan_results['encryption'] ?? null;
            $tables = $sys->scan_results['tables'] ?? [];
            if (! $enc) {
                continue;
            }   // encryption scan didn't run for this engine

            $colEncTables = array_flip($enc['column_encryption_observed']['tables'] ?? []);
            $tablespaceMap = [];
            foreach (($enc['tablespace_encryption'] ?? []) as $ts) {
                $tablespaceMap[$ts['table']] = $ts['encrypted'];   // true | false | null
            }
            $sslInUse = $enc['ssl_in_use'] ?? null;

            foreach ($tables as $t) {
                $tableName = $t['name'] ?? '';
                $hasSpesifik = false;
                $hasPii = false;
                foreach (($t['columns'] ?? []) as $c) {
                    if ($c['pii_detected'] ?? false) {
                        $hasPii = true;
                    }
                    if (($c['pdp_category'] ?? null) === 'spesifik') {
                        $hasSpesifik = true;
                    }
                }
                if (! $hasPii) {
                    continue;
                }

                $tablespaceEncrypted = $tablespaceMap[$tableName] ?? null;
                $hasColEnc = isset($colEncTables[$tableName]);

                // No signal of any encryption + has spesifik PII = critical
                if ($hasSpesifik && $tablespaceEncrypted === false && ! $hasColEnc) {
                    $candidates[] = [
                        'source_pillar' => 'encryption_at_rest',
                        'source_key' => "encryption_at_rest:{$sys->id}:{$tableName}",
                        'source_type' => 'information_system',
                        'source_id' => $sys->id,
                        'source_detail' => "{$sys->name} · {$tableName}",
                        'severity' => 'critical',
                        'title' => "Tabel data spesifik tanpa enkripsi: {$tableName}",
                        'description' => "Tabel '{$tableName}' di sistem '{$sys->name}' mengandung data spesifik (Pasal 4 ayat 2) tapi tablespace tidak terenkripsi dan tidak ditemukan kolom dengan enkripsi field-level (heuristik *_enc/*_encrypted). Pasal 39 UU PDP mensyaratkan pengamanan teknis.",
                        'regulation_ref' => 'UU PDP Pasal 4 ayat 2 + Pasal 39',
                        'metadata' => [
                            'system_id' => $sys->id,
                            'table' => $tableName,
                            'tablespace_encrypted' => $tablespaceEncrypted,
                            'column_encryption_observed' => $hasColEnc,
                            'ssl_in_use' => $sslInUse,
                        ],
                    ];
                } elseif ($hasPii && $sslInUse === false) {
                    // SSL not enforced + PII = medium
                    $candidates[] = [
                        'source_pillar' => 'encryption_at_rest',
                        'source_key' => "encryption_at_rest:{$sys->id}:{$tableName}:ssl",
                        'source_type' => 'information_system',
                        'source_id' => $sys->id,
                        'source_detail' => "{$sys->name} · {$tableName}",
                        'severity' => 'medium',
                        'title' => "Koneksi tanpa SSL/TLS: {$sys->name}",
                        'description' => "Sistem '{$sys->name}' tidak menggunakan SSL/TLS untuk koneksi database. Tabel '{$tableName}' yang punya PII jadi rentan eavesdropping di network.",
                        'regulation_ref' => 'POJK 11/2022 Pasal 27',
                        'metadata' => [
                            'system_id' => $sys->id,
                            'table' => $tableName,
                            'ssl_in_use' => false,
                        ],
                    ];
                }
            }
        }

        return $candidates;
    }
}
