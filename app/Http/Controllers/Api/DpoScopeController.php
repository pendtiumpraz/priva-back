<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Support\AssignmentScope;
use App\Support\CakupanDpo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pengaturan cakupan DPO — milik admin tenant — dan preferensi pandangan milik
 * tiap DPO sendiri.
 *
 * Dua hal yang sengaja dipisah, karena sifatnya memang berbeda:
 *
 *   CAKUPAN (organisasi)  → kontrol akses. Hanya admin tenant yang boleh
 *                           mengubahnya, dan perubahannya diaudit.
 *   PANDANGAN (per-DPO)   → kenyamanan. DPO se-perusahaan memfokuskan
 *                           tampilannya ke satu divisi; ia hanya MENYEMPITKAN
 *                           dan tidak pernah memperluas.
 *
 * Kenapa aturan penulisan pandangannya longgar tapi tetap aman: nilainya
 * diabaikan sepenuhnya oleh CakupanDpo untuk siapa pun yang bukan DPO
 * se-perusahaan. Jadi staf biasa boleh saja menuliskannya — ia tidak akan
 * memberi akses apa pun.
 */
class DpoScopeController extends Controller
{
    /**
     * Bolehkah orang ini mengatur cakupan DPO organisasinya?
     *
     * Hanya admin tenant. DPO sengaja TIDAK — kalau ia bisa, cakupannya
     * berhenti jadi batas dan berubah jadi preferensi yang bisa dilonggarkan
     * sendiri oleh pihak yang dibatasinya.
     */
    private function bolehMengatur(Request $request): bool
    {
        $user = $request->user();

        // Staf platform di atas tenant — juga jalan keluar bila tenant terjebak.
        if (in_array($user->role ?? '', ['root', 'superadmin'], true)) {
            return true;
        }

        // Admin tenant yang ditandai secara tegas. `role` dan nama tenant role
        // masing-masing bernilai tunggal, jadi orang yang ditandai `admin` di
        // sini tidak mungkin sekaligus ditandai `dpo`.
        if (($user->role ?? '') === 'admin') {
            return true;
        }
        if (strtolower((string) optional($user->tenantRole)->name) === 'admin') {
            return true;
        }

        // Cadangan: admin tenant kerap memakai NAMA role kustom tapi berizin '*'
        // — cara kanonik aplikasi menandai akses penuh. Di jalur INILAH seorang
        // DPO bisa menyelinap, karena DPO pun lazimnya berizin penuh atas semua
        // modul. Karena itu hanya di sini DPO dikecualikan.
        $izin = optional($user->tenantRole)->permissions;

        return is_array($izin)
            && in_array('*', $izin, true)
            && ! AssignmentScope::berperanDpo($user);
    }

    /**
     * GET /organization/dpo-scope
     *
     * Keadaan sekarang plus jumlah DPO terdaftar — angka itu yang membuat
     * pilihan "satu DPO" bisa dinilai sebelum disimpan.
     */
    public function show(Request $request)
    {
        $org = Organization::findOrFail($request->user()->org_id);

        return response()->json(['data' => [
            'dpo_scope' => CakupanDpo::cakupan($request->user()),
            'dpo_jumlah' => CakupanDpo::jumlah($org),
            'jumlah_dpo_terdaftar' => $this->hitungDpo($org->id),
            'dapat_mengatur' => $this->bolehMengatur($request),
        ]]);
    }

