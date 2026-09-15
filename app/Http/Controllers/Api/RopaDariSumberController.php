<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ConsentCollectionPoint;
use App\Models\Ropa;
use App\Models\VendorRopa;
use App\Services\RegistrationCodeService;
use Illuminate\Http\Request;

/**
 * Membuat RoPA dari modul lain yang BELUM tertaut ke RoPA mana pun.
 *
 * KENAPA TOMBOL, BUKAN PEMBUATAN OTOMATIS.
 * ----------------------------------------
 * Sebuah RoPA sah karena dua hal: TUJUAN PEMROSESAN dan DASAR HUKUM. Keduanya
 * justru yang paling jarang diketahui modul mana pun — sebuah sistem di Penemuan
 * Data tahu kolom apa yang ia simpan, tetapi tidak tahu untuk apa. RoPA yang
 * dibuat diam-diam dengan tujuan kosong lebih buruk daripada tidak ada: ia
 * menggelembungkan register dan memberi rasa aman palsu tepat pada berkas yang
 * dibaca auditor.
 *
 * Karena itu yang ditawarkan bukan record, melainkan KESENJANGAN beserta
 * tindakannya: "hal ini memproses data pribadi tetapi belum ada kegiatan
 * pemrosesan yang mencatatnya — buat sekarang". Tombolnya hilang begitu
 * tautannya ada.
 *
 * ISINYA DITURUNKAN SERVER, BUKAN DIKIRIM KLIEN.
 * ----------------------------------------------
 * Klien hanya mengirim sumber dan id-nya. Isi RoPA dibaca ulang dari record
 * sumber milik kita sendiri — aturan yang sama dipakai jalur Data Discovery →
 * Insiden. Kalau klien boleh menentukan isinya, register yang dipakai untuk
 * pemberitahuan resmi bisa dikarang dari luar.
 *
 * RISIKONYA TIDAK DINILAI DI SINI, dan itu perlu dinyatakan terang-terangan:
 * RopaRiskCalculator menilai dari jawaban wizard yang belum ada saat record ini
 * lahir. Kolom `ropas.risk_level` sendiri NOT NULL dengan default 'low', jadi
 * record baru akan terbaca "risiko rendah" padahal belum ada yang menilainya.
 * Itu perilaku SELURUH platform — setiap jalur pembuatan RoPA mewarisinya — dan
 * bukan sesuatu yang pantas diubah diam-diam dari satu endpoint; mengubahnya
 * berarti migrasi yang menyentuh semua baris.
 *
 * Yang menandai record ini belum jadi adalah `status = 'draft'`, bukan
 * risikonya. Nilainya akan dihitung ulang begitu wizardnya dilengkapi
 * (`risk_level_locked` tetap false).
 */
class RopaDariSumberController extends Controller
{
    public function __construct(private RegistrationCodeService $codes) {}

    /** Sumber yang sudah punya aturan penurunannya. */
    private const SUMBER = ['vendor_ropa', 'consent'];

    public function store(Request $request)
    {
        $data = $request->validate([
            'sumber' => 'required|string|in:'.implode(',', self::SUMBER),
            'id' => 'required|uuid',
        ]);

        $user = $request->user();
        $orgId = $user->org_id;

        [$isi, $tautkan, $sumberLabel] = match ((string) $data['sumber']) {
            'vendor_ropa' => $this->dariVendorRopa($orgId, (string) $data['id']),
            'consent' => $this->dariConsent($orgId, (string) $data['id']),
            // Tidak terjangkau lewat validasi di atas, tetapi ditulis supaya
            // menambah satu sumber tanpa aturan penurunannya gagal keras di sini,
            // bukan diam-diam membuat RoPA kosong.
            default => abort(422, 'Sumber belum punya aturan penurunan isi RoPA.'),
        };

        // Nomor registrasi dihitung GLOBAL, bukan per-org — kendala uniknya
        // memang global. Lihat catatan F-03 di RegistrationCodeService.
        $ropa = $this->codes->createWithRetry(new Ropa, array_merge($isi, [
            'org_id' => $orgId,
            'registration_number' => $this->codes->nextGlobal('ROPA', Ropa::class),
            'status' => 'draft',
            'created_by' => $user->id,
        ]), 'registration_number', fn () => $this->codes->nextGlobal('ROPA', Ropa::class));

        $tautkan($ropa);

        $this->catat($request, $ropa, $data['sumber'], $data['id'], $sumberLabel);

        return response()->json([
            'message' => 'RoPA draf dibuat dan ditautkan. Lengkapi tujuan & dasar hukumnya sebelum diajukan.',
            'data' => $ropa->fresh(),
        ], 201);
    }

