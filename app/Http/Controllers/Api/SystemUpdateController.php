<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SystemUpdateController extends Controller
{
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
