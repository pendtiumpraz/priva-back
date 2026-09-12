<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Services\ContractReviewLinker;
use App\Services\FileUploadValidator;
use App\Services\TenantStorageService;
use App\Services\VendorContractTokenService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Halaman publik unggah kontrak oleh pihak ketiga.
 *
 * Dipakai saat perusahaan tidak memegang berkas kontraknya: pihak ketiga
 * mengunggah sendiri lewat tautan sekali-unggah. Middleware
 * PublicVendorContractTokenMiddleware sudah memvalidasi token, mengunci
 * unggahan kedua, menyetel tenant context, dan melampirkan kontrak sebagai
 * `$request->_vendorContract`.
 */
class KontrakPihakKetigaPublikController extends Controller
{
    public function show(Request $request, string $token)
    {
        /** @var VendorContract $contract */
        $contract = $request->get('_vendorContract');
        $vendor = Vendor::find($contract->vendor_id);
        $org = Organization::find($contract->org_id);

        return response()->json([
            'data' => [
                'organisasi' => ['nama' => $org?->name],
                'pihak_ketiga' => ['nama' => $vendor?->name],
                'kontrak' => [
                    'judul' => $contract->title,
                    'jenis' => VendorContract::TYPE_LABELS[$contract->contract_type] ?? $contract->contract_type,
                    'nomor' => $contract->contract_number,
                    'mulai' => optional($contract->start_at)->toDateString(),
                    'berakhir' => optional($contract->end_at)->toDateString(),
                ],
                'sudah_diunggah' => $contract->token_consumed_at !== null,
            ],
        ]);
    }

    public function upload(
        Request $request,
        string $token,
        TenantStorageService $storage,
        FileUploadValidator $validator,
        VendorContractTokenService $tokens,
    ) {
        /** @var VendorContract $contract */
        $contract = $request->get('_vendorContract');
        $request->validate(['file' => 'required|file|max:10240']);

        try {
            $validator->validate($request->file('file'), FileUploadValidator::PRESET_DOCUMENT);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $org = Organization::findOrFail($contract->org_id);
        $stored = $storage->storeTenantPrivateFile($org, $request->file('file'), "vendors/{$contract->vendor_id}/contracts");

        $contract->forceFill([
            'file' => [
                'path' => $stored['path'],
                'driver' => $stored['driver'],
                'filename' => $request->file('file')->getClientOriginalName(),
                'size' => $request->file('file')->getSize(),
                'uploaded_at' => now()->toIso8601String(),
            ],
            'uploaded_side' => VendorContract::SIDE_THIRD_PARTY,
            'uploaded_by' => null, // anonim — lewat tautan publik
        ])->save();

        $tokens->markConsumed($contract, $request);

        // Langsung teruskan ke Contract Review. Tanpa ini kontrak hasil unggahan
        // pihak ketiga hanya mengendap sampai ada orang tenant yang teringat
        // menekan "Kirim ke Telaah" — padahal justru kontrak inilah yang paling
        // perlu dinilai, karena isinya tidak disusun oleh tenant.
        //
        // Kegagalan di sini TIDAK boleh menggagalkan unggahan: bagi pihak ketiga
        // pekerjaannya sudah selesai, dan galat urusan internal tenant bukan
        // miliknya untuk ditanggung. Pelakunya null — memang tidak ada pengguna
        // yang login pada tautan publik.
        try {
            app(ContractReviewLinker::class)->link($contract->fresh(), null);
        } catch (\Throwable $e) {
            \Log::warning('Auto-link kontrak pihak ketiga ke Contract Review gagal: '.$e->getMessage());
        }

        return response()->json([
            'message' => 'Kontrak berhasil diunggah. Terima kasih.',
            'data' => ['uploaded_at' => optional($contract->fresh()->token_consumed_at)->toIso8601String()],
        ]);
    }
}
