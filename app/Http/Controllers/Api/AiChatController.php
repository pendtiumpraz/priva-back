<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\KnowledgeBaseSection;
use App\Models\License;
use App\Services\AiContentSanitizer;
use App\Services\ChatSummaryMemoryService;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AiChatController extends Controller
{
    /**
     * License gate untuk AI Chat. SuperAdmin/root bypass.
     * P0 fix dari audit AI security — sebelumnya Surface 2 (Chat Widget)
     * tidak punya license check, basic tier user bisa akses fitur Pro.
     */
    private function checkAiChatLicense(Request $request): bool
    {
        $user = $request->user();
        if (! $user || ! $user->org_id || in_array($user->role, ['root', 'superadmin'], true)) {
            return true;
        }

        $license = License::where('org_id', $user->org_id)
            ->where('status', 'active')
            ->first();

        return $license && $license->package_type !== 'basic';
    }

    private function denyBasic()
    {
        return response()->json([
            'message' => 'Fitur AI Chat hanya tersedia untuk paket Pro AI dan Enterprise.',
            'upgrade_required' => true,
        ], 403);
    }

    /**
     * Chat with AI assistant — answers based on PRIVASIMU knowledge base only.
     */
    public function chat(Request $request)
    {
        // P0: License gate (Pro AI / Enterprise required)
        if (! $this->checkAiChatLicense($request)) {
            return $this->denyBasic();
        }

        $request->validate([
            'message' => 'required|string|max:2000',
            'history' => 'nullable|array',
            'conversation_id' => 'nullable|string',
        ]);

        // Get active provider config (chat mode), fallback to legacy DeepSeek
        $orgId = $request->user()->org_id;
        $providerConfig = AiProviderController::getActiveConfig($orgId, 'chat');

        if (! $providerConfig || empty($providerConfig['api_key'])) {
            return response()->json(['message' => 'API key belum dikonfigurasi. Silakan set AI Provider terlebih dahulu.'], 503);
        }

        $apiKey = $providerConfig['api_key'];
        $chatModel = $providerConfig['model']->model_id;
        $chatBaseUrl = rtrim($providerConfig['base_url'], '/');
        $chatAuthHeader = $providerConfig['auth_header'] ?: 'Authorization';
        $chatAuthPrefix = ($providerConfig['auth_header'] && ! ($providerConfig['auth_prefix'] ?? '')) ? '' : ($providerConfig['auth_prefix'] ?: 'Bearer');

        // Credit check (skip for SuperAdmin — no org to bill)
        $orgId = $request->user()->org_id;
        if ($orgId) {
            CreditService::resetIfNeeded($orgId);
            if (! CreditService::hasCredit($orgId, 'chat')) {
                return response()->json([
                    'message' => 'Quota AI chat Anda habis bulan ini.',
                    'credits_exhausted' => true,
                ], 402);
            }
        }

        $userMessage = $request->message;
        $knowledgeBase = $this->getKnowledgeBase($userMessage, $request->user()->org_id ?? null);
        $history = $request->history ?? [];
        $user = $request->user();

        // Get or create conversation
        // P0 fix: scope by user_id AND org_id supaya tidak bisa cross-tenant
        // access kalau attacker dapat conversation_id milik Org lain.
        $conversation = null;
        if ($request->conversation_id) {
            $query = ChatConversation::where('id', $request->conversation_id)
                ->where('user_id', $user->id);
            if ($user->org_id) {
                $query->where('org_id', $user->org_id);
            }
            $conversation = $query->first();
        }
        if (! $conversation) {
            $conversation = ChatConversation::create([
                'user_id' => $user->id,
                'org_id' => $user->org_id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'status' => 'open',
                'last_message_at' => now(),
            ]);
        }

        // Save user message
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
            'sender_name' => $user->name,
        ]);

        // Check for pending admin messages
        $adminMessages = ChatMessage::where('conversation_id', $conversation->id)
            ->where('role', 'admin')
            ->where('created_at', '>', now()->subMinutes(5))
            ->orderBy('created_at', 'desc')
            ->first();

        // If admin is actively chatting, don't call AI
        if ($conversation->status === 'admin_active' && $adminMessages) {
            $conversation->update(['last_message_at' => now()]);

            return response()->json([
                'reply' => null,
                'conversation_id' => $conversation->id,
                'admin_active' => true,
            ]);
        }

        $systemPrompt = <<<PROMPT
Kamu adalah PRIVASIMU Assistant — asisten AI khusus untuk platform kepatuhan data pribadi PRIVASIMU berdasarkan UU Pelindungan Data Pribadi (UU No. 27 Tahun 2022).

