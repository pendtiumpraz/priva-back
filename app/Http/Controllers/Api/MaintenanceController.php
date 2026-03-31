<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class MaintenanceController extends Controller
{
    /**
     * Execute a shell command via SuperAdmin authentication
     */
    public function execute(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'superadmin') {
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
