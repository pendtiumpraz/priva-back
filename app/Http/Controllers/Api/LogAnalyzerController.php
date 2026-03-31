<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemLogAnalysis;
use App\Services\AiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class LogAnalyzerController extends Controller
{
    /**
     * Get recent log analyses and the current raw log
     */
    public function index(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $logPath = storage_path('logs/laravel.log');
        $rawLogs = '';

        if (File::exists($logPath)) {
            // Read last 50 lines safely without memory overflow
            $file = new \SplFileObject($logPath, 'r');
            $file->seek(PHP_INT_MAX);
            $totalLines = $file->key();
            $startLine = max(0, $totalLines - 100);

            $file->seek($startLine);
            while (!$file->eof()) {
                $rawLogs .= $file->current();
                $file->next();
            }
        }

        $history = SystemLogAnalysis::with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'raw_logs' => $rawLogs,
            'history' => $history
        ]);
    }

    /**
     * Analyze the logs using AI
     */
    public function analyze(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $logPath = storage_path('logs/laravel.log');
        $rawLogs = '';

        if (!File::exists($logPath)) {
            return response()->json(['message' => 'Log file not found'], 404);
        }

        $file = new \SplFileObject($logPath, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();
        $startLine = max(0, $totalLines - 100);

        $file->seek($startLine);
        while (!$file->eof()) {
            $rawLogs .= $file->current();
            $file->next();
        }

        try {
            $prompt = "Tolong periksa cuplikan Laravel backend log terbaru (sekitar 100 baris terakhir) di bawah ini. " .
                      "Buatkan analisa mendalam menggunakan bahasa Indonesia, formatlah balasan kamu murni dalam JSON (HANYA JSON OBJECT, tidak ada markdown codeblock). " .
                      "Penting: Return harus memiliki 4 field berikut: " .
                      "\n1. 'kesimpulan' (string - kesimpulan riwayat error)," .
                      "\n2. 'status' (string - 'sehat', 'peringatan', atau 'kritis')," .
                      "\n3. 'solusi_langkah_langkah' (array of strings - langkah penyelesaian masalah)," .
                      "\n4. 'file_yang_harus_diperiksa' (array of strings - path/nama file yang kemungkinan menyebabkan error jika ada)." .
                      "\n\nBerikut lognya:\n" . substr($rawLogs, -8000); // Send the last 8000 chars roughly

            $aiService = new AiService();
            // Assuming tenant configuration does not strictly apply to superadmin, but we need an active provider
            $response = $aiService->ask($prompt, "", 2000);

            // AiService returns parsed array or raw text in ['raw' => ...]
            if (isset($response['raw'])) {
                // Manually parse if it didn't
                $jsonStr = $response['raw'];
                if (preg_match('/\{.*\}/s', $response['raw'], $matches)) {
                    $jsonStr = $matches[0];
                }
                $analysisData = json_decode($jsonStr, true);
            } else {
                $analysisData = $response;
            }

            // Create record
            $analysis = SystemLogAnalysis::create([
                'raw_log_snippet' => $rawLogs,
                'ai_analysis' => is_array($analysisData) ? $analysisData : ['raw_response' => $response],
                'status' => is_array($analysisData) ? 'success' : 'failed',
                'error_message' => is_array($analysisData) ? null : 'Failed to parse AI response into JSON',
                'created_by' => $request->user()->id,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $analysis
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
