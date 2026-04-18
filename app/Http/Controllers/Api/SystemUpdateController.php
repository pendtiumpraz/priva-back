<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SystemUpdateController extends Controller
{
    /**
     * Check for available updates by fetching from origin and reading git log.
     */
    public function checkUpdate(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'root') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $basePath = base_path();
        
        try {
            // Fetch origin to update remote tracking branch so we know what's new
            shell_exec("cd {$basePath} && git fetch origin main 2>&1");
            
            // Get commits that are in origin/main but NOT in our local HEAD
            $logOutput = shell_exec("cd {$basePath} && git log HEAD..origin/main --pretty=format:\"%h|%s|%cd\" --date=short 2>&1");
            
            // Parse function helper
            $parseLog = function($out) {
                $res = [];
                if (!$out || trim($out) === '') return $res;
                $lines = explode("\n", trim($out));
                foreach ($lines as $line) {
                    if (empty(trim($line))) continue;
                    $parts = explode('|', $line, 3);
                    if (count($parts) === 3) {
                        $res[] = [
                            'hash' => trim($parts[0]),
                            'message' => trim($parts[1]),
                            'date' => trim($parts[2]),
                        ];
                    }
                }
                return $res;
            };

            $pendingCommits = $parseLog($logOutput);

            // Pagination for installed history
            $pageParam = $request->query('page', 1);
            $totalOutput = shell_exec("cd {$basePath} && git rev-list --count HEAD 2>&1");
            $totalCommits = (int)trim($totalOutput);

            if ($pageParam === 'all') {
                $installedOutput = shell_exec("cd {$basePath} && git log --pretty=format:\"%h|%s|%cd\" --date=short 2>&1");
                $installedCommits = $parseLog($installedOutput);
                
                return response()->json([
                    'up_to_date' => count($pendingCommits) === 0,
                    'commits' => $pendingCommits,
                    'installed' => $installedCommits,
                    'history_total' => $totalCommits,
                    'history_page' => 'all',
                    'history_pages' => 1,
                ]);
            }

            $page = (int)$pageParam;
            $perPage = 10;
            $skip = ($page - 1) * $perPage;

            // Get paginated installed commits locally
            $installedOutput = shell_exec("cd {$basePath} && git log --skip={$skip} -n {$perPage} --pretty=format:\"%h|%s|%cd\" --date=short 2>&1");
            $installedCommits = $parseLog($installedOutput);

            return response()->json([
                'up_to_date' => count($pendingCommits) === 0,
                'commits' => $pendingCommits,
                'installed' => $installedCommits,
                'history_total' => $totalCommits,
                'history_page' => $page,
                'history_pages' => ceil($totalCommits / $perPage),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengecek update.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Execute a git pull and migrate on the local server.
     * Only accessible by Superadmin.
     */
    public function updateBackend(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'root') {
            return response()->json(['message' => 'Unauthorized. Only Superadmin can update.'], 403);
        }

        $basePath = base_path();
        
        // Disable execution time limit for this request
        set_time_limit(300);

        // Ensure COMPOSER_HOME is set (Linux PHP-FPM often has no HOME)
        $homeDir = getenv('HOME') ?: (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['dir'] : '/tmp');
        putenv("HOME={$homeDir}");
        putenv("COMPOSER_HOME={$homeDir}/.composer");
        
        $output = [];

        try {
            // Git pull
            $gitOutput = shell_exec("cd {$basePath} && git pull origin main 2>&1");
            $output[] = "--- GIT PULL ---";
            $output[] = $gitOutput ?? "No output from git";

            // Composer install
            $composerOutput = shell_exec("cd {$basePath} && HOME={$homeDir} COMPOSER_HOME={$homeDir}/.composer composer install --no-dev --optimize-autoloader --ignore-platform-reqs 2>&1");
            $output[] = "\n--- COMPOSER INSTALL ---";
            $output[] = $composerOutput ?? "No output from composer";

            // Migrate
            $migrateOutput = shell_exec("cd {$basePath} && php artisan migrate --force 2>&1");
            $output[] = "\n--- DB MIGRATE ---";
            $output[] = $migrateOutput ?? "No output from migrate";

            // Optimize clear
            $optimizeOutput = shell_exec("cd {$basePath} && php artisan optimize:clear 2>&1");
            $output[] = "\n--- OPTIMIZE CLEAR ---";
            $output[] = $optimizeOutput ?? "No output from optimize";

            // Log it
            Log::info('System Auto-Update triggered by ' . $user->email);
            Log::info(implode("\n", $output));

            return response()->json([
                'message' => 'Update berhasil dieksekusi',
                'log' => implode("\n", $output)
            ]);

        } catch (\Exception $e) {
            Log::error('Auto-Update failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Update gagal dieksekusi',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Checkout / switch to a specific version (commit) safely.
     */
    public function checkoutVersion(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'root') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'password' => 'required|string',
            'commit_hash' => 'required|string|min:7|max:40'
        ]);

        // Verifikasi password superadmin
        if (!\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Password Superadmin salah.'
            ], 401);
        }

        $basePath = base_path();
        
        try {
            $hash = escapeshellarg($request->commit_hash); // secure hash
            
            // Verifikasi bahwa hash ada dalam 10 history terakhir (page 1) untuk safety
            $recentCommitsStr = shell_exec("cd {$basePath} && git log -n 10 --pretty=format:\"%h\" 2>&1");
            $recentCommits = explode("\n", trim($recentCommitsStr));
            
            $isSafe = false;
            foreach ($recentCommits as $h) {
                if (str_starts_with(trim($h), $request->commit_hash) || str_starts_with($request->commit_hash, trim($h))) {
                    $isSafe = true;
                    break;
                }
            }

            if (!$isSafe) {
                return response()->json([
                    'message' => 'Downgrade terlalu jauh tidak diizinkan. Hanya bisa berpindah di antara 10 update terakhir untuk mencegah kerusakan database.'
                ], 403);
            }

            set_time_limit(300);
            $output = [];

            // Execute reset hard ke commit tertentu
            // Supaya tidak detached HEAD dan tetap di branch aktif, pakai git reset --hard
            $resetOutput = shell_exec("cd {$basePath} && git reset --hard {$hash} 2>&1");
            $output[] = "--- GIT RESET TO {$request->commit_hash} ---";
            $output[] = $resetOutput ?? "No output";

            $homeDir = getenv('HOME') ?: (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['dir'] : '/tmp');
            $composerOutput = shell_exec("cd {$basePath} && HOME={$homeDir} COMPOSER_HOME={$homeDir}/.composer composer install --no-dev --optimize-autoloader --ignore-platform-reqs 2>&1");
            $output[] = "\n--- COMPOSER INSTALL ---";
            $output[] = $composerOutput ?? "No output";

            // Optimize clear
            $optimizeOutput = shell_exec("cd {$basePath} && php artisan optimize:clear 2>&1");
            $output[] = "\n--- OPTIMIZE CLEAR ---";
            $output[] = $optimizeOutput ?? "No output";

            Log::info("System Switched Version to {$request->commit_hash} by {$user->email}");
            Log::info(implode("\n", $output));

            return response()->json([
                'message' => "Berhasil berpindah ke versi {$request->commit_hash}",
                'log' => implode("\n", $output)
            ]);

        } catch (\Exception $e) {
            Log::error('Checkout version failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Gagal berpindah versi',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  FRONTEND OTA (hybrid: shell OR webhook)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Report the current frontend deploy config + preflight so the UI can
     * show whether updates are possible without calling them.
     */
    public function frontendStatus(Request $request)
    {
        if (($request->user()->role ?? null) !== 'root') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $mode = strtolower((string) env('FRONTEND_DEPLOY_MODE', 'shell'));
        $path = (string) env('FRONTEND_PATH', '');
        $hookUrl = (string) env('FRONTEND_DEPLOY_HOOK_URL', '');
        $reloadCmd = (string) env('FRONTEND_RELOAD_CMD', '');

        $checks = [];
        if ($mode === 'shell') {
            $checks['path_configured'] = $path !== '';
            $checks['path_exists'] = $path !== '' && is_dir($path);
            $checks['git_repo'] = ($checks['path_exists'] ?? false) && is_dir(rtrim($path, '/') . '/.git');
            $checks['npm_available'] = $this->resolveNpmBin() !== null;
            $checks['reload_cmd_configured'] = $reloadCmd !== '';
        } elseif ($mode === 'webhook') {
            $checks['hook_url_configured'] = $hookUrl !== '';
            $checks['hook_url_https'] = $hookUrl !== '' && str_starts_with($hookUrl, 'https://');
        } else {
            $checks['mode_invalid'] = false;
        }

        $ready = $mode === 'shell'
            ? ($checks['path_exists'] ?? false) && ($checks['git_repo'] ?? false) && ($checks['npm_available'] ?? false)
            : ($checks['hook_url_configured'] ?? false);

        $pendingCommits = [];
        $installedHead = null;
        if ($mode === 'shell' && ($checks['git_repo'] ?? false)) {
            try {
                $safePath = escapeshellarg($path);
                shell_exec("cd {$safePath} && git fetch origin main 2>&1");
                $logOut = shell_exec("cd {$safePath} && git log HEAD..origin/main --pretty=format:\"%h|%s|%cd\" --date=short 2>&1");
                $pendingCommits = $this->parseGitLog($logOut);
                $headOut = shell_exec("cd {$safePath} && git log -1 --pretty=format:\"%h|%s|%cd\" --date=short 2>&1");
                $installedHead = $this->parseGitLog($headOut)[0] ?? null;
            } catch (\Throwable $e) {
                Log::warning('Frontend status git probe failed: ' . $e->getMessage());
            }
        }

        return response()->json([
            'mode' => $mode,
            'ready' => $ready,
            'checks' => $checks,
            'path' => $path ?: null,
            'hook_url_masked' => $hookUrl ? preg_replace('/^(https?:\/\/[^\/]+).*/', '$1/…', $hookUrl) : null,
            'pending_commits' => $pendingCommits,
            'installed_head' => $installedHead,
            'up_to_date' => count($pendingCommits) === 0,
        ]);
    }

    /**
     * Fire the configured frontend deploy. Dispatches by FRONTEND_DEPLOY_MODE.
     */
    public function updateFrontend(Request $request)
    {
        $user = $request->user();
        if (($user->role ?? null) !== 'root') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $mode = strtolower((string) env('FRONTEND_DEPLOY_MODE', 'shell'));

        try {
            if ($mode === 'shell') {
                return $this->updateFrontendShell($user);
            }
            if ($mode === 'webhook') {
                return $this->updateFrontendWebhook($user);
            }
            return response()->json([
                'message' => "FRONTEND_DEPLOY_MODE '{$mode}' tidak dikenal. Pilih 'shell' atau 'webhook'.",
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Frontend update failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Frontend update gagal',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function updateFrontendShell($user)
    {
        $path = (string) env('FRONTEND_PATH', '');
        $reloadCmd = (string) env('FRONTEND_RELOAD_CMD', '');

        if ($path === '' || !is_dir($path)) {
            return response()->json(['message' => 'FRONTEND_PATH tidak valid. Set env var ke folder frontend.'], 422);
        }
        if (!is_dir(rtrim($path, '/') . '/.git')) {
            return response()->json(['message' => 'Path frontend bukan git repo.'], 422);
        }

        set_time_limit(900);
        $homeDir = getenv('HOME') ?: (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['dir'] : '/tmp');
        putenv("HOME={$homeDir}");
        $safePath = escapeshellarg($path);
        $npm = $this->resolveNpmBin() ?: 'npm';
        $safeNpm = escapeshellarg($npm);
        $nodeBin = (string) env('NODE_BIN', '');
        $extraPath = '';
        if ($nodeBin !== '') $extraPath = 'PATH=' . escapeshellarg(dirname($nodeBin)) . ':$PATH ';

        $output = [];
        $output[] = "[env] HOME={$homeDir}";
        $output[] = "[env] NPM_BIN={$npm}";
        if ($nodeBin !== '') $output[] = "[env] NODE_BIN={$nodeBin}";

        $gitOut = shell_exec("cd {$safePath} && git pull origin main 2>&1");
        $output[] = "\n--- GIT PULL (frontend) ---";
        $output[] = $gitOut ?? "No output from git";

        // npm ci is reproducible; fall back to install if lockfile mismatch
        $npmOut = shell_exec("cd {$safePath} && {$extraPath}HOME={$homeDir} {$safeNpm} ci --no-audit --no-fund 2>&1");
        if (!$npmOut || stripos($npmOut, 'ERR') !== false) {
            $npmOut .= "\n[fallback] retrying with npm install…\n";
            $npmOut .= shell_exec("cd {$safePath} && {$extraPath}HOME={$homeDir} {$safeNpm} install --no-audit --no-fund 2>&1");
        }
        $output[] = "\n--- NPM INSTALL ---";
        $output[] = $npmOut ?? "No output from npm";

        $buildOut = shell_exec("cd {$safePath} && {$extraPath}HOME={$homeDir} {$safeNpm} run build 2>&1");
        $output[] = "\n--- NEXT BUILD ---";
        $output[] = $buildOut ?? "No output from build";

        if ($reloadCmd !== '') {
            $reloadOut = shell_exec("cd {$safePath} && {$reloadCmd} 2>&1");
            $output[] = "\n--- RELOAD ({$reloadCmd}) ---";
            $output[] = $reloadOut ?? "No output from reload";
        } else {
            $output[] = "\n[info] FRONTEND_RELOAD_CMD empty — skip reload. Restart the Next.js process manually if needed.";
        }

        $log = implode("\n", $output);
        Log::info("Frontend OTA by {$user->email}");
        Log::info($log);

        return response()->json(['message' => 'Frontend update berhasil', 'mode' => 'shell', 'log' => $log]);
    }

    private function updateFrontendWebhook($user)
    {
        $hookUrl = (string) env('FRONTEND_DEPLOY_HOOK_URL', '');
        if ($hookUrl === '') {
            return response()->json(['message' => 'FRONTEND_DEPLOY_HOOK_URL belum di-set.'], 422);
        }

        $headers = [];
        $secretHeader = (string) env('FRONTEND_DEPLOY_HOOK_HEADER', 'X-Deploy-Secret');
        $secret = (string) env('FRONTEND_DEPLOY_HOOK_SECRET', '');
        if ($secret !== '') $headers[$secretHeader] = $secret;

        $method = strtoupper((string) env('FRONTEND_DEPLOY_HOOK_METHOD', 'POST'));

        $client = Http::timeout(60)->withoutVerifying();
        if (!empty($headers)) $client = $client->withHeaders($headers);

        $payload = [
            'triggered_by' => $user->email ?? 'root',
            'timestamp' => now()->toIso8601String(),
        ];
        $response = $method === 'GET' ? $client->get($hookUrl, $payload) : $client->post($hookUrl, $payload);

        $log = "--- WEBHOOK TRIGGER ---\n" .
            "URL: " . preg_replace('/^(https?:\/\/[^\/]+).*/', '$1/…', $hookUrl) . "\n" .
            "Status: {$response->status()}\n" .
            "Body: " . substr($response->body(), 0, 1000);

        Log::info("Frontend webhook deploy by {$user->email}: {$response->status()}");

        if ($response->failed()) {
            return response()->json([
                'message' => 'Webhook response non-success',
                'mode' => 'webhook',
                'status' => $response->status(),
                'log' => $log,
            ], 502);
        }

        return response()->json([
            'message' => 'Webhook triggered. Deploy berjalan di platform hosting frontend.',
            'mode' => 'webhook',
            'status' => $response->status(),
            'log' => $log,
        ]);
    }

    private function binaryAvailable(string $bin): bool
    {
        $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
        $out = @shell_exec("{$which} " . escapeshellarg($bin) . " 2>&1");
        return is_string($out) && trim($out) !== '';
    }

    /**
     * Resolve npm binary: prefer env NPM_BIN if set and executable; otherwise
     * look in PATH; otherwise try common nvm/node locations.
     */
    private function resolveNpmBin(): ?string
    {
        $explicit = (string) env('NPM_BIN', '');
        if ($explicit !== '' && is_file($explicit) && is_executable($explicit)) return $explicit;

        if ($this->binaryAvailable('npm')) return 'npm';

        $homeDir = getenv('HOME') ?: '';
        $candidates = [
            '/usr/bin/npm', '/usr/local/bin/npm', '/opt/homebrew/bin/npm',
        ];
        if ($homeDir) {
            foreach (glob($homeDir . '/.nvm/versions/node/*/bin/npm') ?: [] as $p) {
                $candidates[] = $p;
            }
        }
        foreach ($candidates as $c) {
            if (is_file($c) && is_executable($c)) return $c;
        }
        return null;
    }

    private function parseGitLog(?string $out): array
    {
        $res = [];
        if (!$out || trim($out) === '') return $res;
        foreach (explode("\n", trim($out)) as $line) {
            if (trim($line) === '') continue;
            $parts = explode('|', $line, 3);
            if (count($parts) === 3) {
                $res[] = ['hash' => trim($parts[0]), 'message' => trim($parts[1]), 'date' => trim($parts[2])];
            }
        }
        return $res;
    }
}
