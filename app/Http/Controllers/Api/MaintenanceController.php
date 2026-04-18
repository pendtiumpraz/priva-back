<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\File;

class MaintenanceController extends Controller
{
    /**
     * Get all available seeeder files
     */
    public function getSeeders(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'root') {
            return response()->json(['message' => 'Forbidden Access.'], 403);
        }

        $seederPath = database_path('seeders');
        $seeders = [];

        if (File::exists($seederPath)) {
            $files = File::files($seederPath);
            foreach ($files as $file) {
                // Ignore DatabaseSeeder.php since it's the root/default, but we can include it actually.
                $filename = $file->getFilenameWithoutExtension();
                $seeders[] = $filename;
            }
        }

        return response()->json([
            'seeders' => $seeders
        ]);
    }
    /**
     * Execute a shell command via SuperAdmin authentication
     */
    public function execute(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'root') {
            return response()->json(['message' => 'Forbidden Access. System Level Required.'], 403);
        }

        $request->validate([
            'password' => 'required|string',
            'command' => 'required|string',
        ]);

        // Verify password
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Password Verifikasi Salah!'
            ], 401);
        }

        $commandRaw = $request->input('command');

        // Block highly destructive or interactive commands optionally if needed
        // but user requested full access like cat, grep, php artisan, etc.
        // E.g. restricting `nano`, `vi`, `top` because they require a TTY
        if (preg_match('/^(nano|vi|vim|top|htop|mysql|psql)\b/i', $commandRaw)) {
            return response()->json([
                'status' => 'error',
                'output' => "Perintah interaktif seperti {$commandRaw} tidak didukung di Web Terminal ini."
            ]);
        }

        // Set timeout to 120 seconds (2 minutes) to prevent zombie processes
        $process = Process::fromShellCommandline($commandRaw, base_path());
        $process->setTimeout(120);

        try {
            $process->run();

            $output = $process->getOutput();
            $errorOutput = $process->getErrorOutput();

            return response()->json([
                'status' => $process->isSuccessful() ? 'success' : 'error',
                'output' => $output ?: $errorOutput ?: 'Command executed successfully (no output).',
                'exit_code' => $process->getExitCode()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'output' => 'Exception Caught: ' . $e->getMessage(),
                'exit_code' => -1
            ]);
        }
    }
}
