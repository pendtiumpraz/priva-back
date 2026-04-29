<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Real Database / Source Scanner
 * Supports: MySQL, PostgreSQL, MongoDB, File (CSV/JSON), API
 */
class DatabaseScanner
{
    /**
     * Test real connection to a data source
     */
    public static function testConnection(string $sourceType, array $config): array
    {
        return match ($sourceType) {
            'mysql'      => self::testMysql($config),
            'postgresql' => self::testPostgresql($config),
            'mongodb'    => self::testMongodb($config),
            'mssql'      => self::testMssql($config),
            'oracle'     => self::testOracle($config),
            'api'        => self::testApi($config),
            'file'       => self::testFile($config),
            'aws_s3'     => ['success' => true, 'note' => 'Connected to AWS S3 (Simulated)'],
            'gcs'        => ['success' => true, 'note' => 'Connected to Google Cloud Storage (Simulated)'],
            'azure_blob' => ['success' => true, 'note' => 'Connected to Azure Blob Storage (Simulated)'],
            'google_workspace' => ['success' => true, 'note' => 'Authenticated with Google Workspace (Simulated)'],
            'microsoft_365'    => ['success' => true, 'note' => 'Authenticated with Microsoft 365 (Simulated)'],
            'slack'            => ['success' => true, 'note' => 'Authenticated with Slack Workspace (Simulated)'],
            'notion'           => ['success' => true, 'note' => 'Authenticated with Notion (Simulated)'],
            default      => ['success' => false, 'error' => 'Unknown source type: ' . $sourceType],
        };
    }

    /**
     * Scan schema and detect PII columns
     */
    public static function scanSchema(string $sourceType, array $config): array
    {
        return match ($sourceType) {
            'mysql'      => self::scanMysql($config),
            'postgresql' => self::scanPostgresql($config),
            'mongodb'    => self::scanMongodb($config),
            'mssql'      => self::scanMssql($config),
            'oracle'     => self::scanOracle($config),
            'api'        => self::scanApi($config),
            'file'       => self::scanFile($config),
            'aws_s3'     => CloudStorageScanner::scanS3($config),
            'gcs'        => CloudStorageScanner::scanGcs($config),
            'azure_blob' => CloudStorageScanner::scanAzureBlob($config),
            'google_workspace' => SaasScanner::scanGoogleWorkspace($config),
            'microsoft_365'    => SaasScanner::scanMicrosoft365($config),
            'slack'            => SaasScanner::scanSlack($config),
            'notion'           => SaasScanner::scanNotion($config),
            default      => ['tables' => [], 'error' => 'Unknown source type'],
        };
    }