    /**
     * PUT /organization/dpo-scope
     */
    public function update(Request $request)
    {
        if (! $this->bolehMengatur($request)) {
            return response()->json([
                'message' => 'Hanya admin tenant yang dapat mengatur cakupan DPO.',
            ], 403);
        }

        $data = $request->validate([
            'dpo_scope' => ['required', Rule::in(CakupanDpo::CAKUPAN)],
            'dpo_jumlah' => ['required', Rule::in(CakupanDpo::JUMLAH)],
        ]);

        $org = Organization::findOrFail($request->user()->org_id);
        $terdaftar = $this->hitungDpo($org->id);

        // Menyetel "satu DPO" selagi sudah ada beberapa akan membuat aturan itu
        // bohong sejak detik pertama. Ditolak dengan angkanya, bukan sekadar
        // "tidak valid", supaya admin tahu apa yang harus dirapikan dulu.
        if ($data['dpo_jumlah'] === CakupanDpo::SATU && $terdaftar > 1) {
            return response()->json([
                'message' => "Saat ini ada {$terdaftar} akun ber-peran DPO. Kurangi dulu menjadi satu sebelum memilih mode satu DPO.",
                'jumlah_dpo_terdaftar' => $terdaftar,
            ], 422);
        }

        $pengaturan = $org->settings ?? [];
        $sebelum = [
            CakupanDpo::KUNCI_CAKUPAN => CakupanDpo::cakupan($request->user()),
            CakupanDpo::KUNCI_JUMLAH => CakupanDpo::jumlah($org),
        ];

        $pengaturan[CakupanDpo::KUNCI_CAKUPAN] = $data['dpo_scope'];
        $pengaturan[CakupanDpo::KUNCI_JUMLAH] = $data['dpo_jumlah'];
        $org->settings = $pengaturan;
        $org->save();

        // Ini perubahan kontrol akses, jadi jejaknya wajib.
        AuditLog::log('organization', $org->id, 'dpo_scope_updated', [
            'sebelum' => $sebelum,
            'sesudah' => [
                CakupanDpo::KUNCI_CAKUPAN => $data['dpo_scope'],
                CakupanDpo::KUNCI_JUMLAH => $data['dpo_jumlah'],
            ],
        ], 'manual');

        return response()->json([
            'message' => 'Cakupan DPO diperbarui.',
            'data' => [
                'dpo_scope' => $data['dpo_scope'],
                'dpo_jumlah' => $data['dpo_jumlah'],
                'jumlah_dpo_terdaftar' => $terdaftar,
            ],
        ]);
    }

    /**
     * PUT /me/dpo-view
     *
     * Persempit pandangan DPO se-perusahaan ke satu divisi, atau kembalikan ke
     * seluruh perusahaan dengan mengirim null.
     */
    public function setPandangan(Request $request)
    {
        $data = $request->validate([
            'division' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $divisi = trim((string) ($data['division'] ?? ''));

        // Divisi yang tidak ada akan menghasilkan pandangan kosong tanpa sebab
        // yang terbaca — lebih baik ditolak di sini.
        if ($divisi !== '' && ! Department::where('org_id', $user->org_id)->where('name', $divisi)->exists()) {
            return response()->json(['message' => "Divisi '{$divisi}' tidak ditemukan."], 422);
        }

        $pengaturan = is_array($user->settings) ? $user->settings : [];
        $pengaturan[CakupanDpo::KUNCI_PANDANGAN] = $divisi === '' ? null : $divisi;
        $user->update(['settings' => $pengaturan]);

        return response()->json([
            'message' => $divisi === ''
                ? 'Pandangan dikembalikan ke seluruh perusahaan.'
                : "Pandangan dipersempit ke divisi {$divisi}.",
            'data' => [
                'division' => CakupanDpo::pandanganDipersempit($user->fresh()),
                // Kalau usernya bukan DPO se-perusahaan, nilainya tersimpan tapi
                // tidak berlaku. Dikatakan terus terang, bukan didiamkan.
                'berlaku' => AssignmentScope::berperanDpo($user)
                    && CakupanDpo::cakupan($user) === CakupanDpo::SE_PERUSAHAAN,
            ],
        ]);
    }

    /** Berapa akun aktif yang memegang peran DPO di organisasi ini. */
    private function hitungDpo(string $orgId): int
    {
        return User::where('org_id', $orgId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->with('tenantRole:id,name')
            ->get(['id', 'role', 'tenant_role_id'])
            ->filter(fn (User $u) => AssignmentScope::berperanDpo($u))
            ->count();
    }
}
