<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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

/*
|--------------------------------------------------------------------------
| ⚠️ Setup routes (/setup/{secret} and /setup-fresh/{secret}) DIHAPUS
|--------------------------------------------------------------------------
| Migration via browser dengan plaintext shared secret di repo = security
| disaster. Setiap orang yang baca git history tahu secret-nya, dan endpoint
| bisa nuke seluruh DB lewat HTTP request publik.
|
| Untuk migrate di shared hosting tanpa SSH, pakai salah satu:
|   1. `composer dev` lokal + push DB dump
|   2. Provider hosting yang support cron / job runners
|   3. SSH ke server (cPanel terminal, dll) lalu `php artisan migrate`
|   4. CI/CD pipeline yang trigger migrate (preferred — Vercel/GitHub Actions)
|
| Kalau benar-benar butuh web-trigger migrate, bikin artisan command yang
| protected oleh Sanctum `auth:sanctum + role:root` middleware (bukan
| shared secret di URL).
*/
