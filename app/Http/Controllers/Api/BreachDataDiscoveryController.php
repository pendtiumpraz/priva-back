<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BreachIncident;
use App\Models\InformationSystem;
use Illuminate\Http\Request;

/**
 * Data Discovery → Insiden: memilih sistem & tabel yang terdampak, lalu
 * menurunkan sendiri tipe data pribadinya.
 *
 * Sebelum ini "tipe data terdampak" diketik manual sebagai teks bebas, padahal
 * hasil pindai sistem sudah menyimpan jawabannya per kolom. Di sini pengguna
 * cukup memilih SISTEM yang sudah dipindai lalu mencentang TABEL mana yang
 * terdampak — kolom PII dan kategori PDP-nya diturunkan dari hasil pindai.
 *
 * Dua hal yang disengaja:
 *
 *  1. Sumbernya `information_systems.scan_results`, BUKAN
 *     `data_discovery_scan_plans`. Keduanya sama-sama bernama "data discovery"
 *     tetapi berbeda maksud: scan plan mencari data SATU ORANG berdasarkan
 *     identitas ({email, name, nik}) untuk keperluan DSR, sedangkan
 *     scan_results adalah KATALOG tabel & kolom sistem. Untuk menjawab "data
 *     apa yang bocor", katalog itulah yang benar; memakai scan plan akan
 *     menyodorkan daftar "pencarian atas nama Budi", bukan daftar tabel.
 *
 *  2. Kolom disaring berdasarkan `applied_status`, bukan `pii_detected`.
 *     `pii_detected` hanyalah dugaan pemindai; keputusannya ada pada
 *     `applied_status` (diisi otomatis oleh ColumnAutoAssigner, dapat ditinjau
 *     ulang pengguna). Memakai dugaan mentah akan memasukkan tebakan yang belum
 *     ditinjau ke laporan insiden resmi.
 *
 *  2b. HASIL DEEP SCAN AI DIDAHULUKAN, pindai standar hanya cadangan.
 *     Catatan lama di sini menyatakan sumbernya HARUS `scan_results` karena
 *     "blob AI tidak menyimpan applied_status". Itu sudah tidak benar: sejak
 *     DataDiscoveryController menulis `$aiResult['tables'] = $originalSchema`
 *     lalu menjalankannya lewat ColumnAutoAssigner, blob AI membawa
 *     `applied_status` yang sama.
 *
 *     Yang tetap benar: untuk sistem yang deep scan-nya dijalankan SEBELUM
 *     penyelarasan itu ada, `scan_results` masih berisi pindai standar sementara
 *     keputusan AI hanya hidup di `ai_scan_results`. Membaca `scan_results` saja
 *     membuat sistem-sistem itu menyodorkan hasil pindai standar seolah deep
 *     scan-nya tidak pernah ada.
 *
 *     Karena itu sumbernya dipilih per sistem: pakai `ai_scan_results` bila ia
 *     memuat keputusan, kalau tidak barulah `scan_results`. Sumber yang terpakai
 *     ikut dikirim ke UI — orang harus bisa melihat daftar ini berasal dari mana
 *     tanpa menebak.
 *
 *  3. Klien hanya mengirim NAMA TABEL. Daftar kolom PII tidak pernah diambil
 *     dari kiriman klien melainkan dibaca ulang dari hasil pindai milik kita
 *     sendiri. Kalau klien boleh menentukan kolomnya, isi laporan insiden —
 *     yang dipakai untuk pemberitahuan resmi — bisa dikarang dari luar.
 */
class BreachDataDiscoveryController extends Controller
{
    /** GET /breach/sistem-terpindai — hanya sistem yang pindainya sudah selesai. */
    public function scannedSystems(Request $request)
    {
        $systems = InformationSystem::where('org_id', $request->user()->org_id)
            ->where('scanning_status', 'done')
            ->orderBy('name')
            // `ai_scan_results` ikut diambil: tanpanya sumber katalognya selalu
            // terbaca 'standar' di daftar ini walau sistemnya sudah di-deep-scan,
            // sehingga jumlah tabel yang dilaporkan pun berasal dari blob yang
            // salah. Keduanya kolom JSON besar, jadi daftar kolomnya tetap
            // dibatasi — yang tidak dipakai tidak ikut ditarik.
            ->get(['id', 'name', 'code', 'source_type', 'last_scanned_at', 'pii_alert_count', 'pdp_alert_count', 'scan_results', 'ai_scan_results']);

        $data = [];
        foreach ($systems as $s) {
            $katalog = $this->katalog($s);
            $data[] = [
                'id' => $s->id,
                'name' => $s->name,
                // Kolom `code` ditambahkan oleh migrasi yang memasangnya di
                // dalam perulangan, sehingga analisis statis tidak dapat
                // membuktikan kolomnya ada. Dibaca lewat getAttribute() agar
                // tetap terkirim tanpa perlu menyembunyikan galatnya.
                'code' => $s->getAttribute('code'),
                'source_type' => $s->source_type,
                'last_scanned_at' => $s->last_scanned_at,
                'pii_alert_count' => $s->pii_alert_count,
                'pdp_alert_count' => $s->pdp_alert_count,
                'jumlah_tabel_ber_pii' => count($katalog['tabel']),
                // Dari mana daftar tabelnya berasal. Dikirim supaya orang tidak
                // perlu menebak kenapa sebuah sistem menampilkan tabel tertentu.
                'sumber_katalog' => $katalog['sumber'],
            ];
        }

        return response()->json([
            'data' => $data,
            // Sistem yang belum dipindai sengaja tidak dikirim sama sekali —
            // memilihnya tidak akan menghasilkan tipe data apa pun.
            'catatan' => 'Hanya sistem dengan status pindai selesai yang dapat dipilih.',
        ]);
    }

