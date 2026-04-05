<?php

namespace App\Services;

class SaasScanner
{
    /**
     * Simulate Google Workspace Scanning (Drive, Sheets, Docs)
     */
    public static function scanGoogleWorkspace(array $config): array
    {
        $domain = $config['domain'] ?? 'google_workspace';

        $files = [
            'Shared Drives/HR/Data Karyawan Aktif 2026.xlsx',
            'My Drive/Temp/Draft_Kontrak_Vendor.docx',
            'Shared Drives/Finance/Payroll_Januari.csv',
            'My Drive/List_Peserta_Webinar.gsheet',
            'Shared Drives/Marketing/Database_Leads.csv'
        ];

        return self::simulateSaasScan($domain, $files, 'google_workspace');
    }

    /**
     * Simulate Microsoft 365 Scanning (SharePoint, OneDrive)
     */
    public static function scanMicrosoft365(array $config): array
    {
        $domain = $config['domain'] ?? 'microsoft_365';
        
        $files = [
            'SharePoint/HR Department/Employee_Records.xlsx',
            'SharePoint/Legal/NDAs_Signed.pdf',
            'OneDrive/Personal/Backups/Database_Export.csv',
            'OneDrive/Finance/Invoices_Q1.xlsx',
            'SharePoint/IT/System_Logs_Dump.json'
        ];

        return self::simulateSaasScan($domain, $files, 'microsoft_365');
    }

    /**
     * Simulate Slack Workplace Scanning
     */
    public static function scanSlack(array $config): array
    {
        $workspace = $config['workspace'] ?? 'slack_workspace';
        
        // In Slack, "files" represent channels or DMs where PII was found
        $files = [
            '#general / file_uploads / contacts.csv',
            '#hr-internal / messages / "Berikut ini KTP kandidat..."',
            '#finance / file_uploads / payroll_temp.xlsx',
            '@johndoe (DM) / messages / "Tolong proses NIK ini: 3201..."'
        ];

        return self::simulateSaasScan($workspace, $files, 'slack');
    }

    /**
     * Simulate Notion Workspace Scanning
     */
    public static function scanNotion(array $config): array
    {
        $workspace = $config['workspace'] ?? 'notion_workspace';
        
        $files = [
            'Databases / Customer Pipeline',
            'Pages / HR / Onboarding Data',
            'Databases / Employee Directory',
            'Pages / Finance / Reimbursements'
        ];

        return self::simulateSaasScan($workspace, $files, 'notion');
    }

    /**
     * Helper to generate simulated PII findings for SaaS files/messages
     */
    private static function simulateSaasScan(string $domainName, array $items, string $engine): array
    {
        $tables = [];

        foreach ($items as $item) {
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'css', 'js'])) continue;

            $columns = [];
            // Randomly inject PII findings
            $piiCount = rand(1, 4);
            if (str_contains(strtolower($item), 'hr') || str_contains(strtolower($item), 'employee') || str_contains(strtolower($item), 'payroll')) {
                $piiCount = rand(3, 7); // Higher chance for HR/Finance
            }

            for ($i = 0; $i < $piiCount; $i++) {
                $piiTypes = [
                    ['name' => 'email_addresses', 'type' => 'string_match', 'pdp' => 'umum', 'cls' => 'pii', 'enc' => false, 'reason' => 'Alamat email bocor di dokumen/pesan'],
                    ['name' => 'phone_numbers', 'type' => 'string_match', 'pdp' => 'umum', 'cls' => 'pii', 'enc' => false, 'reason' => 'Nomor HP ditemukan dalam teks'],
                    ['name' => 'nik_ktp_patterns', 'type' => 'regex_match', 'pdp' => 'spesifik', 'cls' => 'sensitive', 'enc' => true, 'reason' => 'Pola 16 digit NIK terdeteksi'],
                    ['name' => 'credit_card_numbers', 'type' => 'regex_match', 'pdp' => 'spesifik', 'cls' => 'sensitive', 'enc' => true, 'reason' => 'Nomor kartu kredit terdeteksi tanpa masking'],
                    ['name' => 'financial_salary', 'type' => 'regex_match', 'pdp' => 'spesifik', 'cls' => 'sensitive', 'enc' => true, 'reason' => 'Data gaji/kompensasi terdeteksi'],
                    ['name' => 'full_names', 'type' => 'nlp_entity', 'pdp' => 'umum', 'cls' => 'pii', 'enc' => false, 'reason' => 'Nama individu terdeteksi via NER']
                ];
                
                $t = $piiTypes[array_rand($piiTypes)];
                
                // Avoid duplicates mock
                $exists = false;
                foreach($columns as $c) if($c['name'] === $t['name']) $exists = true;
                if ($exists) continue;

                $columns[] = [
                    'name' => $t['name'] . ' (Shadow)',
                    'type' => $t['type'],
                    'nullable' => true,
                    'pii_detected' => true,
                    'pdp_category' => $t['pdp'],
                    'classification' => $t['cls'],
                    'encryption_required' => $t['enc'],
                    'pii_reason' => $t['reason'],
                    'manually_classified' => false,
                    'shadow_detected' => true,
                ];
            }

            // Exaggerate row counts to simulate large unstructured files/chat histories
            $rowCount = str_contains($engine, 'slack') ? rand(10, 500) : rand(500, 500000);

            $tables[] = [
                'name' => $item,
                'columns' => $columns,
                'row_count' => $rowCount, 
                'size_mb' => round(rand(5, 1000) / 100, 2),
            ];
        }

        return ['tables' => $tables, 'engine' => 'simulated_' . $engine];
    }
}
