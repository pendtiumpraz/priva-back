<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PrivacyNotice;
use App\Models\PrivacyNoticeContent;
use App\Models\PrivacyNoticeVersion;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pengelolaan terpusat pemberitahuan privasi: banyak dokumen, bertahap versi,
 * melewati persetujuan, dapat dijadwalkan terbit, dan berlaku multi-bahasa.
 *
 * Satu aturan yang ditegakkan di sepanjang controller ini: naskah hanya boleh
 * disunting ketika versinya berstatus draft atau ditolak. Begitu diajukan, ia
 * beku. Tanpa aturan itu, persetujuan kehilangan makna — naskah bisa berubah
 * setelah disetujui dan sebelum terbit, dan yang tayang bukan yang disetujui.
 */
class PrivacyNoticeController extends Controller
{
    // =====================================================================
    // Dokumen
    // =====================================================================

    public function index(Request $request): JsonResponse
    {
        $query = PrivacyNotice::query()->with('publishedVersion');

        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json([
            'data' => $query->orderByDesc('created_at')->paginate((int) $request->input('per_page', 25)),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $notice = PrivacyNotice::with(['versions.contents', 'publishedVersion.contents'])->findOrFail($id);

        return response()->json(['data' => $notice]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'default_locale' => 'nullable|string|max:10',
            'is_active' => 'nullable|boolean',
        ]);

        $notice = PrivacyNotice::createWithCodeRetry([
            'org_id' => $request->user()->org_id,
            'title' => $data['title'],
            'slug' => $data['slug'] ?? null,
            'description' => $data['description'] ?? null,
            'default_locale' => $data['default_locale'] ?? 'id',
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        AuditLog::log('privacy-notice', $notice->id, 'created', ['title' => $notice->title]);

        return response()->json(['data' => $notice], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $notice = PrivacyNotice::findOrFail($id);
        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'slug' => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string',
            'default_locale' => 'sometimes|string|max:10',
            'is_active' => 'sometimes|boolean',
        ]);

        $before = $notice->only(array_keys($data));
        $notice->update($data);
        AuditLog::log('privacy-notice', $notice->id, 'updated', ['before' => $before, 'after' => $data]);

        return response()->json(['data' => $notice]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $notice = PrivacyNotice::findOrFail($id);
        $notice->delete();
        AuditLog::log('privacy-notice', $notice->id, 'deleted');

        return response()->json(['message' => 'Privacy Notice dihapus.']);
    }

    // =====================================================================
    // Versi
    // =====================================================================

    /**
     * Buat versi draft baru, opsional menyalin naskah dari versi terakhir agar
     * penyunting tidak perlu mengetik ulang seluruh dokumen untuk satu koreksi.
     */
    public function storeVersion(Request $request, string $id): JsonResponse
    {
        $notice = PrivacyNotice::findOrFail($id);

        $data = $request->validate([
            'change_note' => 'nullable|string',
            'copy_from_version_id' => 'nullable|uuid',
            'contents' => 'nullable|array',
            'contents.*.locale' => 'required_with:contents|string|max:10',
            'contents.*.title' => 'required_with:contents|string|max:255',
            'contents.*.body' => 'required_with:contents|string',
            'contents.*.summary' => 'nullable|string',
        ]);

        // Draft yang masih terbuka tidak boleh berlipat: dua draft berjalan
        // membuat "versi berikutnya" menjadi ambigu saat diajukan.
        $openDraft = $notice->versions()
            ->whereIn('status', PrivacyNoticeVersion::EDITABLE)
            ->first();
        if ($openDraft) {
            return response()->json([
                'message' => 'Masih ada versi draft yang terbuka. Selesaikan atau hapus versi tersebut lebih dulu.',
                'version_id' => $openDraft->id,
            ], 422);
        }

        return DB::transaction(function () use ($notice, $data) {
            $next = (int) ($notice->versions()->max('version_number') ?? 0) + 1;

            $version = PrivacyNoticeVersion::create([
                'org_id' => $notice->org_id,
                'privacy_notice_id' => $notice->id,
                'version_number' => $next,
                'status' => PrivacyNoticeVersion::STATUS_DRAFT,
                'change_note' => $data['change_note'] ?? null,
            ]);

            $contents = $data['contents'] ?? null;
            if (! $contents && ! empty($data['copy_from_version_id'])) {
                $source = PrivacyNoticeVersion::where('privacy_notice_id', $notice->id)
                    ->findOrFail($data['copy_from_version_id']);
                $contents = $source->contents->map(fn ($c) => [
                    'locale' => $c->locale,
                    'title' => $c->title,
                    'body' => $c->body,
                    'summary' => $c->summary,
                ])->all();
            }

            foreach ($contents ?? [] as $content) {
                PrivacyNoticeContent::create([
                    'org_id' => $notice->org_id,
                    'version_id' => $version->id,
                    'locale' => $content['locale'],
                    'title' => $content['title'],
                    'body' => $content['body'],
                    'summary' => $content['summary'] ?? null,
                ]);
            }

            AuditLog::log('privacy-notice', $notice->id, 'version_created', [
                'version_number' => $next,
            ], 'version');

            return response()->json(['data' => $version->load('contents')], 201);
        });
    }

    /** Simpan naskah per bahasa. Hanya sah pada versi yang masih dapat disunting. */
    public function updateVersionContent(Request $request, string $id, string $versionId): JsonResponse
    {
        $notice = PrivacyNotice::findOrFail($id);
        $version = PrivacyNoticeVersion::where('privacy_notice_id', $notice->id)->findOrFail($versionId);

        if (! $version->isEditable()) {
            return response()->json([
                'message' => 'Versi ini sudah diajukan sehingga naskahnya terkunci. Buat versi baru untuk mengubah naskah.',
                'status' => $version->status,
            ], 422);
        }

        $data = $request->validate([
            'contents' => 'required|array|min:1',
            'contents.*.locale' => 'required|string|max:10',
            'contents.*.title' => 'required|string|max:255',
            'contents.*.body' => 'required|string',
            'contents.*.summary' => 'nullable|string',
            'change_note' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data, $notice, $version) {
            foreach ($data['contents'] as $content) {
                PrivacyNoticeContent::updateOrCreate(
                    ['version_id' => $version->id, 'locale' => $content['locale']],
                    [
                        'org_id' => $notice->org_id,
                        'title' => $content['title'],
                        'body' => $content['body'],
                        'summary' => $content['summary'] ?? null,
                    ]
                );
            }
            if (array_key_exists('change_note', $data)) {
                $version->update(['change_note' => $data['change_note']]);
            }

            AuditLog::log('privacy-notice', $notice->id, 'version_content_updated', [
                'version_number' => $version->version_number,
                'locales' => array_column($data['contents'], 'locale'),
            ], 'version');

            return response()->json(['data' => $version->fresh()->load('contents')]);
        });
    }

    public function submitVersion(Request $request, string $id, string $versionId): JsonResponse
    {
        $notice = PrivacyNotice::findOrFail($id);
        $version = PrivacyNoticeVersion::where('privacy_notice_id', $notice->id)->findOrFail($versionId);

        if (! $version->isEditable()) {
            return response()->json(['message' => 'Hanya versi draft atau yang ditolak yang dapat diajukan.'], 422);
        }
        if ($version->contents()->count() === 0) {
            return response()->json(['message' => 'Naskah masih kosong. Isi minimal satu bahasa sebelum diajukan.'], 422);
        }
        // Bahasa baku wajib ada — itulah yang disajikan ketika pengunjung
        // meminta bahasa yang naskahnya belum tersedia.
        if (! $version->contents()->where('locale', $notice->default_locale)->exists()) {
            return response()->json([
                'message' => "Naskah bahasa baku ({$notice->default_locale}) wajib diisi sebelum diajukan.",
            ], 422);
        }

        $version->update([
            'status' => PrivacyNoticeVersion::STATUS_PENDING,
            'submitted_by' => $request->user()->id,
            'submitted_at' => now(),
            'rejected_by' => null,
            'rejected_at' => null,
            'reject_reason' => null,
        ]);

        AuditLog::log('privacy-notice', $notice->id, 'version_submitted', [
            'version_number' => $version->version_number,
        ], 'approval');

        return response()->json(['data' => $version->fresh()]);
    }

    /**
     * Setujui versi. `publish_at` di masa depan menjadwalkannya; tanpa itu versi
     * langsung terbit dan menggantikan versi sebelumnya.
     */
    public function approveVersion(Request $request, string $id, string $versionId): JsonResponse
    {
        $notice = PrivacyNotice::findOrFail($id);
        $version = PrivacyNoticeVersion::where('privacy_notice_id', $notice->id)->findOrFail($versionId);

        if ($version->status !== PrivacyNoticeVersion::STATUS_PENDING) {
            return response()->json(['message' => 'Hanya versi yang sedang diajukan yang dapat disetujui.'], 422);
        }

        $data = $request->validate([
            'publish_at' => 'nullable|date',
            'note' => 'nullable|string',
        ]);

        // Pemisah pengaju dan penyetuju. Tanpa ini, persetujuan hanyalah
        // formalitas yang dapat dilewati satu orang sendirian.
        if ($version->submitted_by === $request->user()->id && $request->user()->role !== 'root') {
            return response()->json([
                'message' => 'Pengaju tidak dapat menyetujui pengajuannya sendiri.',
            ], 422);
        }

        $publishAt = ! empty($data['publish_at']) ? Carbon::parse($data['publish_at']) : null;
        $scheduled = $publishAt !== null && $publishAt->isFuture();

        $version->update([
            'status' => $scheduled ? PrivacyNoticeVersion::STATUS_SCHEDULED : PrivacyNoticeVersion::STATUS_APPROVED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'publish_at' => $publishAt,
        ]);

        AuditLog::log('privacy-notice', $notice->id, $scheduled ? 'version_scheduled' : 'version_approved', [
            'version_number' => $version->version_number,
            'publish_at' => $publishAt?->toIso8601String(),
            'note' => $data['note'] ?? null,
        ], 'approval');

        if (! $scheduled) {
            $this->promote($notice, $version);
        }

        return response()->json(['data' => $version->fresh()]);
    }

    public function rejectVersion(Request $request, string $id, string $versionId): JsonResponse
    {
        $notice = PrivacyNotice::findOrFail($id);
        $version = PrivacyNoticeVersion::where('privacy_notice_id', $notice->id)->findOrFail($versionId);

        if ($version->status !== PrivacyNoticeVersion::STATUS_PENDING) {
            return response()->json(['message' => 'Hanya versi yang sedang diajukan yang dapat ditolak.'], 422);
        }

        $data = $request->validate(['reason' => 'required|string|max:2000']);

        $version->update([
            'status' => PrivacyNoticeVersion::STATUS_REJECTED,
            'rejected_by' => $request->user()->id,
            'rejected_at' => now(),
            'reject_reason' => $data['reason'],
        ]);

        AuditLog::log('privacy-notice', $notice->id, 'version_rejected', [
            'version_number' => $version->version_number,
            'reason' => $data['reason'],
        ], 'approval');

        return response()->json(['data' => $version->fresh()]);
    }

    /** Terbitkan versi yang sudah disetujui atau dijadwalkan, sekarang juga. */
    public function publishVersion(Request $request, string $id, string $versionId): JsonResponse
    {
        $notice = PrivacyNotice::findOrFail($id);
        $version = PrivacyNoticeVersion::where('privacy_notice_id', $notice->id)->findOrFail($versionId);

        if (! in_array($version->status, [PrivacyNoticeVersion::STATUS_APPROVED, PrivacyNoticeVersion::STATUS_SCHEDULED], true)) {
            return response()->json([
                'message' => 'Hanya versi yang sudah disetujui yang dapat diterbitkan.',
                'status' => $version->status,
            ], 422);
        }

        $this->promote($notice, $version);

        return response()->json(['data' => $version->fresh()]);
    }

    /**
     * Naikkan satu versi menjadi versi yang tayang, dan pensiunkan pendahulunya.
     *
     * Dijalankan dalam satu transaksi supaya tidak pernah ada saat di mana
     * dokumen menunjuk versi yang sudah dipensiunkan — pengunjung yang datang
     * pada celah itu akan menerima 404 atas naskah yang sebenarnya ada.
     */
    private function promote(PrivacyNotice $notice, PrivacyNoticeVersion $version): void
    {
        DB::transaction(function () use ($notice, $version) {
            if ($notice->published_version_id && $notice->published_version_id !== $version->id) {
                PrivacyNoticeVersion::where('id', $notice->published_version_id)->update([
                    'status' => PrivacyNoticeVersion::STATUS_SUPERSEDED,
                    'superseded_at' => now(),
                ]);
            }

            $version->update([
                'status' => PrivacyNoticeVersion::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            $notice->update(['published_version_id' => $version->id]);
        });

        AuditLog::log('privacy-notice', $notice->id, 'version_published', [
            'version_number' => $version->version_number,
        ], 'publish');
    }
}
