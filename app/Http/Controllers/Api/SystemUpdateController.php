<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SystemUpdateController extends Controller
{
    /**
     * Check for available updates by fetching from origin and reading git log.
     */
    public function checkUpdate(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'superadmin') {
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
        if ($user->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized. Only Superadmin can update.'], 403);
        }

        $basePath = base_path();
        
        // Disable execution time limit for this request
        set_time_limit(300);
        
        $output = [];

        try {
            // Git pull
            $gitOutput = shell_exec("cd {$basePath} && git pull origin main 2>&1");
            $output[] = "--- GIT PULL ---";
            $output[] = $gitOutput ?? "No output from git";

            // Composer install
            $composerOutput = shell_exec("cd {$basePath} && composer install --no-dev --optimize-autoloader 2>&1");
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
}
