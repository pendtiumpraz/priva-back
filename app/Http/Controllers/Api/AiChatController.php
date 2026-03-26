<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class AiChatController extends Controller
{
    /**
     * Chat with AI assistant — answers based on PRIVASIMU knowledge base only.
     */
    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'history' => 'nullable|array',
            'conversation_id' => 'nullable|string',
        ]);

        $apiKey = AppSetting::get('deepseek_api_key');
        if (!$apiKey) {
            return response()->json(['message' => 'API key belum dikonfigurasi. Hubungi SuperAdmin.'], 503);
        }

        $knowledgeBase = $this->getKnowledgeBase();
        $userMessage = $request->message;
        $history = $request->history ?? [];
        $user = $request->user();

        // Get or create conversation
        $conversation = null;
        if ($request->conversation_id) {
            $conversation = ChatConversation::find($request->conversation_id);
        }
        if (!$conversation) {
            $conversation = ChatConversation::create([
                'user_id' => $user->id,
                'org_id' => $user->org_id ?? '',
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
2. Jika user bertanya di luar konteks PRIVASIMU atau UU PDP, tolak dengan sopan: "Maaf, saya hanya bisa membantu seputar penggunaan PRIVASIMU dan kepatuhan UU PDP."
3. JANGAN PERNAH mengungkapkan teknologi yang dipakai (framework, database, bahasa pemrograman, API, dsb).
4. Jika ditanya soal teknologi/stack: "Informasi teknis internal bersifat rahasia."
5. Jawab dalam Bahasa Indonesia yang profesional dan mudah dipahami.
6. Berikan jawaban yang praktis, step-by-step jika perlu.

KNOWLEDGE BASE PRIVASIMU:
{$knowledgeBase}

Berdasarkan knowledge base di atas, bantu user memahami dan menggunakan platform PRIVASIMU dengan baik.
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Add conversation history (max last 10)
        $historySlice = array_slice($history, -10);
        foreach ($historySlice as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $role = $msg['role'] === 'admin' ? 'assistant' : $msg['role'];
                $messages[] = ['role' => $role, 'content' => $msg['content']];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.deepseek.com/chat/completions', [
                    'model' => 'deepseek-chat',
                    'messages' => $messages,
                    'temperature' => 0.3,
                    'max_tokens' => 1500,
                ]);

            if ($response->failed()) {
                \Log::error('DeepSeek API error: ' . $response->body());
                return response()->json(['message' => 'AI sedang tidak tersedia. Coba lagi nanti.', 'conversation_id' => $conversation->id], 502);
            }

            $data = $response->json();
            $reply = $data['choices'][0]['message']['content'] ?? 'Maaf, tidak ada respons.';

            // Save AI reply
            ChatMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $reply,
                'sender_name' => 'PRIVASIMU AI',
            ]);

            $conversation->update(['last_message_at' => now()]);

            return response()->json([
                'reply' => $reply,
                'conversation_id' => $conversation->id,
                'usage' => $data['usage'] ?? null,
            ]);
        } catch (\Exception $e) {
            \Log::error('AI Chat error: ' . $e->getMessage());
            return response()->json(['message' => 'Terjadi kesalahan. Coba lagi nanti.', 'conversation_id' => $conversation->id], 500);
        }
    }

    /**
     * Get/Update knowledge base (SuperAdmin only for update)
     */
    public function knowledgeBase(Request $request)
    {
        if ($request->isMethod('GET')) {
            $kb = AppSetting::get('knowledge_base', $this->getDefaultKnowledgeBase());
            return response()->json(['data' => $kb]);
        }

        // PUT — update
        $user = $request->user();
        if ($user->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['content' => 'required|string']);
        AppSetting::set('knowledge_base', $request->content);

        return response()->json(['message' => 'Knowledge base updated']);
    }

    /**
     * Get/Set DeepSeek API key (SuperAdmin only)
     */
    public function apiSettings(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($request->isMethod('GET')) {
            $key = AppSetting::get('deepseek_api_key', '');
            return response()->json([
                'has_key' => !empty($key),
                'key_preview' => $key ? substr($key, 0, 8) . '...' . substr($key, -4) : null,
            ]);
        }

        // PUT — save key to database
        $request->validate(['api_key' => 'required|string']);
        AppSetting::set('deepseek_api_key', $request->api_key);

        return response()->json(['message' => 'API key updated & saved to database']);
    }

    private function getKnowledgeBase(): string
    {
        return AppSetting::get('knowledge_base', $this->getDefaultKnowledgeBase());
    }

    private function getDefaultKnowledgeBase(): string
    {
        return <<<KB
# PRIVASIMU — Platform Kepatuhan UU PDP

## Tentang PRIVASIMU
PRIVASIMU adalah platform SaaS untuk membantu organisasi mematuhi UU Pelindungan Data Pribadi (UU No. 27 Tahun 2022). Platform ini multi-tenant, artinya setiap organisasi memiliki data terpisah.

## Modul-Modul Utama

### 1. Dashboard
- Menampilkan statistik kepatuhan secara real-time
- KPI: total ROPA, DPIA, breach incidents, gap assessment score

### 2. ROPA (Record of Processing Activities)
- Wizard 6 langkah: Identifikasi → Tujuan → Kategori Data → Keamanan → Review → Submit
- Risk level otomatis: data sensitif → HIGH risk → otomatis generate DPIA

### 3. DPIA (Data Protection Impact Assessment)
- Wizard 4 langkah: Identifikasi → Analisis Risiko → Mitigasi → Review
- Scoring: likelihood × impact = risk score

### 4. Gap Assessment
- 62 pertanyaan berdasarkan UU PDP
- 7 domain kepatuhan

### 5. Data Breach Management
- Flow 5 fase: Terdeteksi → Assessment → Containment → Notifikasi → Ditutup
- Countdown 72 jam untuk notifikasi KOMDIGI (UU PDP Pasal 46)

### 6. DSR (Data Subject Request)
- Jenis: akses, koreksi, hapus, portabilitas, tarik consent

### 7. Consent Management
### 8. Simulasi / Fire Drill
### 9. Data Mapping
### 10. Dokumentasi

## Role & Permission
- SuperAdmin, Admin, DPO, Maker, Viewer

## Kontak
PT Sainskerta Solusi Nusantara — 081319504441 (Galih)
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

        if ($user->role !== 'superadmin') {
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
        if ($user->role !== 'superadmin' && $conversation->user_id !== $user->id) {
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
        if ($user->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['message' => 'required|string|max:2000']);

        $conversation = ChatConversation::findOrFail($id);
        $conversation->update(['status' => 'admin_active', 'last_message_at' => now()]);

        $msg = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'admin',
            'content' => $request->message,
            'sender_name' => $user->name . ' (Admin)',
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

        if ($user->role !== 'superadmin' && $conversation->user_id !== $user->id) {
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