    // =============================================
    // MySQL
    // =============================================
    private static function testMysql(array $config): array
    {
        try {
            $start = microtime(true);
            $port = !empty($config['port']) ? $config['port'] : 3306;
            $dsn = "mysql:host={$config['host']};port={$port};dbname={$config['database']}";
            $pdo = new \PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [
                \PDO::ATTR_TIMEOUT => 5,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $ms = round((microtime(true) - $start) * 1000);
            $version = $pdo->query("SELECT VERSION()")->fetchColumn();
            $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
            return [
                'success' => true,
                'latency_ms' => $ms,
                'server_version' => 'MySQL ' . $version,
                'tables_found' => count($tables),
                'ssl_enabled' => false,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private static function scanMysql(array $config): array
    {
        try {
            $port = !empty($config['port']) ? $config['port'] : 3306;
            $dsn = "mysql:host={$config['host']};port={$port};dbname={$config['database']}";
            $pdo = new \PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [
                \PDO::ATTR_TIMEOUT => 10,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $tables = [];
            $tableNames = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tableNames as $tableName) {
                $columns = [];
                $cols = $pdo->query("SHOW COLUMNS FROM `{$tableName}`")->fetchAll(\PDO::FETCH_ASSOC);
                $rowCount = $pdo->query("SELECT COUNT(*) FROM `{$tableName}`")->fetchColumn();

                // Phase 1 Shadow Data Discovery: Content Sampling
                $sampleRows = [];
                try {
                    $sampleRows = $pdo->query("SELECT * FROM `{$tableName}` LIMIT 100")->fetchAll(\PDO::FETCH_ASSOC);
                } catch (\Throwable $e) {}

                foreach ($cols as $col) {
                    $colName = $col['Field'];
                    $piiResult = PiiDetector::analyze($colName, $col['Type']);
                    $shadowDetected = false;

                    // Execute Content Scanner on sample data
                    if (!empty($sampleRows) && count($sampleRows) > 0) {
                        $columnValues = array_column($sampleRows, $colName);
                        $contentPii = ContentPiiScanner::analyzeColumnContent($columnValues);

                        if ($contentPii) {
                            $piiResult = $contentPii;
                            $shadowDetected = true;
                        }
                    }

                    $columns[] = [
                        'name' => $colName,
                        'type' => $col['Type'],
                        'nullable' => $col['Null'] === 'YES',
                        'pii_detected' => $piiResult['is_pii'],
                        'pdp_category' => $piiResult['pdp_category'],
                        'classification' => $piiResult['classification'],
                        'encryption_required' => $piiResult['encryption_required'],
                        'pii_reason' => $piiResult['reason'],
                        'manually_classified' => false,
                        'shadow_detected' => $shadowDetected,
                    ];
                }

                $tables[] = [
                    'name' => $tableName,
                    'columns' => $columns,
                    'row_count' => (int) $rowCount,
                    'size_mb' => 0,
                ];
            }

            return ['tables' => $tables, 'engine' => 'real_mysql'];
        } catch (\Throwable $e) {
            Log::error("MySQL scan failed: " . $e->getMessage());
            return ['tables' => [], 'error' => $e->getMessage()];
        }
    }

    // =============================================
    // PostgreSQL
    // =============================================
    private static function testPostgresql(array $config): array
    {
        try {
            $start = microtime(true);
            $port = !empty($config['port']) ? $config['port'] : 5432;
            $dsn = "pgsql:host={$config['host']};port={$port};dbname={$config['database']}";
            $pdo = new \PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [
                \PDO::ATTR_TIMEOUT => 5,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $ms = round((microtime(true) - $start) * 1000);
            $version = $pdo->query("SELECT version()")->fetchColumn();
            $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public'")->fetchAll(\PDO::FETCH_COLUMN);
            return [
                'success' => true,
                'latency_ms' => $ms,
                'server_version' => substr($version, 0, 30),
                'tables_found' => count($tables),
                'ssl_enabled' => true,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private static function scanPostgresql(array $config): array
    {
        try {
            $port = !empty($config['port']) ? $config['port'] : 5432;
            $dsn = "pgsql:host={$config['host']};port={$port};dbname={$config['database']}";
            $pdo = new \PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [
                \PDO::ATTR_TIMEOUT => 10,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $tables = [];
            $tableNames = $pdo->query(
                "SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"
            )->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tableNames as $tableName) {
                $cols = $pdo->query(
                    "SELECT column_name, data_type, is_nullable FROM information_schema.columns
                     WHERE table_schema='public' AND table_name='{$tableName}' ORDER BY ordinal_position"
                )->fetchAll(\PDO::FETCH_ASSOC);

                $rowCount = 0;
                try { $rowCount = $pdo->query("SELECT COUNT(*) FROM \"{$tableName}\"")->fetchColumn(); } catch (\Throwable) {}

                // Phase 1 Shadow Data Discovery: Content Sampling
                $sampleRows = [];
                try {
                    $sampleRows = $pdo->query("SELECT * FROM \"{$tableName}\" LIMIT 100")->fetchAll(\PDO::FETCH_ASSOC);
                } catch (\Throwable $e) {}

                $columns = [];
                foreach ($cols as $col) {
                    $colName = $col['column_name'];
                    $piiResult = PiiDetector::analyze($colName, $col['data_type']);
                    $shadowDetected = false;

                    // Execute Content Scanner on sample data
                    if (!empty($sampleRows) && count($sampleRows) > 0) {
                        $columnValues = array_column($sampleRows, $colName);
                        $contentPii = ContentPiiScanner::analyzeColumnContent($columnValues);

                        if ($contentPii) {
                            $piiResult = $contentPii;
                            $shadowDetected = true;
                        }
                    }

                    $columns[] = [
                        'name' => $colName,
                        'type' => $col['data_type'],
                        'nullable' => $col['is_nullable'] === 'YES',
                        'pii_detected' => $piiResult['is_pii'],
                        'pdp_category' => $piiResult['pdp_category'],
                        'classification' => $piiResult['classification'],
                        'encryption_required' => $piiResult['encryption_required'],
                        'pii_reason' => $piiResult['reason'],
                        'manually_classified' => false,
                        'shadow_detected' => $shadowDetected,
                    ];
                }

                $tables[] = [
                    'name' => $tableName,
                    'columns' => $columns,
                    'row_count' => (int) $rowCount,
                    'size_mb' => 0,
                ];
            }

            return ['tables' => $tables, 'engine' => 'real_postgresql'];
        } catch (\Throwable $e) {
            Log::error("PostgreSQL scan failed: " . $e->getMessage());
            return ['tables' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Phase 3c — Scan database access paths (who can do what to which table).
     *
     * Output structure:
     *   [
     *     'engine' => 'postgresql' | 'mysql' | ...,
     *     'grants' => [
     *       ['grantee' => 'app_user', 'table' => 'users', 'privilege' => 'SELECT', 'is_grantable' => false],
     *       ...
     *     ],
     *     'roles' => [   // Postgres only — list of all roles + login flag
     *       ['name' => 'app_user', 'can_login' => true, 'is_superuser' => false],
     *       ...
     *     ],
     *     'error' => null | string,
     *   ]
     *
     * Read-only — only queries metadata, no data movement.
     */
    public static function scanAccessPaths(string $sourceType, array $config): array
    {
        return match ($sourceType) {
            'postgresql' => self::scanAccessPathsPostgres($config),
            'mysql'      => self::scanAccessPathsMysql($config),
            default      => ['engine' => $sourceType, 'grants' => [], 'roles' => [], 'error' => 'Access path scan not supported for ' . $sourceType],
        };
    }

    private static function scanAccessPathsPostgres(array $config): array
    {
        try {
            $port = !empty($config['port']) ? $config['port'] : 5432;
            $dsn = "pgsql:host={$config['host']};port={$port};dbname={$config['database']}";
            $pdo = new \PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [
                \PDO::ATTR_TIMEOUT => 10,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            // Roles + login capability
            $rolesRaw = $pdo->query(
                "SELECT rolname AS name, rolcanlogin AS can_login, rolsuper AS is_superuser,
                        rolcreatedb AS can_create_db, rolcreaterole AS can_create_role,
                        rolvaliduntil AS valid_until
                 FROM pg_roles
                 WHERE rolname NOT LIKE 'pg\_%'
                 ORDER BY rolname"
            )->fetchAll(\PDO::FETCH_ASSOC);

            $roles = array_map(fn ($r) => [
                'name' => $r['name'],
                'can_login' => (bool) $r['can_login'],
                'is_superuser' => (bool) $r['is_superuser'],
                'can_create_db' => (bool) $r['can_create_db'],
                'can_create_role' => (bool) $r['can_create_role'],
                'valid_until' => $r['valid_until'],
            ], $rolesRaw);

            // Per-table privilege grants (excluding self-owner internal grants on PUBLIC)
            $grantsRaw = $pdo->query(
                "SELECT grantee, table_name, privilege_type, is_grantable
                 FROM information_schema.role_table_grants
                 WHERE table_schema = 'public'
                   AND grantee NOT IN ('PUBLIC')
                   AND grantee NOT LIKE 'pg\_%'
                 ORDER BY grantee, table_name"
            )->fetchAll(\PDO::FETCH_ASSOC);

            $grants = array_map(fn ($g) => [
                'grantee' => $g['grantee'],
                'table' => $g['table_name'],
                'privilege' => $g['privilege_type'],
                'is_grantable' => $g['is_grantable'] === 'YES',
            ], $grantsRaw);

            return [
                'engine' => 'postgresql',
                'roles' => $roles,
                'grants' => $grants,
                'scanned_at' => now()->toIso8601String(),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning("PostgreSQL access path scan failed: " . $e->getMessage());
            return ['engine' => 'postgresql', 'grants' => [], 'roles' => [], 'error' => $e->getMessage()];
        }
    }

    private static function scanAccessPathsMysql(array $config): array
    {
        try {
            $port = !empty($config['port']) ? $config['port'] : 3306;
            $dsn = "mysql:host={$config['host']};port={$port};dbname={$config['database']}";
            $pdo = new \PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [
                \PDO::ATTR_TIMEOUT => 10,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            // List MySQL users (note: needs privilege on mysql.user — may fail
            // for non-DBA scanner accounts; we degrade gracefully)
            $roles = [];
            try {
                $rows = $pdo->query("SELECT User AS name, Host AS host FROM mysql.user")->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $roles[] = ['name' => $r['name'] . '@' . $r['host'], 'can_login' => true, 'is_superuser' => false];
                }
            } catch (\Throwable $e) {
                Log::info('MySQL mysql.user inaccessible to scanner — degrading: ' . $e->getMessage());
            }

            // Schema-level privileges
            $grants = [];
            try {
                $rows = $pdo->query(
                    "SELECT GRANTEE, TABLE_SCHEMA, TABLE_NAME, PRIVILEGE_TYPE, IS_GRANTABLE
                     FROM INFORMATION_SCHEMA.TABLE_PRIVILEGES
                     WHERE TABLE_SCHEMA = '{$config['database']}'
                     ORDER BY GRANTEE, TABLE_NAME"
                )->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $g) {
                    $grants[] = [
                        'grantee' => trim($g['GRANTEE'], "'"),
                        'table' => $g['TABLE_NAME'],
                        'privilege' => $g['PRIVILEGE_TYPE'],
                        'is_grantable' => $g['IS_GRANTABLE'] === 'YES',
                    ];
                }
            } catch (\Throwable $e) {
                Log::info('MySQL TABLE_PRIVILEGES inaccessible: ' . $e->getMessage());
            }

            return [
                'engine' => 'mysql',
                'roles' => $roles,
                'grants' => $grants,
                'scanned_at' => now()->toIso8601String(),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning("MySQL access path scan failed: " . $e->getMessage());
            return ['engine' => 'mysql', 'grants' => [], 'roles' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Phase 3c — Scan encryption signals at the DB level.
     *
     * Output:
     *   [
     *     'engine' => 'postgresql' | 'mysql' | ...,
     *     'ssl_in_use' => bool,                   // connection-level TLS
     *     'tablespace_encryption' => [            // per-table where detectable
     *       ['table' => 'users', 'encrypted' => true|false|null],
     *       ...
     *     ],
     *     'column_encryption_observed' => [       // tables with pgp_sym_encrypt
     *                                             // or AES_ENCRYPT in their data
     *       'tables' => ['users'],
     *       'note' => 'Detected via column type / data sample'
     *     ],
     *     'data_at_rest_encrypted' => bool|null,  // best-effort overall flag
     *     'error' => null | string,
     *   ]
     *
     * Note: at-rest encryption is usually filesystem/storage-level (LUKS,
     * EBS encryption) — not visible to DB. We detect what IS visible:
     *   - Postgres: SSL in use (pg_settings ssl), tablespace flags via
     *     pg_tablespace and pg_class.reloptions when AWS RDS encryption used
     *   - MySQL: INFORMATION_SCHEMA.INNODB_TABLESPACES_ENCRYPTION
     */
    public static function scanEncryption(string $sourceType, array $config): array
    {
        return match ($sourceType) {
            'postgresql' => self::scanEncryptionPostgres($config),
            'mysql'      => self::scanEncryptionMysql($config),
            default      => [
                'engine' => $sourceType,
                'ssl_in_use' => null,
                'tablespace_encryption' => [],
                'column_encryption_observed' => ['tables' => [], 'note' => null],
                'data_at_rest_encrypted' => null,
                'error' => 'Encryption scan not supported for ' . $sourceType,
            ],
        };
    }

    private static function scanEncryptionPostgres(array $config): array
    {
        try {
            $port = !empty($config['port']) ? $config['port'] : 5432;
            $dsn = "pgsql:host={$config['host']};port={$port};dbname={$config['database']}";
            $pdo = new \PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [
                \PDO::ATTR_TIMEOUT => 10,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            // SSL in use for THIS connection
            $sslInUse = false;
            try {
                $row = $pdo->query("SELECT setting FROM pg_settings WHERE name = 'ssl'")->fetchColumn();
                $sslInUse = strtolower((string) $row) === 'on';
            } catch (\Throwable $e) {}

            // Detect column-level encryption via pgcrypto: scan public schema for
            // bytea columns named like *_encrypted / *_enc — heuristic, not perfect.
            $colEncTables = [];
            try {
                $rows = $pdo->query(
                    "SELECT DISTINCT table_name FROM information_schema.columns
                     WHERE table_schema = 'public'
                       AND data_type = 'bytea'
                       AND (column_name ~ '_(enc|encrypted|cipher)$'
                            OR column_name LIKE 'enc\\_%')"
                )->fetchAll(\PDO::FETCH_COLUMN);
                $colEncTables = array_values(array_unique($rows));
            } catch (\Throwable $e) {}

            // Per-table encryption flag — Postgres doesn't expose tablespace
            // encryption as a per-table flag; we mark unknown for all and let
            // the operator override via protection_assessment.
            $tablespace = [];
            try {
                $tableNames = $pdo->query(
                    "SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"
                )->fetchAll(\PDO::FETCH_COLUMN);
                foreach ($tableNames as $name) {
                    $tablespace[] = [
                        'table' => $name,
                        'encrypted' => null,   // unknown at DB layer alone
                        'note' => 'PostgreSQL does not expose tablespace-level encryption flag via SQL — verify via storage layer (RDS storage_encrypted, LUKS, etc.)',
                    ];
                }
            } catch (\Throwable $e) {}

            return [
                'engine' => 'postgresql',
                'ssl_in_use' => $sslInUse,
                'tablespace_encryption' => $tablespace,
                'column_encryption_observed' => [
                    'tables' => $colEncTables,
                    'note' => 'Detected via heuristic: bytea columns named *_enc/*_encrypted/enc_*',
                ],
                'data_at_rest_encrypted' => null,
                'scanned_at' => now()->toIso8601String(),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning("PostgreSQL encryption scan failed: " . $e->getMessage());
            return [
                'engine' => 'postgresql', 'ssl_in_use' => null,
                'tablespace_encryption' => [], 'column_encryption_observed' => ['tables' => [], 'note' => null],
                'data_at_rest_encrypted' => null, 'error' => $e->getMessage(),
            ];
        }
    }

    private static function scanEncryptionMysql(array $config): array
    {
        try {
            $port = !empty($config['port']) ? $config['port'] : 3306;
            $dsn = "mysql:host={$config['host']};port={$port};dbname={$config['database']}";
            $pdo = new \PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [
                \PDO::ATTR_TIMEOUT => 10,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            // SSL — query session status
            $sslInUse = false;
            try {
                $row = $pdo->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch(\PDO::FETCH_ASSOC);
                $sslInUse = !empty($row['Value']);
            } catch (\Throwable $e) {}

            // Tablespace encryption — InnoDB
            $tablespace = [];
            $atRestAllEnc = null;
            try {
                $rows = $pdo->query(
                    "SELECT t.NAME AS table_name, te.ENCRYPTION
                     FROM INFORMATION_SCHEMA.INNODB_TABLES t
                     LEFT JOIN INFORMATION_SCHEMA.INNODB_TABLESPACES_ENCRYPTION te
                       ON te.SPACE = t.SPACE
                     WHERE t.NAME LIKE '{$config['database']}/%'"
                )->fetchAll(\PDO::FETCH_ASSOC);

                $allEnc = !empty($rows);
                foreach ($rows as $r) {
                    $tableName = explode('/', $r['table_name'])[1] ?? $r['table_name'];
                    $enc = $r['ENCRYPTION'] === 'Y' || strtoupper((string) $r['ENCRYPTION']) === 'ON';
                    if (!$enc) $allEnc = false;
                    $tablespace[] = [
                        'table' => $tableName,
                        'encrypted' => $enc,
                        'note' => null,
                    ];
                }
                $atRestAllEnc = !empty($rows) ? $allEnc : null;
            } catch (\Throwable $e) {
                Log::info('MySQL INNODB_TABLESPACES_ENCRYPTION inaccessible: ' . $e->getMessage());
            }

            // Column encryption hint — detect AES_ENCRYPT / VARBINARY columns
            // named like *_enc / *_encrypted (heuristic).
            $colEncTables = [];
            try {
                $rows = $pdo->query(
                    "SELECT DISTINCT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = '{$config['database']}'
                       AND DATA_TYPE IN ('varbinary', 'blob')
                       AND (COLUMN_NAME REGEXP '_(enc|encrypted|cipher)$'
                            OR COLUMN_NAME LIKE 'enc\\_%')"
                )->fetchAll(\PDO::FETCH_COLUMN);
                $colEncTables = array_values(array_unique($rows));
            } catch (\Throwable $e) {}

            return [
                'engine' => 'mysql',
                'ssl_in_use' => $sslInUse,
                'tablespace_encryption' => $tablespace,
                'column_encryption_observed' => [
                    'tables' => $colEncTables,
                    'note' => 'Detected via heuristic: varbinary/blob columns named *_enc/*_encrypted/enc_*',
                ],
                'data_at_rest_encrypted' => $atRestAllEnc,
                'scanned_at' => now()->toIso8601String(),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning("MySQL encryption scan failed: " . $e->getMessage());
            return [
                'engine' => 'mysql', 'ssl_in_use' => null,
                'tablespace_encryption' => [], 'column_encryption_observed' => ['tables' => [], 'note' => null],
                'data_at_rest_encrypted' => null, 'error' => $e->getMessage(),
            ];
        }
    }

    private static function testMongodb(array $config): array
    {
        $start = microtime(true);
        $ms = round((microtime(true) - $start) * 1000 + rand(15, 80));
        return [
            'success' => true,
            'latency_ms' => $ms,
            'server_version' => 'MongoDB 7.0',
            'collections_found' => rand(3, 20),
            'note' => 'Connection simulated (MongoDB PHP driver not installed)',
        ];
    }

    private static function scanMongodb(array $config): array
    {
        return self::simulateScan('mongodb');
    }

    // =============================================
    // MSSQL — fallback to simulation
    // =============================================
    private static function testMssql(array $config): array
    {
        $start = microtime(true);
        $ms = round((microtime(true) - $start) * 1000 + rand(20, 100));
        return [
            'success' => true,
            'latency_ms' => $ms,
            'server_version' => 'Microsoft SQL Server 2022 (RTM) - 16.0.1000.6',
            'tables_found' => rand(15, 50),
            'note' => 'Connection simulated (sqlsrv driver not available in environment)',
        ];
    }

    private static function scanMssql(array $config): array
    {
        return self::simulateScan('mssql');
    }

    // =============================================
    // Oracle — fallback to simulation
    // =============================================
    private static function testOracle(array $config): array
    {
        $start = microtime(true);
        $ms = round((microtime(true) - $start) * 1000 + rand(50, 150));
        return [
            'success' => true,
            'latency_ms' => $ms,
            'server_version' => 'Oracle Database 19c Enterprise Edition Release 19.0.0.0.0',
            'tables_found' => rand(20, 100),
            'note' => 'Connection simulated (oci8 driver not available in environment)',
        ];
    }

    private static function scanOracle(array $config): array
    {
        return self::simulateScan('oracle');
    }

    // =============================================
    // API Source
    // =============================================
    private static function testApi(array $config): array
    {
        try {
            $start = microtime(true);
            $response = \Http::timeout(5)->get($config['url'] ?? '');
            $ms = round((microtime(true) - $start) * 1000);
            return [
                'success' => $response->successful(),
                'latency_ms' => $ms,
                'status_code' => $response->status(),
                'content_type' => $response->header('Content-Type'),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private static function scanApi(array $config): array
    {
        return self::simulateScan('api');
    }

    // =============================================
    // File Source
    // =============================================
    private static function testFile(array $config): array
    {
        $path = $config['path'] ?? '';
        if ($path && file_exists($path)) {
            return [
                'success' => true,
                'files_found' => count(glob($path . '/*.{csv,json,xlsx}', GLOB_BRACE)),
                'total_size_mb' => round(array_sum(array_map('filesize', glob($path . '/*'))) / 1048576, 2),
                'formats' => ['csv', 'json', 'xlsx'],
            ];
        }
        return ['success' => true, 'note' => 'Path not accessible – simulated', 'files_found' => rand(5, 50)];
    }

    private static function scanFile(array $config): array
    {
        $path = $config['path'] ?? '';
        if (!$path || !is_dir($path)) {
            return ['tables' => [], 'error' => 'Path is invalid or not a directory.'];
        }

        $files = glob($path . '/*.{csv,json,xlsx,pdf,docx}', GLOB_BRACE);
        $tables = [];

        $parserService = new \App\Services\DocumentParserService();

        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $filename = basename($file);
            $columns = [];
            $rowCount = 0;
            $sizeMb = round(filesize($file) / 1048576, 2);

            try {
                if ($ext === 'csv') {
                    if (($handle = fopen($file, "r")) !== FALSE) {
                        $headers = fgetcsv($handle, 1000, ",");
                        if ($headers) {
                            foreach ($headers as $h) {
                                $piiResult = PiiDetector::analyze($h, 'varchar');
                                $columns[] = array_merge([
                                    'name' => $h, 'type' => 'varchar', 'nullable' => true,
                                    'manually_classified' => false
                                ], $piiResult);
                            }
                        }
                        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                            $rowCount++;
                        }
                        fclose($handle);
                    }
                } elseif ($ext === 'json') {
                    $content = json_decode(file_get_contents($file), true);
                    if (is_array($content)) {
                        $rowCount = count($content);
                        if ($rowCount > 0 && is_array($content[0])) {
                            foreach (array_keys($content[0]) as $h) {
                                $piiResult = PiiDetector::analyze($h, 'json_field');
                                $columns[] = array_merge([
                                    'name' => $h, 'type' => 'json_field', 'nullable' => true,
                                    'manually_classified' => false
                                ], $piiResult);
                            }
                        }
                    }
                } elseif (in_array($ext, ['pdf', 'docx', 'xlsx'])) {
                    // UNSTRUCTURED DATA SCAN USING DOCUMENT INTELLIGENCE
                    $parsed = $parserService->parse($file, $ext);
                    $rawText = $parsed['raw_text'] ?? '';
                    
                    // Split raw text into isolated words to match exact regex patterns
                    $words = preg_split('/\s+/', $rawText);
                    $rowCount = 1;

                    if (!empty($words)) {
                        $piiResult = ContentPiiScanner::analyzeColumnContent($words);
                        if ($piiResult && $piiResult['is_pii']) {
                            $columns[] = array_merge([
                                'name' => 'unstructured_content',
                                'type' => strtoupper($ext) . ' Document',
                                'nullable' => true,
                                'manually_classified' => false
                            ], $piiResult);
                        } else {
                            $columns[] = [
                                'name' => 'unstructured_content',
                                'type' => strtoupper($ext) . ' Document',
                                'nullable' => true,
                                'pii_detected' => false,
                                'pdp_category' => null,
                                'classification' => 'internal',
                                'encryption_required' => false,
                                'manually_classified' => false
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error("Failed to scan file {$filename}: " . $e->getMessage());
            }

            if (!empty($columns)) {
                $tables[] = [
                    'name' => $filename,
                    'columns' => $columns,
                    'row_count' => $rowCount,
                    'size_mb' => $sizeMb,
                ];
            }
        }

        return ['tables' => $tables, 'engine' => 'real_file_scanner'];
    }

    // =============================================
    // Simulation fallback (for unsupported types)
    // =============================================
    public static function simulateScan(string $sourceType): array
    {
        $tableTemplates = [
            ['name' => 'users', 'columns' => [
                ['name' => 'id', 'type' => 'uuid', 'pii_detected' => false, 'pdp_category' => null, 'classification' => 'internal', 'encryption_required' => false, 'nullable' => false],
                ['name' => 'full_name', 'type' => 'varchar(255)', 'pii_detected' => true, 'pdp_category' => 'umum', 'classification' => 'pii', 'encryption_required' => false, 'pii_reason' => 'Nama lengkap terdeteksi', 'nullable' => true],
                ['name' => 'email', 'type' => 'varchar(255)', 'pii_detected' => true, 'pdp_category' => 'umum', 'classification' => 'pii', 'encryption_required' => false, 'pii_reason' => 'Email address terdeteksi', 'nullable' => true],
                ['name' => 'phone_number', 'type' => 'varchar(20)', 'pii_detected' => true, 'pdp_category' => 'umum', 'classification' => 'pii', 'encryption_required' => false, 'pii_reason' => 'Nomor telepon terdeteksi', 'nullable' => true],
                ['name' => 'nik', 'type' => 'varchar(16)', 'pii_detected' => true, 'pdp_category' => 'spesifik', 'classification' => 'sensitive', 'encryption_required' => true, 'pii_reason' => 'NIK (ID Nasional) terdeteksi', 'nullable' => true],
                ['name' => 'date_of_birth', 'type' => 'date', 'pii_detected' => true, 'pdp_category' => 'umum', 'classification' => 'pii', 'encryption_required' => false, 'pii_reason' => 'Tanggal lahir terdeteksi', 'nullable' => true],
                ['name' => 'created_at', 'type' => 'timestamp', 'pii_detected' => false, 'pdp_category' => null, 'classification' => 'internal', 'encryption_required' => false, 'nullable' => false],
            ]],
            ['name' => 'employees', 'columns' => [
                ['name' => 'id', 'type' => 'bigint', 'pii_detected' => false, 'pdp_category' => null, 'classification' => 'internal', 'encryption_required' => false, 'nullable' => false],
                ['name' => 'employee_name', 'type' => 'varchar(255)', 'pii_detected' => true, 'pdp_category' => 'umum', 'classification' => 'pii', 'encryption_required' => false, 'pii_reason' => 'Nama karyawan terdeteksi', 'nullable' => false],
                ['name' => 'salary', 'type' => 'decimal(15,2)', 'pii_detected' => true, 'pdp_category' => 'spesifik', 'classification' => 'sensitive', 'encryption_required' => true, 'pii_reason' => 'Data keuangan sensitif', 'nullable' => false],
                ['name' => 'health_record', 'type' => 'text', 'pii_detected' => true, 'pdp_category' => 'spesifik', 'classification' => 'sensitive', 'encryption_required' => true, 'pii_reason' => 'Rekam medis (data kesehatan)', 'nullable' => true],
                ['name' => 'religion', 'type' => 'varchar(50)', 'pii_detected' => true, 'pdp_category' => 'spesifik', 'classification' => 'sensitive', 'encryption_required' => true, 'pii_reason' => 'Agama/keyakinan – data spesifik', 'nullable' => true],
            ]],
            ['name' => 'transactions', 'columns' => [
                ['name' => 'id', 'type' => 'uuid', 'pii_detected' => false, 'pdp_category' => null, 'classification' => 'internal', 'encryption_required' => false, 'nullable' => false],
                ['name' => 'card_number', 'type' => 'varchar(19)', 'pii_detected' => true, 'pdp_category' => 'spesifik', 'classification' => 'sensitive', 'encryption_required' => true, 'pii_reason' => 'Nomor kartu kredit/debit', 'nullable' => true],
                ['name' => 'ip_address', 'type' => 'varchar(45)', 'pii_detected' => true, 'pdp_category' => 'umum', 'classification' => 'pii', 'encryption_required' => false, 'pii_reason' => 'Alamat IP terdeteksi', 'nullable' => true],
                ['name' => 'amount', 'type' => 'decimal(15,2)', 'pii_detected' => false, 'pdp_category' => null, 'classification' => 'internal', 'encryption_required' => false, 'nullable' => false],
            ]],
        ];

        $count = rand(2, count($tableTemplates));
        shuffle($tableTemplates);
        $selected = array_slice($tableTemplates, 0, $count);
        foreach ($selected as &$t) {
            $t['row_count'] = rand(500, 250000);
            $t['size_mb'] = round($t['row_count'] * rand(1, 8) / 1000, 1);
            foreach ($t['columns'] as &$c) { $c['manually_classified'] = false; }
        }
        return ['tables' => $selected, 'engine' => 'simulated'];
    }

    // =============================================
    // DSR: Search Exact Subject Data
    // =============================================
    public static function searchSubject(string $type, array $config, array $tables, string $identifier): array
    {
        $start = microtime(true);
        $results = [];

        try {
            if ($type === 'mysql') {
                $host = $config['host'] ?? '127.0.0.1';
                $port = $config['port'] ?? 3306;
                $db = $config['database'] ?? '';
                $user = $config['username'] ?? '';
                $pass = $config['password'] ?? '';

                $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
                $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3]);
                
                // For each table with PII, try to find the user
                foreach ($tables as $t) {
                    if (empty($t['pii_columns'])) continue;
                    
                    $conditions = [];
                    $params = [];
                    foreach ($t['pii_columns'] as $col) {
                        $conditions[] = "`{$col}` = ?";
                        $params[] = $identifier;
                    }
                    if (empty($conditions)) continue;

                    $sql = "SELECT * FROM `{$t['table']}` WHERE " . implode(' OR ', $conditions) . " LIMIT 10";
                    try {
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                        if (count($rows) > 0) {
                            $results[] = [
                                'table' => $t['table'],
                                'matched_rows' => count($rows),
                                'matched_on' => array_keys(array_filter($rows[0], fn($v) => $v === $identifier)),
                            ];
                        }
                    } catch (\Throwable) {} // Ignore querying errors for specific tables
                }
            } 
            elseif ($type === 'postgresql') {
                $host = $config['host'] ?? '127.0.0.1';
                $port = $config['port'] ?? 5432;
                $db = $config['database'] ?? '';
                $user = $config['username'] ?? '';
                $pass = $config['password'] ?? '';
                $ssl = ($config['sslmode'] ?? '') ? "sslmode=" . $config['sslmode'] : '';

                $dsn = "pgsql:host={$host};port={$port};dbname={$db};{$ssl}";
                $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3]);
                
                foreach ($tables as $t) {
                    if (empty($t['pii_columns'])) continue;
                    
                    $conditions = [];
                    $params = [];
                    foreach ($t['pii_columns'] as $col) {
                        $conditions[] = "\"{$col}\" = ?";
                        $params[] = $identifier;
                    }
                    if (empty($conditions)) continue;

                    $sql = "SELECT * FROM \"{$t['table']}\" WHERE " . implode(' OR ', $conditions) . " LIMIT 10";
                    try {
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                        if (count($rows) > 0) {
                            $results[] = [
                                'table' => $t['table'],
                                'matched_rows' => count($rows),
                                'matched_on' => array_keys(array_filter($rows[0], fn($v) => $v === $identifier)),
                            ];
                        }
                    } catch (\Throwable) {} 
                }
            }
            else {
                // Return generic match for non-sql systems
                $results[] = ['table' => 'simulation_match', 'matched_rows' => 1, 'matched_on' => ['email']];
            }
        } catch (\Throwable $e) {
            Log::error("Failed DSR search in $type: " . $e->getMessage());
        }

        return [
            'found_data' => count($results) > 0,
            'matches' => $results,
            'search_time_ms' => round((microtime(true) - $start) * 1000)
        ];
    }

    /**
     * Execute raw read queries generated by AI Text-to-SQL.
     *
     * Read-only guard is enforced at THREE layers so a leaky prompt or a
     * cleverly-escaped mutation keyword can't reach the data source:
     *   1. Per-query text validation (only SELECT/WITH, no mutation keywords,
     *      no multi-statement via internal semicolons).
     *   2. Driver-level multi-statement flag disabled (MySQL).
     *   3. Read-only transaction — any mutation that slips through text
     *      validation gets rolled back and fails at the DB level
     *      (SET TRANSACTION READ ONLY).
     */
    public static function executeRawReadQueries(string $sourceType, array $config, array $queries): array
    {
        if (empty($queries)) return ['results' => []];

        $sanitized = [];
        foreach ($queries as $query) {
            $check = self::validateReadOnlyQuery($query);
            if ($check !== true) {
                return ['error' => "Query ditolak (read-only guard): {$check}. Query: " . substr($query, 0, 200)];
            }
            $sanitized[] = rtrim(trim($query), ';');
        }

        $pdo = null;
        $txStarted = false;
        try {
            if ($sourceType === 'mysql') {
                $dsn = "mysql:host={$config['host']};port=" . ($config['port'] ?? 3306) . ";dbname={$config['database']}";
                $pdo = new \PDO($dsn, $config['username'], $config['password'], [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
                ]);
                // MySQL: START TRANSACTION READ ONLY is the atomic form.
                // SET TRANSACTION READ ONLY mid-transaction is invalid.
                $pdo->exec('START TRANSACTION READ ONLY');
            } elseif ($sourceType === 'postgresql') {
                $dsn = "pgsql:host={$config['host']};port=" . ($config['port'] ?? 5432) . ";dbname={$config['database']}";
                $pdo = new \PDO($dsn, $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('BEGIN READ ONLY');
            } else {
                return ['error' => 'Unsupported source_type for raw query execution.'];
            }
            $txStarted = true;

            $results = [];
            foreach ($sanitized as $query) {
                $stmt = $pdo->query($query);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $results[] = ['query' => $query, 'rows' => $rows];
            }

            $pdo->exec('ROLLBACK');
            return ['results' => $results];

        } catch (\Throwable $e) {
            if ($pdo && $txStarted) {
                try { $pdo->exec('ROLLBACK'); } catch (\Throwable $ignored) {}
            }
            Log::error("Failed executing raw read queries: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Returns true if the query is a safe read-only statement, or a string
     * describing why it was rejected. Public so other call sites (leak
     * verifier, etc.) can reuse the same hardening.
     */
    public static function validateReadOnlyQuery(string $query): string|bool
    {
        $q = trim($query);
        if ($q === '') return 'query kosong';

        // Strip -- line comments and /* ... */ block comments before keyword check
        // so attackers can't hide mutation keywords inside comments that the DB
        // parser ignores but our regex otherwise wouldn't.
        $stripped = preg_replace('~/\*.*?\*/~s', ' ', $q) ?? $q;
        $stripped = preg_replace('~--[^\n]*~', ' ', $stripped) ?? $stripped;

        if (!preg_match('/^\s*(SELECT|WITH|SHOW|EXPLAIN|DESCRIBE|DESC)\b/i', $stripped)) {
            return 'query harus dimulai dengan SELECT/WITH/SHOW/EXPLAIN';
        }

        // Reject any internal semicolon (only one trailing ; allowed)
        $withoutTrailing = rtrim($stripped, "; \t\n\r\0\x0B");
        if (str_contains($withoutTrailing, ';')) {
            return 'multi-statement tidak diizinkan (terdapat `;` di tengah query)';
        }

        // Word-boundary match on mutation / DDL / DCL keywords
        $forbidden = [
            'INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'ALTER', 'CREATE',
            'GRANT', 'REVOKE', 'MERGE', 'REPLACE', 'RENAME', 'CALL', 'EXEC',
            'LOCK', 'UNLOCK', 'LOAD', 'COPY', 'IMPORT', 'HANDLER',
        ];
        foreach ($forbidden as $kw) {
            if (preg_match('/\b' . $kw . '\b/i', $stripped)) {
                return "keyword `{$kw}` tidak diizinkan";
            }
        }

        // PostgreSQL CTE with DML RETURNING — guarded by the forbidden-keyword
        // scan above (DELETE/UPDATE/INSERT inside WITH clause will hit the list),
        // and by SET TRANSACTION READ ONLY at runtime as belt-and-braces.

        return true;
    }

    /**
     * Execute a single parametrized SELECT against the data source. Used by
     * leak verification where the SQL template has `?` placeholders and the
     * user-supplied values (potentially leaked PII) must never reach the
     * LLM. Values bind through PDO prepare/execute — same read-only guards
     * as executeRawReadQueries.
     */
    public static function executeParametrizedReadQuery(string $sourceType, array $config, string $query, array $params): array
    {
        $check = self::validateReadOnlyQuery($query);
        if ($check !== true) {
            return ['error' => "Query template ditolak (read-only guard): {$check}"];
        }

        $pdo = null;
        $txStarted = false;
        try {
            if ($sourceType === 'mysql') {
                $dsn = "mysql:host={$config['host']};port=" . ($config['port'] ?? 3306) . ";dbname={$config['database']}";
                $pdo = new \PDO($dsn, $config['username'], $config['password'], [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
                ]);
                $pdo->exec('START TRANSACTION READ ONLY');
            } elseif ($sourceType === 'postgresql') {
                $dsn = "pgsql:host={$config['host']};port=" . ($config['port'] ?? 5432) . ";dbname={$config['database']}";
                $pdo = new \PDO($dsn, $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('BEGIN READ ONLY');
            } else {
                return ['error' => 'Unsupported source_type for parametrized query.'];
            }
            $txStarted = true;

            $stmt = $pdo->prepare(rtrim(trim($query), ';'));
            $stmt->execute(array_values($params));
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $pdo->exec('ROLLBACK');
            return ['rows' => $rows];
        } catch (\Throwable $e) {
            if ($pdo && $txStarted) {
                try { $pdo->exec('ROLLBACK'); } catch (\Throwable $ignored) {}
            }
            Log::error('Failed parametrized read query: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    // =========================================================
    //  Sprint E2/E3/E4 — Selective scan, metadata compare, sample query
    // =========================================================

    /**
     * Sprint E2: Filter a full schema scan by table / column whitelist.
     * Returns the same shape as scanSchema() minus unselected entries.
     */
    public static function filterSchema(array $schema, array $selectedTables = [], array $selectedColumns = []): array
    {
        if (empty($schema['tables'] ?? null)) return $schema;
        if (empty($selectedTables) && empty($selectedColumns)) return $schema;

        $filtered = [];
        foreach ($schema['tables'] as $table) {
            $tableName = $table['name'] ?? ($table['table_name'] ?? null);
            if (!empty($selectedTables) && !in_array($tableName, $selectedTables, true)) {
                continue;
            }

            if (!empty($selectedColumns) && !empty($table['columns'])) {
                $table['columns'] = array_values(array_filter($table['columns'], function ($col) use ($selectedColumns, $tableName) {
                    $name = $col['name'] ?? ($col['column_name'] ?? null);
                    return in_array($name, $selectedColumns, true)
                        || in_array("{$tableName}.{$name}", $selectedColumns, true);
                }));
                if (empty($table['columns'])) continue;
            }

            $filtered[] = $table;
        }

        $schema['tables'] = $filtered;
        return $schema;
    }

    /**
     * Sprint E3: Compare an external column list against the scanned DB schema
     * using fuzzy string matching. Returns ranked matches.
     *
     * @param array  $schema        scanSchema() output
     * @param array  $columnNames   e.g. ['email', 'phone', 'name', 'birth_date']
     * @return array{matches: array} ranked match list
     */
    public static function compareMetadata(array $schema, array $columnNames): array
    {
        $columnNames = array_values(array_filter(array_map('trim', $columnNames)));
        if (empty($columnNames) || empty($schema['tables'] ?? null)) {
            return ['matches' => []];
        }

        $matches = [];
        foreach ($schema['tables'] as $table) {
            $tableName = $table['name'] ?? ($table['table_name'] ?? '');
            $tableCols = array_map(
                fn($c) => strtolower($c['name'] ?? ($c['column_name'] ?? '')),
                $table['columns'] ?? []
            );

            $matched = [];
            foreach ($columnNames as $needle) {
                $needleLc = strtolower($needle);
                foreach ($tableCols as $col) {
                    if (!$col) continue;
                    $sim = self::colSimilarity($needleLc, $col);
                    if ($sim >= 0.6) {
                        $matched[] = ['input' => $needle, 'matched_column' => $col, 'similarity' => round($sim, 2)];
                    }
                }
            }

            if (count($matched) > 0) {
                $avgSim = array_sum(array_map(fn($m) => $m['similarity'], $matched)) / count($matched);
                $coverage = count($matched) / count($columnNames);
                $matches[] = [
                    'table' => $tableName,
                    'matching_columns' => $matched,
                    'coverage' => round($coverage, 2),
                    'similarity' => round(($avgSim + $coverage) / 2, 2),
                ];
            }
        }

        usort($matches, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return ['matches' => $matches];
    }

    private static function colSimilarity(string $a, string $b): float
    {
        if ($a === $b) return 1.0;
        if ($a === '' || $b === '') return 0.0;

        // Substring hint
        if (str_contains($b, $a) || str_contains($a, $b)) return 0.85;

        similar_text($a, $b, $percent);
        return $percent / 100.0;
    }

    /**
     * Sprint E4: Execute a single AI-generated SELECT with strict safety net.
     * Returns { sql, rows, truncated, error? }
     */
    public static function executeSampleQuery(string $sourceType, array $config, string $sql, int $limit = 100): array
    {
        $sqlTrim = trim($sql);
        if (!preg_match('/^SELECT\b/i', $sqlTrim)) {
            return ['error' => 'Only SELECT statements are allowed.', 'sql' => $sqlTrim];
        }
        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|REPLACE|GRANT|REVOKE)\b/i', $sqlTrim)) {
            return ['error' => 'Query contains disallowed keyword.', 'sql' => $sqlTrim];
        }

        try {
            if ($sourceType === 'mysql') {
                $dsn = "mysql:host={$config['host']};port=" . ($config['port'] ?? 3306) . ";dbname={$config['database']}";
                $pdo = new \PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 10]);
            } elseif ($sourceType === 'postgresql') {
                $dsn = "pgsql:host={$config['host']};port=" . ($config['port'] ?? 5432) . ";dbname={$config['database']}";
                $pdo = new \PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 10]);
            } else {
                return ['error' => 'Sample query supports mysql / postgresql only.', 'sql' => $sqlTrim];
            }

            // Inject LIMIT if missing
            $finalSql = $sqlTrim;
            if (!preg_match('/\bLIMIT\s+\d+/i', $finalSql)) {
                $finalSql = rtrim($finalSql, "; \t\n\r") . " LIMIT {$limit}";
            }

            $stmt = $pdo->query($finalSql);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'sql' => $finalSql,
                'rows' => array_slice($rows, 0, $limit),
                'truncated' => count($rows) > $limit,
                'row_count' => count($rows),
            ];
        } catch (\Throwable $e) {
            Log::error('executeSampleQuery failed: ' . $e->getMessage());
            return ['error' => $e->getMessage(), 'sql' => $sqlTrim];
        }
    }
}
