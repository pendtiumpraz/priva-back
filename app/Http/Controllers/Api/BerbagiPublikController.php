<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Dpia;
use App\Models\RecordShareLink;
use App\Models\Ropa;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Halaman dokumen untuk lembaga — satu RoPA/DPIA, dijaga kata sandi.
 *
 * Keaktifan tautan sudah dijaga PublicShareLinkTokenMiddleware. Yang khas di
 * sini adalah kata sandinya:
 *
 *  - percobaan sandi punya ember batas laju sendiri (per token + IP) yang jauh
 *    lebih ketat daripada membuka halaman, karena inilah satu-satunya hal yang
 *    berdiri di antara tautan yang bocor dan isi dokumennya;
 *  - hitungan kunjungan hanya bertambah saat sandi BENAR. Kalau dihitung saat
 *    halaman dibuka, pratinjau tautan di aplikasi pesan bisa menghabiskan jatah
 *    sebelum petugasnya sempat membuka;
 *  - setiap pembukaan yang berhasil dicatat berikut IP sebagai bukti akses.
 */
class BerbagiPublikController extends Controller
{
    private const PASSWORD_ATTEMPTS_PER_MINUTE = 8;

    private function link(Request $request): RecordShareLink
    {
        return $request->input('_shareLink');
    }

    /**
     * GET /api/berbagi-publik/{token}
     *
     * Sengaja minim: sebelum kata sandi benar, tidak ada apa pun tentang isi
     * maupun pemilik dokumen yang diungkapkan.
     */
    public function info(Request $request)
    {
        $link = $this->link($request);

        return response()->json([
            'data' => [
                'module' => $link->module,
                'module_label' => $link->module === RecordShareLink::MODULE_DPIA
                    ? 'Penilaian Dampak (DPIA)'
                    : 'Catatan Kegiatan Pemrosesan (RoPA)',
                'requires_password' => true,
                'remaining_views' => $link->remainingViews(),
            ],
        ]);
    }

    /**
     * POST /api/berbagi-publik/{token}/buka
     */
    public function buka(Request $request)
    {
        $link = $this->link($request);
        $request->validate(['password' => 'required|string|max:200']);

        $rateKey = 'share-link-password:'.$link->id.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, self::PASSWORD_ATTEMPTS_PER_MINUTE)) {
            $retry = RateLimiter::availableIn($rateKey);

            return response()->json([
                'error' => 'Terlalu banyak percobaan kata sandi. Coba lagi dalam '.$retry.' detik.',
                'retry_after' => $retry,
            ], 429)->header('Retry-After', (string) $retry);
        }

        if (! Hash::check((string) $request->input('password'), $link->password_hash)) {
            RateLimiter::hit($rateKey, 60);
            AuditLog::create([
                'module' => $link->module,
                'record_id' => $link->record_id,
                'action' => 'share_link.password_failed',
                'user_name' => 'Tautan Lembaga',
                'user_role' => 'public_share',
                'section' => 'share',
                'changes' => ['link_id' => $link->id, 'recipient' => $link->recipient_label],
                'ip_address' => $request->ip(),
            ]);

            return response()->json(['error' => 'Kata sandi salah.'], 422);
        }

        $record = $this->findRecord($link);
        if (! $record) {
            return response()->json(['error' => 'Dokumen sudah tidak tersedia.'], 404);
        }

        // Hitung DULU, supaya pembukaan yang berhasil tetap tercatat walau
        // penyusunan payload di bawah gagal.
        $link->recordSuccessfulView();

        AuditLog::create([
            'module' => $link->module,
            'record_id' => $link->record_id,
            'action' => 'share_link.opened',
            'user_name' => 'Tautan Lembaga'.($link->recipient_label ? ' — '.$link->recipient_label : ''),
            'user_role' => 'public_share',
            'section' => 'share',
            'changes' => [
                'link_id' => $link->id,
                'view_count' => $link->view_count,
                'max_views' => $link->max_views,
                'auto_revoked' => $link->isRevoked(),
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'data' => $this->presentRecord($link, $record),
            'meta' => [
                'remaining_views' => $link->remainingViews(),
                'auto_revoked' => $link->isRevoked(),
                'notice' => $link->isRevoked()
                    ? 'Ini pembukaan terakhir yang diizinkan. Tautan kini tertutup otomatis.'
                    : null,
            ],
        ]);
    }

    private function findRecord(RecordShareLink $link)
    {
        $model = $link->module === RecordShareLink::MODULE_DPIA ? new Dpia : new Ropa;

        return $model->newQuery()
            ->where('org_id', $link->org_id)
            ->find($link->record_id);
    }

    /**
     * Dokumen utuh untuk regulator, dikurangi kolom kerja internal.
     * Yang dibagikan adalah ISI catatan pemrosesannya, bukan siapa yang
     * ditugasi dan siapa yang menyetujui di dalam organisasi.
     */
    private function presentRecord(RecordShareLink $link, $record): array
    {
        $data = $record->toArray();
        foreach (RecordShareLink::HIDDEN_FIELDS as $f) {
            unset($data[$f]);
        }

        $data['organization'] = $link->organization?->name;

        if ($link->module === RecordShareLink::MODULE_ROPA) {
            $record->loadMissing('vendors:id,name,country,risk_level');
            $data['third_parties'] = $record->vendors->map(fn ($v) => [
                'name' => $v->name,
                'country' => $v->country,
                'role' => $v->pivot->role,
                'role_label' => Vendor::roleLabel($v->pivot->role),
                'purpose' => $v->pivot->purpose,
            ])->values();
            unset($data['vendors']);
        } else {
            $record->loadMissing('ropas:id,registration_number,processing_activity');
            $data['linked_ropas'] = $record->ropas->map(fn ($r) => [
                'registration_number' => $r->registration_number,
                'processing_activity' => $r->processing_activity,
            ])->values();
            unset($data['ropas']);
        }

        return $data;
    }
}