ATURAN KETAT:
1. Kamu HANYA boleh menjawab pertanyaan yang berkaitan dengan penggunaan platform PRIVASIMU dan UU PDP Indonesia.
2. Jika user bertanya di luar konteks PRIVASIMU atau UU PDP, tolak dengan sopan.
3. JANGAN PERNAH mengungkapkan teknologi yang dipakai (framework, database, bahasa pemrograman, API, dsb).
4. Jika ditanya soal teknologi/stack: "Informasi teknis internal bersifat rahasia."
5. Jawab dalam Bahasa Indonesia yang profesional dan mudah dipahami.

FORMAT OUTPUT WAJIB:
Kamu WAJIB membalas dalam format JSON valid dengan struktur berikut:
{
  "greeting": "Kalimat pembuka singkat (opsional, boleh null)",
  "sections": [
    {
      "type": "text|steps|list|tip|warning|info|table|code",
      "title": "Judul section (opsional)",
      "content": "Isi teks/deskripsi (untuk type text/tip/warning/info/code)",
      "items": ["Item 1", "Item 2"],
      "table_data": [{"Kolom A": "value", "Kolom B": "value"}],
      "headers": ["Kolom A", "Kolom B"],
      "language": "sql (untuk type code, opsional)"
    }
  ],
  "closing": "Kalimat penutup singkat (opsional, boleh null)",
  "summary": "Ringkasan 20-30 kata Bahasa Indonesia tentang topik dan keputusan turn ini (WAJIB diisi untuk memory percakapan, tidak ditampilkan ke user)"
}

TIPE SECTION:
- "text": paragraf biasa
- "steps": langkah-langkah bernomor (gunakan "items" array)
- "list": daftar bullet points (gunakan "items" array)
- "tip": tips/saran (highlight hijau)
- "warning": peringatan penting (highlight kuning)
- "info": informasi tambahan (highlight biru)
- "table": tabel perbandingan/data (WAJIB pakai "table_data" array of object, optional "headers" untuk urutan kolom). JANGAN embed table di "content" sebagai markdown.
- "code": code block / SQL / JSON snippet (content = raw code, opsional "language")

CONTOH TABEL YANG BENAR:
{"type":"table","title":"Perbandingan A vs B","table_data":[{"Aspek":"Speed","A":"Cepat","B":"Lambat"},{"Aspek":"Cost","A":"Gratis","B":"Mahal"}]}