    /**
     * Laporan RoPA yang diisi sendiri pihak ketiga.
     *
     * Ini prefill terkuat yang ada: pihak ketiga sudah mengisi formulir yang
     * bentuknya memang RoPA — tujuan, dasar hukum, kategori data, subjek,
     * retensi, dan pengamanan semuanya sudah ada. Yang ditambahkan hanya
     * penanda asalnya, supaya peninjau tahu isi ini datang dari luar dan perlu
     * diperiksa, bukan diterima begitu saja.
     *
     * @return array{0: array<string,mixed>, 1: callable, 2: string}
     */
    private function dariVendorRopa(string $orgId, string $id): array
    {
        // Tanpa eager-load: satu record, satu pihak ketiga. `with('vendor')` di
        // sini juga tidak bisa diverifikasi analisis statis karena relasinya
        // belum beranotasi tipe — dan memaksakannya hanya untuk menyenangkan
        // linter akan menambah kueri yang tidak dibutuhkan.
        $vr = VendorRopa::where('org_id', $orgId)->findOrFail($id);

        if ($vr->ropas()->exists()) {
            abort(409, 'Laporan ini sudah tertaut ke RoPA.');
        }

        $nama = $vr->vendor->name ?? 'pihak ketiga';

        $isi = [
            'processing_activity' => $vr->processing_activity ?: "Pemrosesan oleh {$nama}",
            'purpose' => $vr->purpose,
            'legal_basis' => $vr->legal_basis,
            'data_categories' => $vr->data_categories ?? [],
            'data_subjects' => $vr->data_subjects ?? [],
            'retention_period' => $vr->retention_period,
            // BENTUKNYA BERBEDA DI DUA KOLOM INI, dan arahnya berlawanan:
            // `ropas.security_measures` kolom TEKS (tidak di-cast), sedangkan
            // `vendor_ropas.security_measures` di-cast larik — jadi digabung.
            // `ropas.recipients` justru sebaliknya: ia DI-CAST larik, sehingga
            // string tunggal akan tersimpan salah bentuk.
            'security_measures' => $vr->security_measures
                ? implode(', ', $vr->security_measures)
                : null,
            // Pihak ketiga itu sendiri adalah penerima data — kalau tidak
            // dituliskan, RoPA-nya justru menghilangkan fakta yang membuatnya ada.
            'recipients' => [$nama],
            'description' => "Dibuat dari laporan RoPA yang diisi sendiri oleh {$nama}. Isinya berasal dari pihak ketiga dan perlu diverifikasi.",
        ];

        return [$isi, fn (Ropa $ropa) => $vr->ropas()->syncWithoutDetaching([
            $ropa->id => ['org_id' => $orgId],
        ]), $nama];
    }

    /**
     * Titik pengumpulan persetujuan.
     *
     * Satu-satunya sumber yang tahu KEDUA field penentu: tujuannya tersusun dari
     * item persetujuan yang ditampilkan ke orang, dan dasar hukumnya sudah pasti
     * persetujuan — itulah yang sedang dikumpulkan.
     *
     * Tautannya disimpan di `settings`, bukan kolom sendiri, mengikuti bentuk
     * yang sudah dipakai ConsentCollectionController.
     *
     * @return array{0: array<string,mixed>, 1: callable, 2: string}
     */
    private function dariConsent(string $orgId, string $id): array
    {
        $titik = ConsentCollectionPoint::where('org_id', $orgId)->findOrFail($id);

        $settings = $titik->settings ?? [];
        if (! empty($settings['linked_ropa_id'])) {
            abort(409, 'Titik pengumpulan ini sudah tertaut ke RoPA.');
        }

        $items = $titik->items()->where('is_active', true)->pluck('title')->all();

        $isi = [
            'processing_activity' => "Pengumpulan persetujuan — {$titik->name}",
            'purpose' => $items
                ? 'Memproses data pribadi untuk: '.implode(', ', $items)
                : null,
            // Bukan tebakan: titik ini memang ada untuk mengumpulkan persetujuan.
            'legal_basis' => 'Persetujuan',
            'data_subjects' => ['Pengguna / pengunjung'],
            'description' => "Dibuat dari titik pengumpulan persetujuan \"{$titik->name}\""
                .($titik->domain ? " di {$titik->domain}" : '').'.',
        ];

        return [$isi, function (Ropa $ropa) use ($titik, $settings) {
            $titik->settings = array_merge($settings, ['linked_ropa_id' => $ropa->id]);
            $titik->save();
        }, $titik->name];
    }

    private function catat(Request $request, Ropa $ropa, string $sumber, string $sumberId, string $label): void
    {
        try {
            AuditLog::create([
                'module' => 'ropa',
                'record_id' => $ropa->id,
                'action' => 'created_from_source',
                'user_name' => $request->user()->name ?? 'Unknown',
                'user_role' => $request->user()->role ?? 'user',
                'section' => $sumber,
                'changes' => [
                    'registration_number' => $ropa->registration_number,
                    'sumber' => $sumber,
                    'sumber_id' => $sumberId,
                    'sumber_label' => $label,
                    // Dicatat supaya jelas RoPA ini lahir belum lengkap — dua
                    // field penentunya mungkin masih kosong saat dibuat.
                    'purpose_terisi' => ! empty($ropa->purpose),
                    'legal_basis_terisi' => ! empty($ropa->legal_basis),
                ],
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Audit log RoPA dari sumber gagal: '.$e->getMessage());
        }
    }
}
