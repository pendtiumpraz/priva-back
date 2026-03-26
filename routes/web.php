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
