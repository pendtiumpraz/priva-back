<?php

namespace App\Services;

class CloudStorageScanner
{
    /**
     * Simulate S3 Bucket Scanning
     */
    public static function scanS3(array $config): array
    {
        $bucket = $config['bucket'] ?? 'my-s3-bucket';
        $pdpCategories = ['umum', 'spesifik'];
        $classifications = ['pii', 'sensitive'];

        $files = [
            'backups/2025/prod_db_dump.sql',
            'exports/marketing_leads.csv',
            'hr/employee_records_q1.xlsx',
            'temp/debug_logs_001.txt',
            'public/assets/images/logo.png',
            'data/analytics_raw.json'
        ];

        return self::simulateCloudScan($bucket, $files, 'aws_s3');
    }

    /**
     * Simulate GCS Bucket Scanning
     */
    public static function scanGcs(array $config): array
    {
        $bucket = $config['bucket'] ?? 'my-gcs-bucket';
        
        $files = [
            'bigquery_exports/customer_data.csv',
            'app_engine/error_logs_with_payloads.json',
            'firebase_backups/users_collection.json',
            'static/index.html',
            'reports/sales_2025_with_contacts.xlsx'
        ];

        return self::simulateCloudScan($bucket, $files, 'gcs');
    }

    /**
     * Simulate Azure Blob Scanning
     */
    public static function scanAzureBlob(array $config): array
    {
        $container = $config['container'] ?? 'my-azure-container';
        
        $files = [
            'sql_server_backups/DataBackup.bak',
            'ad_exports/azure_ad_users.csv',
            'telemetry/app_insights_raw.json',
            'documents/confidential_contracts.docx'
        ];

        return self::simulateCloudScan($container, $files, 'azure_blob');
    }

    /**
     * Helper to generate simulated PII findings for cloud files
     */
    private static function simulateCloudScan(string $containerName, array $filenames, string $engine): array
    {
        $tables = [];

        foreach ($filenames as $filename) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'html', 'css', 'js'])) continue;

            $columns = [];
            // Randomly inject PII findings into these files
            $piiCount = rand(0, 4);
            if (str_contains($filename, 'prod_db') || str_contains($filename, 'user') || str_contains($filename, 'employee')) {
                $piiCount = rand(3, 8); // Higher chance for DB dumps
            }

            for ($i = 0; $i < $piiCount; $i++) {
                $piiTypes = [
                    ['name' => 'email_addresses', 'type' => 'string_match', 'pdp' => 'umum', 'cls' => 'pii', 'enc' => false, 'reason' => 'Alamat email bocor di dalam file'],
                    ['name' => 'phone_numbers', 'type' => 'string_match', 'pdp' => 'umum', 'cls' => 'pii', 'enc' => false, 'reason' => 'Nomor HP ditemukan dalam teks'],
                    ['name' => 'nik_ktp_patterns', 'type' => 'regex_match', 'pdp' => 'spesifik', 'cls' => 'sensitive', 'enc' => true, 'reason' => 'Pola 16 digit NIK terdeteksi'],
                    ['name' => 'credit_card_numbers', 'type' => 'regex_match', 'pdp' => 'spesifik', 'cls' => 'sensitive', 'enc' => true, 'reason' => 'Nomor kartu kredit terdeteksi tanpa masking'],
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

            $tables[] = [
                'name' => $filename,
                'columns' => $columns,
                'row_count' => rand(500, 500000), // Simulating bytes or lines
                'size_mb' => round(rand(10, 5000) / 100, 2),
            ];
        }

        return ['tables' => $tables, 'engine' => 'simulated_' . $engine];
    }
}