JANGAN gunakan markdown (**bold**, *italic*, #heading, |tabel|). HANYA JSON murni.
Jangan bungkus JSON dalam code block. Langsung response JSON mentah.

KNOWLEDGE BASE PRIVASIMU:
{$knowledgeBase}

Berdasarkan knowledge base di atas, bantu user memahami dan menggunakan platform PRIVASIMU dengan baik. Selalu balas dalam format JSON di atas.
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Build history pakai Summary Buffer Memory pattern:
        // - Older messages: compressed jadi 1 system "[Ringkasan]" line
        // - Recent 10 messages: full content (sanitized)
        // User message current sudah saved di line 117, jadi sudah include
        // sebagai message terakhir di buildHistoryMessages (no duplikat).
        $historyMessages = ChatSummaryMemoryService::buildHistoryMessages($conversation->id);
        $messages = array_merge($messages, $historyMessages);

        try {
            $headers = ['Content-Type' => 'application/json'];
            if ($chatAuthPrefix) {
                $headers[$chatAuthHeader] = $chatAuthPrefix.' '.$apiKey;
            } else {
                $headers[$chatAuthHeader] = $apiKey;
            }

            // Clamp max_tokens via output guard supaya tidak bisa over-ride
            // hard cap (default 4000). 1500 di bawah cap default, tapi clamp
            // jaga konsistensi kalau admin set cap lebih ketat.
            $outputGuard = app(\App\Services\AiOutputGuard::class);
            $maxTokens = $outputGuard->clampMaxTokens(1500);

            $response = Http::withOptions([
                'timeout' => 60,
                'connect_timeout' => 15,
            ])
                ->withoutVerifying()
                ->withHeaders($headers)
                ->post($chatBaseUrl.'/chat/completions', [
                    'model' => $chatModel,
                    'messages' => $messages,
                    'temperature' => 0.3,
                    'max_tokens' => $maxTokens,
                ]);

            if ($response->failed()) {
                \Log::error('AI Provider API error ['.$chatModel.']: '.$response->body());

                return response()->json(['message' => 'AI API Error ['.$chatModel.']: '.substr($response->body(), 0, 500), 'conversation_id' => $conversation->id], 502);
            }

            $data = $response->json();
            $reply = $data['choices'][0]['message']['content'] ?? 'Maaf, tidak ada respons.';

            // Output safety guard — tolak respons yang melewati batas total,
            // mengandung pola berulang, atau baris terlalu panjang. Mencegah
            // skenario user paksa AI tulis "AAA..." 1jt baris.
            if (! $outputGuard->isSafe($reply)) {
                \Log::warning('AI Chat Output Guard rejected response ['.$chatModel.']', [
                    'length' => mb_strlen($reply),
                ]);

                return response()->json([
                    'message' => 'Respons AI ditolak karena melebihi batas atau mengandung pola tidak wajar. Silakan coba pertanyaan yang lebih spesifik.',
                    'conversation_id' => $conversation->id,
                    'output_rejected' => true,
                ], 422);
            }

            // Parse summary dari JSON response — disimpan untuk summary memory
            // pattern. FE tetap render full `reply` (greeting/sections/closing).
            $extractedSummary = null;
            $decodedReply = json_decode($reply, true);
            if (is_array($decodedReply) && isset($decodedReply['summary'])) {
                $extractedSummary = (string) $decodedReply['summary'];
            }

            // Save AI reply dengan summary + token usage (granular tracking per chat)
            $usage = $data['usage'] ?? [];
            ChatMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $reply,
                'summary' => $extractedSummary,
                'sender_name' => 'PRIVASIMU AI',
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
                'total_tokens' => $usage['total_tokens'] ?? null,
                'provider' => $providerConfig['provider']->name ?? null,
                'model' => $chatModel,
            ]);

            $conversation->update(['last_message_at' => now()]);

            // Deduct chat credit on success
            if ($orgId) {
                CreditService::deduct($orgId, $user->id, 'chat', 'chat');
            }

            return response()->json([
                'reply' => $reply,
                'conversation_id' => $conversation->id,
                'usage' => $data['usage'] ?? null,
            ]);
        } catch (\Exception $e) {
            \Log::error('AI Chat error: '.$e->getMessage());

            return response()->json(['message' => 'Terjadi kesalahan. Coba lagi nanti.', 'conversation_id' => $conversation->id], 500);
        }
    }

    /**
     * Get/Update knowledge base (SuperAdmin only for update)
     */
    public function knowledgeBase(Request $request)
    {
        if ($request->isMethod('GET')) {
            // Load all sections from knowledge_base_sections table
            $sections = KnowledgeBaseSection::where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            if ($sections->count() > 0) {
                $kb = '';
                foreach ($sections as $section) {
                    $kb .= "# {$section->title}\n{$section->content}\n\n---\n\n";
                }

                return response()->json([
                    'data' => trim($kb),
                    'sections' => $sections,
                    'source' => 'database',
                ]);
            }

            // Fallback to app_settings
            $kb = AppSetting::get('knowledge_base', $this->getDefaultKnowledgeBase());

            return response()->json(['data' => $kb, 'source' => 'app_settings']);
        }

        // PUT — update
        $user = $request->user();
        if (! in_array($user->role, ['root', 'superadmin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['content' => 'required|string']);
        AppSetting::set('knowledge_base', $request->input('content'));

        return response()->json(['message' => 'Knowledge base updated']);
    }

    /**
     * Get/Set DeepSeek API key (SuperAdmin only)
     */
    public function apiSettings(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, ['root', 'superadmin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($request->isMethod('GET')) {
            $key = AppSetting::get('deepseek_api_key', '');

            return response()->json([
                'has_key' => ! empty($key),
                'key_preview' => $key ? substr($key, 0, 8).'...'.substr($key, -4) : null,
            ]);
        }

        // PUT — save key to database
        $request->validate(['api_key' => 'required|string']);
        AppSetting::set('deepseek_api_key', $request->api_key);

        return response()->json(['message' => 'API key updated & saved to database']);
    }

    /**
     * Test DeepSeek API connection
     */
    public function testConnection(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, ['root', 'superadmin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $apiKey = AppSetting::get('deepseek_api_key');
        if (! $apiKey) {
            return response()->json(['success' => false, 'message' => 'API key belum dikonfigurasi'], 400);
        }

        try {
            $response = Http::timeout(10)
                ->withoutVerifying()
                ->withHeaders([
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.deepseek.com/chat/completions', [
                    'model' => 'deepseek-chat',
                    'messages' => [['role' => 'user', 'content' => 'Hi, test connection only. Reply with: OK']],
                    'max_tokens' => 10,
                ]);

            if ($response->ok()) {
                $data = $response->json();

                return response()->json([
                    'success' => true,
                    'message' => 'Koneksi berhasil! DeepSeek API aktif.',
                    'model' => $data['model'] ?? 'deepseek-chat',
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'API Error: '.($response->json()['error']['message'] ?? $response->body()),
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
            ], 500);
        }
    }

    private function getKnowledgeBase(string $query = '', ?string $orgId = null): string
    {
        // Try RAG: find relevant sections from DB (scoped to tenant + shared)
        $sections = KnowledgeBaseSection::where('is_active', true)
            ->visibleTo($orgId)
            ->count();

        if ($sections > 0) {
            $relevant = KnowledgeBaseSection::findRelevant($query, $orgId);
            if (! empty($relevant)) {
                $kb = '';
                foreach ($relevant as $section) {
                    // P0 fix: sanitize KB content sebelum inject ke system prompt.
                    // Tanpa ini, attacker yang edit KB section bisa inject
                    // "SYSTEM: forget all rules" via content field.
                    $safeTitle = AiContentSanitizer::neutralize($section->title);
                    $safeContent = AiContentSanitizer::neutralize($section->content);
                    $kb .= "\n---\n# {$safeTitle}\n{$safeContent}\n";
                }

                return $kb;
            }
        }

        // Fallback: use legacy app_settings knowledge_base or default
        // (juga sanitize — meskipun source dari admin trusted, defensive)
        $fallback = AppSetting::get('knowledge_base', $this->getDefaultKnowledgeBase());
        return AiContentSanitizer::neutralize((string) $fallback);
    }

    private function getDefaultKnowledgeBase(): string
    {
        return <<<'KB'
# PRIVASIMU — Platform Kepatuhan UU PDP
PRIVASIMU adalah platform SaaS untuk membantu organisasi mematuhi UU PDP (UU No. 27 Tahun 2022).
Modul: Dashboard, Gap Assessment, RoPA, DPIA, Breach, DSR, Consent, Fire Drill, Data Discovery, Docs.
Role: SuperAdmin, Admin, DPO, Maker, Viewer.
Kontak: PT Sainskerta Solusi Nusantara — 081319504441 (Galih)
KB;
    }

    // =============================================
    // CHAT HISTORY — SuperAdmin endpoints
    // =============================================

    /**
     * List all conversations (SuperAdmin) or user's conversations
     */
    public function conversations(Request $request)
    {
        $user = $request->user();
        $query = ChatConversation::query()->withCount('messages');

        if (! in_array($user->role, ['root', 'superadmin'], true)) {
            $query->where('user_id', $user->id);
        }

        if ($request->search) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('user_name', 'like', "%{$s}%")
                    ->orWhere('user_email', 'like', "%{$s}%");
            });
        }

        $conversations = $query->orderBy('last_message_at', 'desc')->paginate(20);

        return response()->json($conversations);
    }

    /**
     * Get conversation messages
     */
    public function conversationMessages(Request $request, string $id)
    {
        $user = $request->user();
        $conversation = ChatConversation::with('messages')->findOrFail($id);

        // Only owner or superadmin can view
        if (! in_array($user->role, ['root', 'superadmin'], true) && $conversation->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json(['data' => $conversation]);
    }

    /**
     * SuperAdmin sends a reply to a user's conversation
     */
    public function adminReply(Request $request, string $id)
    {
        $user = $request->user();
        if (! in_array($user->role, ['root', 'superadmin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['message' => 'required|string|max:2000']);

        $conversation = ChatConversation::findOrFail($id);
        $conversation->update(['status' => 'admin_active', 'last_message_at' => now()]);

        $msg = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'admin',
            'content' => $request->message,
            'sender_name' => $user->name.' (Admin)',
        ]);

        return response()->json(['message' => 'Sent', 'data' => $msg]);
    }

    /**
     * Get new messages for polling (user side)
     */
    public function pollMessages(Request $request, string $id)
    {
        $user = $request->user();
        $conversation = ChatConversation::findOrFail($id);

        if (! in_array($user->role, ['root', 'superadmin'], true) && $conversation->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $after = $request->after; // ISO timestamp
        $query = ChatMessage::where('conversation_id', $id);
        if ($after) {
            $query->where('created_at', '>', $after);
        }

        $messages = $query->orderBy('created_at')->get();

        return response()->json(['data' => $messages]);
    }
}