    /** GET /breach/sistem/{systemId}/tabel — tabel ber-PII beserta kolomnya. */
    public function systemTables(Request $request, string $systemId)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($systemId);

        if ($system->scanning_status !== 'done') {
            return response()->json([
                'message' => 'Sistem ini belum selesai dipindai, sehingga tabel ber-PII-nya belum diketahui.',
                'scanning_status' => $system->scanning_status,
            ], 422);
        }

        $katalog = $this->katalog($system);

        return response()->json([
            'data' => [
                'sistem' => ['id' => $system->id, 'name' => $system->name],
                'sumber' => $katalog['sumber'],
                'tabel' => $katalog['tabel'],
            ],
        ]);
    }

    /**
     * PUT /breach/{id}/sistem-terdampak — simpan pilihan & turunkan tipe datanya.
     */
    public function saveAffected(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $breach = BreachIncident::where('org_id', $orgId)->findOrFail($id);

        $data = $request->validate([
            'sistem' => 'present|array',
            'sistem.*.information_system_id' => 'required|uuid',
            'sistem.*.tables' => 'required|array|min:1',
            'sistem.*.tables.*' => 'string|max:191',
        ]);

        $terpilih = [];
        $kolom = [];
        $kategori = [];

        foreach ($data['sistem'] as $baris) {
            // Dicari ulang dengan penyaring org — id sistem milik tenant lain
            // tidak boleh bisa disisipkan lewat badan permintaan.
            $system = InformationSystem::where('org_id', $orgId)->find($baris['information_system_id']);
            if (! $system || $system->scanning_status !== 'done') {
                continue;
            }

            // Sumber yang sama dengan yang ditawarkan ke pemilihnya — kalau
            // berbeda, tabel yang tampil bisa tidak ditemukan saat disimpan.
            $tersedia = collect($this->katalog($system)['tabel'])->keyBy('nama');
            $tabelTerpilih = [];

            foreach ($baris['tables'] as $namaTabel) {
                $tabel = $tersedia->get($namaTabel);
                if (! $tabel) {
                    continue; // tabel tidak ada di hasil pindai, atau tidak ber-PII
                }
                $tabelTerpilih[] = $tabel;
                foreach ($tabel['kolom_pii'] as $c) {
                    $kolom[] = $c['nama'];
                    if (! empty($c['kategori_pdp'])) {
                        $kategori[] = $c['kategori_pdp'];
                    }
                }
            }

            if ($tabelTerpilih) {
                $terpilih[] = [
                    'information_system_id' => $system->id,
                    'system_name' => $system->name,
                    'tables' => $tabelTerpilih,
                ];
            }
        }

        $sebelumnya = $breach->affected_data_types;

        $breach->forceFill([
            'affected_systems' => $terpilih,
            // Ditulis sebagai teks dipisah koma, BUKAN larik. Meski kolomnya
            // di-cast 'array', seluruh penulis dan pembacanya memperlakukannya
            // sebagai string — UI insiden memanggil `.split(',')` di dua tempat
            // dan akan langsung galat bila menerima larik. Bentuk terstrukturnya
            // sudah tersimpan utuh di `affected_systems`.
            'affected_data_types' => implode(', ', array_values(array_unique($kolom))),
            'affected_data_categories' => array_values(array_unique($kategori)),
        ])->save();

        // Nilai lama ikut dicatat: pilihan ini MENGGANTI tipe data yang mungkin
        // sudah diketik manual, dan penggantian diam-diam pada isi laporan
        // insiden bukan sesuatu yang boleh hilang jejaknya.
        $this->log($request, $breach, $sebelumnya, count($terpilih));

        return response()->json([
            'message' => 'Sistem terdampak disimpan, tipe data diturunkan dari hasil pindai.',
            'data' => $breach->fresh(),
        ]);
    }

    /** Status kolom yang dihitung sebagai keputusan, bukan dugaan. */
    private const STATUS_DIPUTUSKAN = ['applied_pribadi', 'applied_sensitive'];

    /**
     * Katalog tabel sebuah sistem beserta ASALNYA.
     *
     * Deep scan AI didahulukan; pindai standar hanya dipakai kalau deep scan
     * belum pernah menghasilkan keputusan. Lihat catatan 2b di kepala kelas.
     *
     * @return array{sumber: string, tabel: array<int, array<string, mixed>>}
     */
    private function katalog(InformationSystem $system): array
    {
        $ai = $system->ai_scan_results['tables'] ?? null;
        if (is_array($ai) && $this->adaKeputusan($ai)) {
            return ['sumber' => 'deep_scan_ai', 'tabel' => $this->tabelBerPiiDari($ai)];
        }

        $standar = $system->scan_results['tables'] ?? [];
        if (! is_array($standar)) {
            $standar = [];
        }

        return [
            // Pindai standar yang kolomnya sudah ditinjau AI tetap hasil deep
            // scan: sejak penyelarasan di DataDiscoveryController, keputusan AI
            // memang ditulis balik ke `scan_results` dan ditandai 'ai_scan'.
            'sumber' => $this->adaTandaAi($standar) ? 'deep_scan_ai' : 'standar',
            'tabel' => $this->tabelBerPiiDari($standar),
        ];
    }

    /** @param  array<int, mixed>  $tables */
    private function adaKeputusan(array $tables): bool
    {
        foreach ($tables as $tabel) {
            foreach ((is_array($tabel) ? ($tabel['columns'] ?? []) : []) as $kolom) {
                if (in_array((string) ($kolom['applied_status'] ?? ''), self::STATUS_DIPUTUSKAN, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param  array<int, mixed>  $tables */
    private function adaTandaAi(array $tables): bool
    {
        foreach ($tables as $tabel) {
            foreach ((is_array($tabel) ? ($tabel['columns'] ?? []) : []) as $kolom) {
                if (($kolom['applied_note'] ?? null) === 'ai_scan') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Tabel yang punya minimal satu kolom ber-PII, beserta kolomnya.
     *
     * Tabel tanpa PII sengaja tidak dikembalikan — menyodorkan seluruh tabel
     * hanya membuat pemilihnya harus menebak mana yang relevan.
     *
     * @param  array<int, mixed>  $tables
     * @return array<int, array<string, mixed>>
     */
    private function tabelBerPiiDari(array $tables): array
    {
        $hasil = [];

        foreach ($tables as $tabel) {
            if (! is_array($tabel)) {
                continue;
            }

            $kolomPii = [];
            foreach (($tabel['columns'] ?? []) as $kolom) {
                // Yang dipakai `applied_status`, BUKAN `pii_detected`.
                // `pii_detected` adalah dugaan mentah pemindai; sebuah kolom
                // baru dihitung data pribadi setelah keputusannya ditetapkan
                // (oleh ColumnAutoAssigner atau ditinjau ulang oleh pengguna).
                // Memakai dugaan mentah berarti memasukkan tebakan yang belum
                // ditinjau ke dalam laporan insiden resmi — aturan yang sama
                // sudah dipegang jalur "tarik dari Data Discovery" di UI.
                $status = (string) ($kolom['applied_status'] ?? '');
                if (! in_array($status, self::STATUS_DIPUTUSKAN, true)) {
                    continue;
                }
                // Kolom yang disamarkan disimpan dengan nama asli yang tidak
                // bermakna (mis. "A1") dan alias yang bermakna ("NIK") —
                // yang ditampilkan aliasnya, yang dicatat keduanya.
                $alias = trim((string) ($kolom['alias'] ?? ''));
                $kolomPii[] = [
                    'nama' => $alias !== '' ? $alias : ($kolom['name'] ?? ''),
                    'kolom_asli' => $kolom['name'] ?? '',
                    // Kategori diturunkan dari KEPUTUSANnya, bukan dari tebakan
                    // `pdp_category` pemindai: 'sensitif' pada UU PDP adalah
                    // Data Pribadi bersifat spesifik.
                    'kategori_pdp' => $status === 'applied_sensitive' ? 'spesifik' : 'umum',
                ];
            }

            if ($kolomPii) {
                $hasil[] = [
                    'nama' => $tabel['name'] ?? '',
                    'jumlah_baris' => $tabel['row_count'] ?? null,
                    'kolom_pii' => $kolomPii,
                ];
            }
        }

        return $hasil;
    }

    private function log(Request $request, BreachIncident $breach, mixed $sebelumnya, int $jumlahSistem): void
    {
        try {
            AuditLog::create([
                'module' => 'breach',
                'record_id' => $breach->id,
                'action' => 'affected_systems_updated',
                'user_name' => $request->user()->name ?? 'Unknown',
                'user_role' => $request->user()->role ?? 'user',
                'section' => 'data_discovery',
                'changes' => [
                    'incident_code' => $breach->incident_code,
                    'jumlah_sistem' => $jumlahSistem,
                    'tipe_data_sebelumnya' => $sebelumnya,
                    'tipe_data_sekarang' => $breach->affected_data_types,
                    'kategori_pdp' => $breach->affected_data_categories,
                ],
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Audit log sistem terdampak gagal: '.$e->getMessage());
        }
    }
}
