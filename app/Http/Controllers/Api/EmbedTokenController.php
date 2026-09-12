<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EmbedToken;
use Illuminate\Http\Request;

/**
 * Pengelolaan token embed oleh tenant (terautentikasi).
 *
 * Modul diikat dari rute, bukan dari badan permintaan, supaya gerbang izin
 * (`permission:ropa,write` / `permission:dpia,write`) benar-benar menjaga modul
 * yang bersangkutan. Kalau modul datang dari payload, seseorang dengan hak
 * tulis RoPA bisa menerbitkan token DPIA.
 *
 * Menerbitkan token embed = mengizinkan data terbaca tanpa login, jadi haknya
 * disamakan dengan hak TULIS modul, bukan hak baca.
 */
class EmbedTokenController extends Controller
{
    /** Masa berlaku bawaan; tautan yang hidup selamanya cenderung terlupakan. */
    private const DEFAULT_TTL_DAYS = 90;

    public function index(Request $request, string $module)
    {
        $items = EmbedToken::where('org_id', $request->user()->org_id)
            ->where('module', $module)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $items->map(fn ($t) => $this->present($t)),
            // Setiap kolom di ALLOWED_FIELDS dijamin punya label di FIELD_LABELS,
            // jadi pencariannya total — tidak perlu jaring pengaman ??.
            'available_fields' => array_map(fn ($f) => [
                'key' => $f,
                'label' => EmbedToken::FIELD_LABELS[$f],
            ], EmbedToken::ALLOWED_FIELDS[$module] ?? []),
            'available_filters' => EmbedToken::ALLOWED_FILTERS[$module] ?? [],
        ]);
    }

    public function store(Request $request, string $module)
    {
        $data = $request->validate([
            'label' => 'required|string|max:160',
            'fields' => 'nullable|array',
            'filters' => 'nullable|array',
            'allowed_origins' => 'nullable|array',
            'allowed_origins.*' => 'string|max:255',
            'expires_in_days' => 'nullable|integer|min:1|max:730',
        ]);

        $orgId = $request->user()->org_id;
        $tokenMentah = EmbedToken::generateUniqueToken();

        $embed = EmbedToken::create([
            'org_id' => $orgId,
            'module' => $module,
            'label' => $data['label'],
            'token' => $tokenMentah,
            // Diiris daftar putih di sisi server — pilihan klien tidak dipercaya.
            'fields' => EmbedToken::sanitizeFields($module, $data['fields'] ?? null),
            'filters' => EmbedToken::sanitizeFilters($module, $data['filters'] ?? null),
            'allowed_origins' => $data['allowed_origins'] ?? null,
            'expires_at' => now()->addDays((int) ($data['expires_in_days'] ?? self::DEFAULT_TTL_DAYS)),
            'created_by' => $request->user()->id,
        ]);

        AuditLog::log($module, $embed->id, 'embed_token.created', [
            'label' => $embed->label,
            'fields' => $embed->fields,
            'expires_at' => optional($embed->expires_at)->toIso8601String(),
        ], 'embed');

        return response()->json([
            'message' => 'Tautan embed dibuat.',
            'data' => $this->present($embed, $tokenMentah),
        ], 201);
    }

    /**
     * Rotasi: tautan lama langsung mati.
     *
     * URUTAN ARGUMEN PENTING: parameter dari URI (`{id}`) harus mendahului yang
     * disuntik `->defaults('module', ...)` di rute. Dengan urutan terbalik,
     * nilai default masuk ke argumen yang salah dan pencarian berubah menjadi
     * `where('module', <uuid>)` — selalu 404. Jangan dirapikan jadi ($module, $id).
     */
    public function rotate(Request $request, string $id, string $module)
    {
        $embed = $this->find($request, $module, $id);
        $tokenMentah = EmbedToken::generateUniqueToken();
        $embed->update(['token' => $tokenMentah, 'revoked_at' => null]);

        AuditLog::log($module, $embed->id, 'embed_token.rotated', [
            'warning' => 'tautan lama langsung tidak berlaku, perbarui pemasangan iframe',
        ], 'embed');

        return response()->json([
            'message' => 'Tautan embed dirotasi. Perbarui pemasangan iframe di situs Anda.',
            'data' => $this->present($embed->fresh(), $tokenMentah),
        ]);
    }

    /** Lihat catatan urutan argumen pada rotate(). */
    public function revoke(Request $request, string $id, string $module)
    {
        $embed = $this->find($request, $module, $id);
        $embed->update(['revoked_at' => now()]);

        AuditLog::log($module, $embed->id, 'embed_token.revoked', ['label' => $embed->label], 'embed');

        return response()->json(['message' => 'Tautan embed dicabut.', 'data' => $this->present($embed->fresh())]);
    }

    private function find(Request $request, string $module, string $id): EmbedToken
    {
        return EmbedToken::where('org_id', $request->user()->org_id)
            ->where('module', $module)
            ->findOrFail($id);
    }

    /**
     * Token mentah hanya disertakan tepat saat dibuat atau dirotasi; sesudah
     * itu pemilik memakai tautan yang sudah dipasang, dan kalau hilang jalan
     * keluarnya adalah rotasi — bukan menyimpan token agar bisa dilihat lagi.
     */
    private function present(EmbedToken $t, ?string $tokenMentah = null): array
    {
        $out = [
            'id' => $t->id,
            'label' => $t->label,
            'module' => $t->module,
            'fields' => $t->fields,
            'filters' => $t->filters,
            'allowed_origins' => $t->allowed_origins,
            'expires_at' => optional($t->expires_at)->toIso8601String(),
            'revoked_at' => optional($t->revoked_at)->toIso8601String(),
            'last_used_at' => optional($t->last_used_at)->toIso8601String(),
            'view_count' => $t->view_count,
            'active' => $t->isActive(),
            'created_at' => optional($t->created_at)->toIso8601String(),
        ];

        if ($tokenMentah !== null) {
            $out['token'] = $tokenMentah;
            $out['embed_url'] = rtrim((string) config('app.frontend_url', config('app.url', 'http://localhost:3000')), '/')
                .'/embed/register/'.$tokenMentah;
            $out['snippet'] = '<iframe src="'.$out['embed_url'].'" '
                .'style="width:100%;border:0;min-height:520px" loading="lazy" '
                .'title="'.htmlspecialchars($t->label, ENT_QUOTES).'"></iframe>';
        }

        return $out;
    }
}
