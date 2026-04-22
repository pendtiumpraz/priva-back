<?php

namespace Database\Seeders;

use App\Models\ContainmentTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds 15 standard cybersecurity incident containment templates.
 * Each row is a system default (org_id=null, is_system=true) that tenants
 * can copy + customize. Every step declares whether evidence is required —
 * for compliance audits (UU PDP + ISO 27035) evidence is mandatory on
 * critical actions.
 */
class ContainmentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::TEMPLATES as $tpl) {
            ContainmentTemplate::updateOrCreate(
                ['org_id' => null, 'case_type' => $tpl['case_type'], 'is_system' => true],
                [
                    'label' => $tpl['label'],
                    'description' => $tpl['description'],
                    'steps' => $tpl['steps'],
                    'is_default' => true,
                ]
            );
        }
    }

    // ────────────────────────────────────────────────────────────────
    // 15 CASE TYPES with tailored containment SOPs
    // ────────────────────────────────────────────────────────────────
    private const TEMPLATES = [
        // ──── 1. RANSOMWARE ────
        [
            'case_type' => 'ransomware',
            'label' => 'Ransomware Attack — Standard SOP',
            'description' => 'Sistem terenkripsi oleh ransomware. Prioritas: isolasi + preservasi evidence + ASSESS damage sebelum pertimbangan dekripsi/restore.',
            'steps' => [
                ['key' => 'r_isolate', 'label' => 'Isolasi sistem terdampak dari jaringan (disconnect LAN/WiFi)', 'category' => 'isolation', 'requires_evidence' => true, 'hint' => 'Foto kabel tercabut / screenshot firewall rule'],
                ['key' => 'r_preserve', 'label' => 'Preserve forensic evidence: snapshot RAM, disk image, log', 'category' => 'forensics', 'requires_evidence' => true, 'hint' => 'Hash file image (SHA-256) + chain-of-custody log'],
                ['key' => 'r_notify_internal', 'label' => 'Notifikasi internal: DPO, CISO, Direksi', 'category' => 'communication', 'requires_evidence' => false],
                ['key' => 'r_identify_strain', 'label' => 'Identifikasi varian ransomware (pakai ID Ransomware service / signature)', 'category' => 'analysis', 'requires_evidence' => true, 'hint' => 'Screenshot ransom note + hash sample malware'],
                ['key' => 'r_scope', 'label' => 'Tentukan scope: sistem mana saja yang ter-encrypt, data apa saja', 'category' => 'assessment', 'requires_evidence' => true, 'hint' => 'Daftar hostname + share path + kategori data (PII/Keuangan/Kesehatan)'],
                ['key' => 'r_backup_check', 'label' => 'Verifikasi ketersediaan backup clean + integritas (test restore ke sandbox)', 'category' => 'recovery', 'requires_evidence' => true, 'hint' => 'Log hasil test restore + hash match'],
                ['key' => 'r_law_decision', 'label' => 'Decision point: LAPOR POLRI (cyber crime)?', 'category' => 'legal', 'requires_evidence' => true, 'hint' => 'Surat lapor polisi jika diputuskan lapor'],
                ['key' => 'r_no_pay', 'label' => 'KONFIRMASI: tidak membayar ransom (kecuali ada legal opinion khusus)', 'category' => 'legal', 'requires_evidence' => false, 'hint' => 'Payment ransom mendorong kejahatan + tidak menjamin decryption'],
                ['key' => 'r_eradicate', 'label' => 'Eradication: remove malware, patch entry point, reset credentials', 'category' => 'eradication', 'requires_evidence' => true, 'hint' => 'Log AV scan + patch KB number + password rotation audit'],
                ['key' => 'r_restore', 'label' => 'Restore dari backup clean (verifikasi integritas setelah restore)', 'category' => 'recovery', 'requires_evidence' => true, 'hint' => 'Checksum compare pre/post restore'],
                ['key' => 'r_monitor', 'label' => 'Monitoring intensif 30 hari (EDR alert review harian)', 'category' => 'monitoring', 'requires_evidence' => true, 'hint' => 'Dashboard screenshot + incident log'],
                ['key' => 'r_postmortem', 'label' => 'Post-incident review + lessons learned doc', 'category' => 'closure', 'requires_evidence' => true, 'hint' => 'Dokumen PIR ditandatangani DPO+CISO'],
            ],
        ],
        // ──── 2. PHISHING / SOCIAL ENGINEERING ────
        [
            'case_type' => 'phishing',
            'label' => 'Phishing / Social Engineering — Standard SOP',
            'description' => 'Serangan phishing via email/SMS/WhatsApp berhasil/hampir berhasil. Fokus: scope kompromi kredensial + awareness training.',
            'steps' => [
                ['key' => 'p_harvest_artifacts', 'label' => 'Kumpulkan artefak phishing: email header (raw), URL, attachment', 'category' => 'forensics', 'requires_evidence' => true, 'hint' => 'Forward email as .eml + screenshot'],
                ['key' => 'p_block_sender', 'label' => 'Block sender + domain di email gateway / firewall', 'category' => 'isolation', 'requires_evidence' => true, 'hint' => 'Screenshot blocklist update'],
                ['key' => 'p_block_url', 'label' => 'Block URL phishing di web proxy / DNS sinkhole', 'category' => 'isolation', 'requires_evidence' => true],
                ['key' => 'p_identify_victims', 'label' => 'Identifikasi user yang membuka link / memasukkan credential', 'category' => 'assessment', 'requires_evidence' => true, 'hint' => 'Log web proxy + email open tracking'],
                ['key' => 'p_force_reset', 'label' => 'Force password reset untuk user yang terpapar', 'category' => 'remediation', 'requires_evidence' => true, 'hint' => 'Screenshot bulk reset di IAM / AD'],
                ['key' => 'p_enable_mfa', 'label' => 'Enable/enforce MFA untuk akun terdampak', 'category' => 'remediation', 'requires_evidence' => true],
                ['key' => 'p_check_mail_rules', 'label' => 'Periksa apakah attacker membuat mail-forwarding rule di akun korban', 'category' => 'forensics', 'requires_evidence' => true, 'hint' => 'Screenshot mailbox rules audit'],
                ['key' => 'p_review_logins', 'label' => 'Review login history 30 hari terakhir (anomalous location/IP)', 'category' => 'forensics', 'requires_evidence' => true],
                ['key' => 'p_alert_all_users', 'label' => 'Broadcast alert ke seluruh karyawan (pesan phishing + cara lapor)', 'category' => 'communication', 'requires_evidence' => true, 'hint' => 'Screenshot broadcast email / Slack'],
                ['key' => 'p_refresher_training', 'label' => 'Schedule mandatory awareness training untuk korban + team mereka', 'category' => 'prevention', 'requires_evidence' => true, 'hint' => 'Training attendance log'],
                ['key' => 'p_simulate', 'label' => 'Simulasi phishing kontrol 30 hari setelah insiden untuk ukur improvement', 'category' => 'prevention', 'requires_evidence' => false],
            ],
        ],
        // ──── 3. UNAUTHORIZED ACCESS ────
        [
            'case_type' => 'unauthorized_access',
            'label' => 'Unauthorized Access / Credential Compromise — Standard SOP',
            'description' => 'Akses tidak sah terdeteksi (login dari IP asing, akun stale, dll). Prioritas: revoke akses + forensik aktivitas.',
            'steps' => [
                ['key' => 'u_lock_account', 'label' => 'Lock/disable akun terduga compromised', 'category' => 'isolation', 'requires_evidence' => true, 'hint' => 'Screenshot account disabled di IAM'],
                ['key' => 'u_revoke_tokens', 'label' => 'Revoke semua active session + API token', 'category' => 'isolation', 'requires_evidence' => true],
                ['key' => 'u_preserve_logs', 'label' => 'Preserve auth logs (min. 90 hari ke belakang) untuk forensik', 'category' => 'forensics', 'requires_evidence' => true, 'hint' => 'Log export + SHA-256 hash'],
                ['key' => 'u_scope_access', 'label' => 'Tentukan scope akses: resource apa saja yang diakses selama window kompromi', 'category' => 'assessment', 'requires_evidence' => true, 'hint' => 'SIEM query result / audit log'],
                ['key' => 'u_data_accessed', 'label' => 'Identifikasi data yang di-read/download (row count, tabel, kategori PII)', 'category' => 'assessment', 'requires_evidence' => true, 'hint' => 'DB query log + file access log'],
                ['key' => 'u_check_backdoor', 'label' => 'Periksa apakah attacker menanam backdoor (scheduled task, new user, startup script)', 'category' => 'forensics', 'requires_evidence' => true],
                ['key' => 'u_reset_creds', 'label' => 'Reset password + rotate API keys untuk akun terdampak', 'category' => 'remediation', 'requires_evidence' => true],
                ['key' => 'u_enforce_mfa', 'label' => 'Enforce MFA kalau belum aktif', 'category' => 'remediation', 'requires_evidence' => true],
                ['key' => 'u_review_privileges', 'label' => 'Review privilege level akun — over-privileged? Apply least-privilege', 'category' => 'remediation', 'requires_evidence' => true, 'hint' => 'Before/after RBAC matrix'],
                ['key' => 'u_monitor_anomaly', 'label' => 'Enable extra monitoring untuk akun + user peer group selama 30 hari', 'category' => 'monitoring', 'requires_evidence' => true],
            ],
        ],
        // ──── 4. INSIDER THREAT (malicious) ────
        [
            'case_type' => 'insider_threat',
            'label' => 'Insider Threat (Malicious) — Standard SOP',
            'description' => 'Karyawan/kontraktor dengan akses sah diduga/terbukti melakukan exfiltrasi data sengaja. Hati-hati: ranah HR + legal + teknis.',
            'steps' => [
                ['key' => 'i_hr_coord', 'label' => 'Koordinasi awal dengan HR + Legal (JANGAN komunikasi langsung ke pelaku)', 'category' => 'communication', 'requires_evidence' => true, 'hint' => 'Meeting minutes + signed confidentiality'],
                ['key' => 'i_preserve_covert', 'label' => 'Preserve evidence secara covert (pelaku tidak boleh tahu sedang diinvestigasi)', 'category' => 'forensics', 'requires_evidence' => true],
                ['key' => 'i_activity_baseline', 'label' => 'Pull 90-day activity log: file access, USB, print, email external', 'category' => 'assessment', 'requires_evidence' => true, 'hint' => 'DLP + EDR + proxy log'],
                ['key' => 'i_anomaly_analysis', 'label' => 'Identifikasi anomali dari baseline: volume, destinasi, tipe data', 'category' => 'analysis', 'requires_evidence' => true],
                ['key' => 'i_consult_legal', 'label' => 'Konsultasi dengan legal counsel sebelum action HR', 'category' => 'legal', 'requires_evidence' => true, 'hint' => 'Legal opinion memo'],
                ['key' => 'i_revoke_access', 'label' => 'Revoke akses (IAM disable, badge deactivate, MDM wipe) — koordinir timing dengan HR', 'category' => 'remediation', 'requires_evidence' => true],
                ['key' => 'i_asset_recovery', 'label' => 'Recover corporate assets: laptop, phone, USB drive, token', 'category' => 'remediation', 'requires_evidence' => true, 'hint' => 'Asset return checklist signed'],
                ['key' => 'i_interview', 'label' => 'Investigative interview (dengan HR + Legal hadir)', 'category' => 'investigation', 'requires_evidence' => true, 'hint' => 'Transcript + ditandatangani'],
                ['key' => 'i_report_apparatus', 'label' => 'Decision: lapor ke aparat hukum (POLRI) jika ada indikasi pidana', 'category' => 'legal', 'requires_evidence' => true],
                ['key' => 'i_policy_gap', 'label' => 'Review policy gap: apakah akses berlebih? DLP gagal detect?', 'category' => 'prevention', 'requires_evidence' => true],
            ],
        ],
        // ──── 5. INSIDER ERROR (accidental) ────
        [
            'case_type' => 'insider_error',
            'label' => 'Insider Error / Accidental Disclosure — Standard SOP',
            'description' => 'Karyawan secara tidak sengaja men-share data (salah kirim email, CC publik, upload ke share yg salah). Fokus: recovery + training.',
            'steps' => [
                ['key' => 'ie_confirm_incident', 'label' => 'Konfirmasi kronologi: apa yang ter-share, ke siapa, kapan', 'category' => 'assessment', 'requires_evidence' => true, 'hint' => 'Screenshot email terkirim / log share'],
                ['key' => 'ie_recall_attempt', 'label' => 'Attempt recall email (Outlook recall) / revoke share link', 'category' => 'remediation', 'requires_evidence' => true],
                ['key' => 'ie_contact_recipient', 'label' => 'Hubungi penerima: minta hapus + konfirmasi tertulis tidak diforward', 'category' => 'communication', 'requires_evidence' => true, 'hint' => 'Email/surat konfirmasi delete'],
                ['key' => 'ie_check_opened', 'label' => 'Cek apakah email dibuka / attachment didownload (jika ada tracking)', 'category' => 'assessment', 'requires_evidence' => true],
                ['key' => 'ie_data_scope', 'label' => 'Identifikasi kategori + jumlah data yang ter-share (untuk decision notifikasi PDP)', 'category' => 'assessment', 'requires_evidence' => true],
                ['key' => 'ie_apply_dlp', 'label' => 'Apply/tighten DLP rule untuk mencegah kejadian serupa', 'category' => 'prevention', 'requires_evidence' => true],
                ['key' => 'ie_refresher_training', 'label' => 'Refresher training untuk user + team', 'category' => 'prevention', 'requires_evidence' => true],
                ['key' => 'ie_policy_update', 'label' => 'Update SOP email eksternal (auto-tag confidential, konfirmasi recipient)', 'category' => 'prevention', 'requires_evidence' => false],
            ],
        ],
        // ──── 6. MISCONFIGURATION ────
        [
            'case_type' => 'misconfiguration',
            'label' => 'Misconfiguration Exposure — Standard SOP',
            'description' => 'Data ter-expose karena config salah: S3 bucket public, database port open, API tanpa auth, dll.',
            'steps' => [
                ['key' => 'm_close_exposure', 'label' => 'Segera tutup exposure: ubah bucket private / firewall block / tambah auth', 'category' => 'isolation', 'requires_evidence' => true, 'hint' => 'Screenshot config sebelum + sesudah'],
                ['key' => 'm_check_access_log', 'label' => 'Review access log untuk identify siapa yang sudah akses', 'category' => 'forensics', 'requires_evidence' => true, 'hint' => 'Log query result: unique IP, object keys accessed'],
                ['key' => 'm_identify_duration', 'label' => 'Tentukan berapa lama exposure berlangsung (dari commit config → sekarang)', 'category' => 'assessment', 'requires_evidence' => true],
                ['key' => 'm_data_exposed', 'label' => 'Inventarisasi data yang exposed: jumlah row/file, kategori PII', 'category' => 'assessment', 'requires_evidence' => true],
                ['key' => 'm_search_leak', 'label' => 'Search data leak di public indexing (Shodan, GrayHat, Google dork)', 'category' => 'investigation', 'requires_evidence' => true, 'hint' => 'Screenshot hasil search untuk arsip'],
                ['key' => 'm_root_cause_config', 'label' => 'RCA: mengapa config misconfig lolos (IaC review? peer review?)', 'category' => 'analysis', 'requires_evidence' => true],
                ['key' => 'm_add_guardrails', 'label' => 'Tambahkan policy-as-code / CSPM rule untuk mencegah ulang', 'category' => 'prevention', 'requires_evidence' => true, 'hint' => 'PR link IaC policy'],
                ['key' => 'm_scan_similar', 'label' => 'Scan seluruh infra untuk misconfig pattern serupa', 'category' => 'prevention', 'requires_evidence' => true],
            ],
        ],
        // ──── 7. PHYSICAL THEFT / LOSS ────
        [
            'case_type' => 'physical_theft',
            'label' => 'Physical Theft / Loss — Standard SOP',
            'description' => 'Laptop/phone/USB/dokumen fisik hilang atau dicuri. Fokus: remote wipe + legal report + audit scope.',
            'steps' => [
                ['key' => 'pt_remote_lock', 'label' => 'Remote lock device via MDM segera', 'category' => 'isolation', 'requires_evidence' => true, 'hint' => 'Screenshot MDM console — status locked'],
                ['key' => 'pt_remote_wipe', 'label' => 'Remote wipe device (jika tidak bisa dikembalikan dalam 24h)', 'category' => 'isolation', 'requires_evidence' => true],
                ['key' => 'pt_police_report', 'label' => 'Lapor POLRI (surat laporan kehilangan/pencurian)', 'category' => 'legal', 'requires_evidence' => true, 'hint' => 'Upload surat laporan polisi'],
                ['key' => 'pt_insurance_claim', 'label' => 'File insurance claim (jika applicable)', 'category' => 'administration', 'requires_evidence' => true],
                ['key' => 'pt_data_inventory', 'label' => 'Inventarisasi data yang ada di device (cached docs, downloaded attachments)', 'category' => 'assessment', 'requires_evidence' => true, 'hint' => 'Log download activity + file indexing'],
                ['key' => 'pt_encryption_check', 'label' => 'Verifikasi device ter-encrypt (FileVault/BitLocker) sebelum hilang', 'category' => 'assessment', 'requires_evidence' => true, 'hint' => 'Compliance report MDM'],
                ['key' => 'pt_revoke_creds', 'label' => 'Revoke semua credential yang cached di device (VPN cert, SSO token)', 'category' => 'remediation', 'requires_evidence' => true],
                ['key' => 'pt_notify_user', 'label' => 'Notifikasi user/karyawan terkait + backup plan (laptop pengganti)', 'category' => 'communication', 'requires_evidence' => false],
                ['key' => 'pt_policy_review', 'label' => 'Review physical security policy (tas dibawa ke mana aja, kunci mobil, dll)', 'category' => 'prevention', 'requires_evidence' => false],
            ],
        ],
        // ──── 8. MALWARE / VIRUS ────
        [
            'case_type' => 'malware',
            'label' => 'Malware / Virus Infection — Standard SOP',
            'description' => 'Sistem terinfeksi malware (non-ransomware): trojan, spyware, cryptominer, botnet client.',
            'steps' => [
                ['key' => 'ml_isolate', 'label' => 'Isolasi host dari jaringan (network quarantine via EDR)', 'category' => 'isolation', 'requires_evidence' => true],
                ['key' => 'ml_sample', 'label' => 'Kumpulkan sample malware untuk analisis (jangan execute di production)', 'category' => 'forensics', 'requires_evidence' => true, 'hint' => 'Upload hash + file sample ke sandbox (Any.Run, Hybrid Analysis)'],
                ['key' => 'ml_identify', 'label' => 'Identifikasi family + behavior (C2 IP, persistence mechanism, data exfil target)', 'category' => 'analysis', 'requires_evidence' => true],
                ['key' => 'ml_block_c2', 'label' => 'Block C2 IP/domain di firewall + DNS', 'category' => 'isolation', 'requires_evidence' => true],
                ['key' => 'ml_scope_infection', 'label' => 'Scan environment: host lain yang terinfeksi?', 'category' => 'assessment', 'requires_evidence' => true, 'hint' => 'EDR sweep result'],
                ['key' => 'ml_data_exfil_check', 'label' => 'Check egress traffic untuk indikasi data exfil', 'category' => 'forensics', 'requires_evidence' => true],
                ['key' => 'ml_remove', 'label' => 'Remove malware (full disk scan + manual verification)', 'category' => 'eradication', 'requires_evidence' => true],
                ['key' => 'ml_reimage', 'label' => 'Re-image device bila kepercayaan rendah (disarankan untuk rootkit)', 'category' => 'eradication', 'requires_evidence' => true],
                ['key' => 'ml_patch', 'label' => 'Patch vulnerability yang jadi entry point', 'category' => 'remediation', 'requires_evidence' => true, 'hint' => 'KB number / CVE + patch confirmation'],
                ['key' => 'ml_monitor', 'label' => 'Enhanced monitoring 30 hari (IOC watchlist aktif)', 'category' => 'monitoring', 'requires_evidence' => true],
            ],
        ],
        // ──── 9. DDoS ────
        [
            'case_type' => 'ddos',
            'label' => 'DDoS / Service Disruption — Standard SOP',
            'description' => 'Serangan denial of service (layer 3/4/7). Fokus: mitigasi + komunikasi eksternal. Biasanya tidak ada data breach.',
            'steps' => [
                ['key' => 'd_confirm', 'label' => 'Konfirmasi DDoS (bukan traffic legitimate surge)', 'category' => 'assessment', 'requires_evidence' => true, 'hint' => 'Traffic pattern chart'],
                ['key' => 'd_identify_type', 'label' => 'Identifikasi jenis: volumetric, protocol (SYN flood), application (L7)', 'category' => 'analysis', 'requires_evidence' => true],
                ['key' => 'd_enable_mitigation', 'label' => 'Aktifkan mitigasi: Cloudflare/AWS Shield/scrubbing service', 'category' => 'isolation', 'requires_evidence' => true, 'hint' => 'Screenshot mitigation mode = on'],
                ['key' => 'd_rate_limit', 'label' => 'Apply rate limiting + geo-block sumber serangan', 'category' => 'isolation', 'requires_evidence' => true],
                ['key' => 'd_contact_isp', 'label' => 'Kontak ISP upstream untuk null-route traffic jahat', 'category' => 'communication', 'requires_evidence' => true],
                ['key' => 'd_comm_status', 'label' => 'Publikasikan status page: service degraded, ETA recovery', 'category' => 'communication', 'requires_evidence' => true],
                ['key' => 'd_preserve_evidence', 'label' => 'Preserve traffic capture + log untuk forensik/lapor polisi', 'category' => 'forensics', 'requires_evidence' => true],
                ['key' => 'd_ransom_check', 'label' => 'Cek apakah ada demand tebusan (ransom DDoS)', 'category' => 'legal', 'requires_evidence' => true],
                ['key' => 'd_capacity_plan', 'label' => 'Post-attack: review kapasitas + autoscaling policy', 'category' => 'prevention', 'requires_evidence' => true],
            ],
        ],
        // ──── 10. SUPPLY CHAIN / THIRD-PARTY ────
        [
            'case_type' => 'supply_chain',
            'label' => 'Supply Chain / Third-party Breach — Standard SOP',
            'description' => 'Vendor / SaaS / third-party breached, data kita ikut terdampak. Fokus: scope impact + vendor coordination + legal.',
            'steps' => [
                ['key' => 'sc_vendor_confirm', 'label' => 'Kontak vendor untuk konfirmasi detail breach + scope data terdampak', 'category' => 'communication', 'requires_evidence' => true, 'hint' => 'Email/surat resmi dari vendor'],
                ['key' => 'sc_data_inventory', 'label' => 'Inventory data kita yang ada di vendor (DPA + data flow diagram)', 'category' => 'assessment', 'requires_evidence' => true],
                ['key' => 'sc_rotate_creds', 'label' => 'Rotate semua credential yang di-share ke vendor (API key, service account)', 'category' => 'remediation', 'requires_evidence' => true],
                ['key' => 'sc_monitor_use', 'label' => 'Monitor penggunaan credential terdampak untuk indikasi abuse', 'category' => 'monitoring', 'requires_evidence' => true],
                ['key' => 'sc_consider_suspend', 'label' => 'Decision: suspend integrasi dengan vendor temporary?', 'category' => 'isolation', 'requires_evidence' => true],
                ['key' => 'sc_legal_review', 'label' => 'Legal: review DPA + SLA — vendor wajib compensate?', 'category' => 'legal', 'requires_evidence' => true, 'hint' => 'Legal opinion memo'],
                ['key' => 'sc_notify_subjects', 'label' => 'Notifikasi subjek data kita yang terdampak (Pasal 46)', 'category' => 'communication', 'requires_evidence' => true],
                ['key' => 'sc_vendor_audit', 'label' => 'Request vendor untuk share RCA + remediation plan tertulis', 'category' => 'investigation', 'requires_evidence' => true],
                ['key' => 'sc_reassess_vendor', 'label' => 'Re-assess vendor risk score (TPRM) — lanjut, monitor ketat, atau putus kontrak', 'category' => 'prevention', 'requires_evidence' => true],
            ],
        ],
        // ──── 11. SQL INJECTION / WEB ATTACK ────
        [
            'case_type' => 'web_attack',
            'label' => 'SQL Injection / Web App Attack — Standard SOP',
            'description' => 'Serangan SQLi, XSS, CSRF, RCE pada aplikasi web. Fokus: close vuln + forensik query/log.',
            'steps' => [
                ['key' => 'w_waf_block', 'label' => 'Enable WAF + block payload signature', 'category' => 'isolation', 'requires_evidence' => true],
                ['key' => 'w_preserve_log', 'label' => 'Preserve web server + app log + DB query log', 'category' => 'forensics', 'requires_evidence' => true],
                ['key' => 'w_identify_payload', 'label' => 'Analisis payload yang berhasil: SQLi, XSS, RCE, dll', 'category' => 'analysis', 'requires_evidence' => true],
                ['key' => 'w_data_exfil_scope', 'label' => 'Query audit log: data apa yang diekstrak attacker?', 'category' => 'assessment', 'requires_evidence' => true, 'hint' => 'DB query log + table access count'],
                ['key' => 'w_patch_vuln', 'label' => 'Patch vulnerability: parameterized query / input sanitization / escape', 'category' => 'remediation', 'requires_evidence' => true, 'hint' => 'PR link fix + code review approval'],
                ['key' => 'w_rotate_secrets', 'label' => 'Rotate DB credential + API key yang terbaca dari exploit', 'category' => 'remediation', 'requires_evidence' => true],
                ['key' => 'w_pentest', 'label' => 'Jalankan pentest ulang untuk validate fix + find related vulns', 'category' => 'prevention', 'requires_evidence' => true],
                ['key' => 'w_review_similar', 'label' => 'Scan code untuk pattern sejenis di module lain', 'category' => 'prevention', 'requires_evidence' => true, 'hint' => 'SAST tool report'],
                ['key' => 'w_notify_auth', 'label' => 'Notifikasi auth vendor (KOMDIGI) jika data PII terekstraksi', 'category' => 'legal', 'requires_evidence' => true],
            ],
        ],
        // ──── 12. ACCOUNT TAKEOVER ────
        [
            'case_type' => 'account_takeover',
            'label' => 'Account Takeover (ATO) — Standard SOP',
            'description' => 'Akun user (customer atau internal) ter-takeover — biasanya via credential stuffing, phishing, atau session hijack.',
            'steps' => [
                ['key' => 'a_lock_account', 'label' => 'Lock akun yang ter-takeover', 'category' => 'isolation', 'requires_evidence' => true],
                ['key' => 'a_revoke_sessions', 'label' => 'Revoke semua session + refresh token', 'category' => 'isolation', 'requires_evidence' => true],
                ['key' => 'a_contact_user', 'label' => 'Hubungi user asli via out-of-band channel (phone, registered email cadangan)', 'category' => 'communication', 'requires_evidence' => true],
                ['key' => 'a_reset_flow', 'label' => 'Guide user lewat reset password + enable MFA', 'category' => 'remediation', 'requires_evidence' => true],
                ['key' => 'a_transaction_review', 'label' => 'Review transaksi/activity yang terjadi saat ter-takeover', 'category' => 'investigation', 'requires_evidence' => true],
                ['key' => 'a_reverse_fraud', 'label' => 'Reverse transaksi fraudulent (jika applicable, koordinasi finance)', 'category' => 'remediation', 'requires_evidence' => true],
                ['key' => 'a_check_pattern', 'label' => 'Scan akun lain yang mungkin terdampak (credential stuffing pattern)', 'category' => 'investigation', 'requires_evidence' => true],
                ['key' => 'a_add_controls', 'label' => 'Tambah kontrol: rate limit login, CAPTCHA, device fingerprint, risk-based auth', 'category' => 'prevention', 'requires_evidence' => true],
            ],
        ],
        // ──── 13. DATA EXFILTRATION ────
        [
            'case_type' => 'data_exfiltration',
            'label' => 'Data Exfiltration — Standard SOP',
            'description' => 'Data sensitif ter-extract keluar (upload ke cloud luar, USB, email bulk). Sering ujung dari serangan lain (insider, malware).',
            'steps' => [
                ['key' => 'e_confirm', 'label' => 'Konfirmasi exfiltration terjadi: DLP alert + egress traffic analysis', 'category' => 'assessment', 'requires_evidence' => true],
                ['key' => 'e_preserve', 'label' => 'Preserve NetFlow/PCAP + endpoint log', 'category' => 'forensics', 'requires_evidence' => true],
                ['key' => 'e_scope_data', 'label' => 'Scope data: berapa volume, tabel/file mana, kategori PII', 'category' => 'assessment', 'requires_evidence' => true],
                ['key' => 'e_identify_actor', 'label' => 'Identifikasi actor: user, system, atau attacker eksternal', 'category' => 'investigation', 'requires_evidence' => true],
                ['key' => 'e_destination', 'label' => 'Identifikasi destinasi exfil: IP, cloud provider, storage service', 'category' => 'investigation', 'requires_evidence' => true],
                ['key' => 'e_takedown', 'label' => 'Takedown request ke destination (jika public cloud/paste site)', 'category' => 'remediation', 'requires_evidence' => true, 'hint' => 'Email abuse report ke provider'],
                ['key' => 'e_legal_law', 'label' => 'Lapor POLRI cyber crime kalau actor eksternal / insider', 'category' => 'legal', 'requires_evidence' => true],
                ['key' => 'e_notify', 'label' => 'Notifikasi KOMDIGI + subjek terdampak sesuai Pasal 46 UU PDP', 'category' => 'legal', 'requires_evidence' => true],
                ['key' => 'e_strengthen_dlp', 'label' => 'Tighten DLP rule untuk cegah pattern serupa', 'category' => 'prevention', 'requires_evidence' => true],
            ],
        ],
        // ──── 14. API ABUSE ────
        [
            'case_type' => 'api_abuse',
            'label' => 'API Abuse / Scraping — Standard SOP',
            'description' => 'API di-abuse: rate limit bypass, enum attack, scraping mass data, BOLA (broken object level auth).',
            'steps' => [
                ['key' => 'ab_block_key', 'label' => 'Revoke API key abuser', 'category' => 'isolation', 'requires_evidence' => true],
                ['key' => 'ab_ip_block', 'label' => 'Block IP/IP range di WAF', 'category' => 'isolation', 'requires_evidence' => true],
                ['key' => 'ab_analyze_pattern', 'label' => 'Analisis pattern call: endpoint, auth, volume, timing', 'category' => 'analysis', 'requires_evidence' => true],
                ['key' => 'ab_scope_data', 'label' => 'Scope data yang ter-extract (row count, unique users)', 'category' => 'assessment', 'requires_evidence' => true],
                ['key' => 'ab_rate_limit', 'label' => 'Tighten rate limiting per-user + per-IP', 'category' => 'remediation', 'requires_evidence' => true],
                ['key' => 'ab_auth_audit', 'label' => 'Audit IDOR / BOLA vulnerability di endpoint terdampak', 'category' => 'analysis', 'requires_evidence' => true],
                ['key' => 'ab_add_monitoring', 'label' => 'Tambah API monitoring: Prometheus alert on anomaly', 'category' => 'prevention', 'requires_evidence' => true],
                ['key' => 'ab_terms_violation', 'label' => 'Decision: laporan terms violation / legal action ke actor', 'category' => 'legal', 'requires_evidence' => true],
            ],
        ],
        // ──── 15. OTHER / GENERIC ────
        [
            'case_type' => 'other',
            'label' => 'Incident Generic Template — Standard SOP',
            'description' => 'Template umum kalau case tidak match kategori lain. Step generic sesuai best-practice incident response.',
            'steps' => [
                ['key' => 'o_identify', 'label' => 'Identifikasi + dokumentasikan insiden', 'category' => 'assessment', 'requires_evidence' => true],
                ['key' => 'o_contain', 'label' => 'Containment: isolasi/hentikan dampak berjalan', 'category' => 'isolation', 'requires_evidence' => true],
                ['key' => 'o_preserve', 'label' => 'Preserve evidence', 'category' => 'forensics', 'requires_evidence' => true],
                ['key' => 'o_scope', 'label' => 'Scope dampak + data terdampak', 'category' => 'assessment', 'requires_evidence' => true],
                ['key' => 'o_eradicate', 'label' => 'Eradication: hilangkan root cause', 'category' => 'eradication', 'requires_evidence' => true],
                ['key' => 'o_recover', 'label' => 'Recovery: restore operasi normal', 'category' => 'recovery', 'requires_evidence' => true],
                ['key' => 'o_notify', 'label' => 'Notifikasi regulator + subjek terdampak (jika applicable)', 'category' => 'legal', 'requires_evidence' => true],
                ['key' => 'o_lessons', 'label' => 'Post-incident review + lessons learned', 'category' => 'closure', 'requires_evidence' => true],
            ],
        ],
    ];
}
