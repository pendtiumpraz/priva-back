<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Setup Routes (untuk shared hosting tanpa SSH)
|--------------------------------------------------------------------------
| Akses via browser:
|   - Migrate:       /setup/privasimu-setup-2026
|   - Fresh + Seed:  /setup-fresh/privasimu-setup-2026
|
| ⚠️ HAPUS route ini setelah setup production selesai!
*/

Route::get('/setup/{secret}', function (string $secret) {
    if ($secret !== 'privasimu-setup-2026') abort(404);
    try {
        Artisan::call('migrate', ['--force' => true]);
        $m = Artisan::output();
        Artisan::call('db:seed', ['--force' => true]);
        $s = Artisan::output();
        return response()->json(['status' => '✅ OK', 'migrate' => $m, 'seed' => $s]);
    } catch (\Exception $e) {
        return response()->json(['status' => '❌ ERROR', 'message' => $e->getMessage()], 500);
    }
});

Route::get('/setup-fresh/{secret}', function (string $secret) {
    if ($secret !== 'privasimu-setup-2026') abort(404);
    try {
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        return response()->json(['status' => '✅ OK', 'output' => Artisan::output()]);
    } catch (\Exception $e) {
        return response()->json(['status' => '❌ ERROR', 'message' => $e->getMessage()], 500);
    }
});

/*
|--------------------------------------------------------------------------
| Public DSR — browser-facing pages (verify HTML, NDA preview/sign)
|--------------------------------------------------------------------------
| Subject klik link di email → buka di browser. JSON polling (widget)
| stays on /api/public/dsr/* for clarity.
*/
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/public/dsr/verify/{token}', [\App\Http\Controllers\Api\DsrPublicController::class, 'verify']);
    Route::get('/public/dsr/{token}/nda', [\App\Http\Controllers\Api\DsrPublicController::class, 'ndaPreview']);
    Route::post('/public/dsr/{token}/nda/sign', [\App\Http\Controllers\Api\DsrPublicController::class, 'ndaSign']);

    // Live preview pages — sandbox HTML showing real widgets
    Route::get('/preview/consent-banner', [\App\Http\Controllers\Api\PreviewController::class, 'consentBanner']);
    Route::get('/preview/dsr-widget', [\App\Http\Controllers\Api\PreviewController::class, 'dsrWidget']);
});

use App\Http\Controllers\Lms\PublicVerificationController;

Route::get('/verify/{certificateNumber}', [PublicVerificationController::class, 'verify'])
    ->name('lms.certificate.verify');
