<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PrivacyNotice;
use App\Models\PrivacyNoticeVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penyajian naskah pemberitahuan privasi untuk situs dan aplikasi klien.
 *
 * Tanpa otentikasi — memang ditujukan bagi pengunjung anonim. Karena itu hanya
 * versi yang BERSTATUS TERBIT yang pernah keluar dari sini: draft dan versi
 * yang menunggu persetujuan tidak boleh bocor lewat penebakan parameter.
 */
class PrivacyNoticePublicController extends Controller
{
    public function show(Request $request, string $token): JsonResponse
    {
        // Global scope `org` tidak berlaku pada jalur tanpa otentikasi, jadi
        // pencarian sengaja dilakukan lintas tenant lewat token — token itulah
        // satu-satunya rahasia yang memberi akses.
        $notice = PrivacyNotice::withoutGlobalScope('org')
            ->where('embed_token', $token)
            ->where('is_active', true)
            ->first();

        if (! $notice || ! $notice->published_version_id) {
            return response()->json(['message' => 'Privacy notice tidak ditemukan atau belum diterbitkan.'], 404);
        }

        $version = $notice->publishedVersion()->withoutGlobalScope('org')->first();
        if (! $version || $version->status !== PrivacyNoticeVersion::STATUS_PUBLISHED) {
            return response()->json(['message' => 'Privacy notice belum diterbitkan.'], 404);
        }

        $contents = $version->contents()->withoutGlobalScope('org')->get();
        $requested = (string) $request->query('locale', $notice->default_locale);

        $content = $contents->firstWhere('locale', $requested)
            ?? $contents->firstWhere('locale', $notice->default_locale)
            ?? $contents->first();

        if (! $content) {
            return response()->json(['message' => 'Naskah belum tersedia.'], 404);
        }

        return response()->json([
            'data' => [
                'code' => $notice->code,
                'notice_title' => $notice->title,
                'version' => $version->version_number,
                'published_at' => $version->published_at?->toIso8601String(),
                'locale' => $content->locale,
                // Bahasa yang diminta bisa berbeda dari yang disajikan ketika
                // naskahnya belum diterjemahkan. Klien perlu tahu selisih itu
                // agar dapat menampilkan penanda bahasa yang jujur.
                'requested_locale' => $requested,
                'available_locales' => $contents->pluck('locale')->values(),
                'title' => $content->title,
                'summary' => $content->summary,
                'body' => $content->body,
            ],
        ]);
    }
}
