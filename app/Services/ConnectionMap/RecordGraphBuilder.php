<?php

namespace App\Services\ConnectionMap;

use App\Models\User;
use App\Support\AssignmentScope;
use App\Support\ContractReviewScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Peta koneksi yang dihitung LANGSUNG, untuk dua tampilan:
 *
 *   - `forRecord()` — satu record di tengah beserta tetangganya;
 *   - `forModule()` — seluruh record satu modul, dikelompokkan per tahap.
 *
 * Berbeda dari ConnectionMapScanner yang memindai se-organisasi lalu MENYIMPAN
 * hasilnya, di sini grafnya dihitung saat diminta. Alasannya bukan selera:
 * tombol peta ada di baris tabel, dan orang menekannya tepat setelah membuat
 * tautan. Peta yang hanya sesegar scan terakhir akan menampilkan keadaan lama
 * dan terasa seperti fitur yang rusak.
 *
 * Pengetahuan relasinya TIDAK ditulis ulang di sini — semuanya dibaca dari
 * RelationCatalog, yang juga menjadi sumber bagi scanner. Itu yang mencegah dua
 * peta perlahan berbeda isi.
 *
 * Isolasi tenant dijaga dengan cara yang sama seperti scanner: simpul diambil
 * per jenis dengan penyaring `org_id`, lalu setiap tepi yang salah satu
 * ujungnya TIDAK termasuk simpul milik org tersebut dibuang. Tautan salah-scope
 * di tabel pivot karena itu tidak dapat menarik record tenant lain ke dalam
 * peta, bahkan bila barisnya ada.
 */
class RecordGraphBuilder
{
    public function __construct(private CatalogLinkResolver $resolver) {}

    /** Batas record modul yang digambar; sisanya dilaporkan lewat `truncated`. */
    public const MAX_MODULE_NODES = 120;

    /** @var array<string, bool> cache Schema::hasColumn — dipanggil berulang per permintaan */
    private array $cacheKolom = [];

    /** @var array<string, bool> cache Schema::hasTable */
    private array $cacheTabel = [];

    /**
     * Jenis simpul yang boleh muncul, atau null bila tidak dibatasi.
     *
     * Disetel pemanggil dari gerbang entitlement. Penyaringannya dilakukan di
     * SATU tempat — saat simpul diambil — sehingga tepi yang menyentuh modul
     * tercabut ikut hilang dengan sendirinya lewat penjaga "kedua ujung harus
     * simpul yang dikenal". Menyaring di sisi tepi akan menyisakan simpul
     * yatim yang tetap membocorkan keberadaannya.
     *
     * @var array<string, true>|null
     */
    private ?array $jenisDiizinkan = null;

    /**
     * Batasi jenis simpul yang boleh muncul (gerbang entitlement).
     *
     * @param  array<int, string>|null  $types  null = tanpa batas
     */
    public function hanyaJenis(?array $types): self
    {
        $this->jenisDiizinkan = $types === null ? null : array_fill_keys($types, true);

        return $this;
    }

    /**
     * User yang sedang melihat peta, atau null bila tanpa penyaringan divisi.
     *
     * Peta HARUS menyaring per divisi seperti halaman daftarnya. Tanpa ini RoPA,
     * DPIA, dan pihak ketiga milik divisi lain — yang di tabelnya sudah
     * tersembunyi — tetap tergambar di peta lengkap dengan nama dan kodenya.
     * Barisnya di sini diambil lewat `DB::table()` mentah, yang tidak pernah kena
     * scope Eloquent, jadi klausanya harus ditempelkan sendiri.
     *
     * Null dipakai konteks tanpa pengguna (artisan, queue, uji unit) dan berarti
     * "tanpa batas" — sama seperti perilaku scope Eloquent-nya.
     */
    private ?User $pengguna = null;

    /** Saring baris sesuai divisi user ini (lihat $pengguna). */
    public function untukPengguna(?User $user): self
    {
        $this->pengguna = $user;

        return $this;
    }

    /**
     * Tempelkan klausa keterlihatan divisi untuk satu jenis simpul.
     *
     * Kolomnya diperiksa lebih dulu: lingkungan yang migrasinya belum jalan tidak
     * boleh membuat seluruh peta galat — di sana memang belum ada penugasan sama
     * sekali, jadi tidak ada yang perlu disaring.
     */
    private function saringDivisi(Builder $q, string $table, string $type, string $orgId): void
    {
        if (! $this->pengguna) {
            return;
        }

        // Telaah kontrak tidak punya penugasan sendiri: divisinya DITURUNKAN
        // dari pihak ketiga di ujung rantai source_document_id → vendor_contracts
        // → vendors. Karena itu ia tidak lolos uji kolom `assign_group` di bawah
        // dan butuh klausanya sendiri — lihat ContractReviewScope.
        //
        // Tanpa ini peta membocorkan persis yang paling menarik: JUDUL kontrak
        // divisi lain, lengkap dengan tautan ke halaman telaahnya.
        if ($type === 'contract_review') {
            ContractReviewScope::terapkan($q, $this->pengguna, $orgId);

            return;
        }

        $ragam = RelationCatalog::visibilityByType()[$type] ?? null;
        if (! $ragam || ! $this->punyaKolom($table, 'assign_group')) {
            return;
        }

        AssignmentScope::terapkan(
            $q,
            $this->pengguna,
            $ragam['created_by'] && $this->punyaKolom($table, 'created_by'),
            $ragam['wizard_ropa'],
        );
    }

    /** @return array<string, mixed> */
    public function forRecord(string $orgId, string $type, string $id): array
    {
        $pusat = RelationCatalog::NODE_PREFIX[$type].':'.$id;

        [$nodes, $edges] = $this->expand($orgId, [$type => [$id]]);

        // Simpul turunan DIBEDAH di peta satu record — lihat bedahTurunan().
        $this->bedahTurunan($orgId, $nodes, $edges);

        // Baru SESUDAH dibedah: batas tetangga berlaku pada simpul yang
        // benar-benar akan digambar, termasuk item RTP hasil pembedahan.
        $this->batasiTetangga($type, $pusat, $nodes, $edges);

        // Simpul pusat harus ada walau tidak punya tetangga sama sekali —
        // peta kosong yang menampilkan dirinya sendiri lebih jujur daripada
        // peta yang tampak gagal memuat.
        if (! isset($nodes[$pusat])) {
            $sendiri = $this->nodesOf($orgId, $type, [$id]);
            $nodes = $sendiri + $nodes;
        }

        return [
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
            'center' => isset($nodes[$pusat]) ? $pusat : null,
            'center_type' => $type,
            'lanes' => null,
            'truncated' => null,
            'peringatan' => $this->peringatanData($type, $pusat, $nodes),
        ];
    }

    /**
     * Buang tetangga yang tidak termasuk pertanyaan peta ini.
     *
     * Lihat RelationCatalog::tetanggaPetaRecord() untuk alasannya per jenis.
     * Simpul pusat tidak pernah ikut dibuang, dan tepi yang kehilangan salah
     * satu ujungnya gugur — tepi menggantung menggambar hubungan ke sesuatu
     * yang tidak ada di layar.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $edges
     */
    private function batasiTetangga(string $type, string $pusat, array &$nodes, array &$edges): void
    {
        $aturan = RelationCatalog::tetanggaPetaRecord()[$type] ?? null;
        if (! $aturan) {
            return;
        }
        $hanya = isset($aturan['hanya']) ? array_fill_keys($aturan['hanya'], true) : null;
        $kecuali = array_fill_keys($aturan['kecuali'] ?? [], true);

        foreach ($nodes as $nid => $n) {
            if ($nid === $pusat) {
                continue;
            }
            $jenis = $n['type'];
            $boleh = $hanya === null ? ! isset($kecuali[$jenis]) : isset($hanya[$jenis]);
            if (! $boleh) {
                unset($nodes[$nid]);
            }
        }

        foreach ($edges as $ek => $e) {
            if (! isset($nodes[$e['from']]) || ! isset($nodes[$e['to']])) {
                unset($edges[$ek]);
            }
        }
    }

    /**
     * Kejanggalan data yang HARUS dilihat orangnya, bukan disembunyikan.
     *
     * Satu RoPA dinilai satu DPIA. Kalau di peta sebuah RoPA muncul dua DPIA
     * atau lebih, itu bukan kekayaan tautan melainkan kesalahan: penilaian yang
     * sama dikerjakan dua kali, dan yang berlebih harus dihapus atau diarahkan
     * ke RoPA lain. Menggambarnya diam-diam membuat orang menyangka itu normal.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @return array<string, mixed>|null
     */
    private function peringatanData(string $type, string $pusat, array $nodes): ?array
    {
        if ($type !== 'ropa') {
            return null;
        }

        $dpia = 0;
        foreach ($nodes as $nid => $n) {
            if ($nid !== $pusat && $n['type'] === 'dpia') {
                $dpia++;
            }
        }
        if ($dpia < 2) {
            return null;
        }

        return [
            'kode' => 'dpia_ganda',
            'jumlah' => $dpia,
            'pesan' => 'Kegiatan ini dinilai oleh '.$dpia.' DPIA. Satu RoPA seharusnya dinilai satu DPIA — hapus yang berlebih, atau arahkan koneksinya ke RoPA lain.',
        ];
    }

    /** @return array<string, mixed> */
    public function forModule(string $orgId, string $type): array
    {
        $sumber = RelationCatalog::nodeSources()[$type] ?? null;
        if (! $sumber) {
            return $this->kosong($type);
        }

        $total = $this->baseQuery($orgId, $type, $sumber)->count();
        $ids = $this->baseQuery($orgId, $type, $sumber)
            ->orderBy('created_at', 'desc')
            ->limit(self::MAX_MODULE_NODES)
            ->pluck('id')
            ->map(fn ($v) => (string) $v)
            ->all();

        if (! $ids) {
            return $this->kosong($type);
        }

        [$nodes, $edges] = $this->expand($orgId, [$type => $ids]);

        // Pastikan seluruh record modul tampil, termasuk yang belum tertaut apa
        // pun — justru itu yang ingin dilihat pada peta global: mana yang masih
        // menggantung sendirian.
        $nodes = $this->nodesOf($orgId, $type, $ids) + $nodes;

        $lanes = $this->laneLabels($orgId, $type, $nodes);

        return [
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
            'center' => null,
            'center_type' => $type,
            'lanes' => $lanes['urutan'],
            'truncated' => $total > count($ids) ? ['shown' => count($ids), 'total' => $total] : null,
            // Peringatan kejanggalan data hanya lahir di peta satu record —
            // di peta se-modul ia akan berlaku untuk ratusan baris sekaligus
            // dan tidak menunjuk apa pun.
            'peringatan' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function kosong(string $type): array
    {
        return ['nodes' => [], 'edges' => [], 'center' => null, 'center_type' => $type, 'lanes' => null, 'truncated' => null, 'peringatan' => null];
    }

    /**
     * SATU langkah penelusuran dari sekumpulan benih, lalu simpulnya diambil.
     *
     * Seluruh pengetahuan relasi datang dari resolver bersama — pemindai DSPM
     * memakai yang sama, sehingga kedua peta tidak mungkin menyimpang.
     *
     * KEDALAMAN. Peta satu record sempat menelusuri DUA lompatan, dengan alasan
     * yang waktu itu benar: sebuah DPIA tidak punya jalan langsung ke pihak
     * ketiga, jadi "DPIA ini menyentuh berapa pihak ketiga" hanya terjawab lewat
     * RoPA yang dinilainya. Pivot `dpia_vendor` lalu dibuat dan alasannya habis.
     *
     * Yang tersisa dari lompatan kedua tinggal kerugiannya: peta satu DPIA ikut
     * menyeret SELURUH tetangga RoPA-nya — sistem, consent, TIA, insiden —
     * padahal tak satu pun menyentuh DPIA itu. Gambar yang seharusnya menjawab
     * "DPIA ini menilai apa, ditangani apa" berubah jadi peta organisasi yang
     * kebetulan berpusat di sebuah DPIA. Tetangga sejauh dua langkah tetap bisa
     * dibaca dengan membuka peta tetangganya sendiri — itulah yang membuat tiap
     * peta menjawab satu pertanyaan, bukan semuanya sekaligus.
     *
     * @param  array<string, array<int, string>>  $benih  jenis → daftar id
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function expand(string $orgId, array $benih): array
    {
        $perJenis = [];
        foreach ($benih as $type => $ids) {
            $perJenis[$type] = array_merge($perJenis[$type] ?? [], $ids);
        }

        $pasangan = $this->resolver->resolve($orgId, $benih);
        foreach ($pasangan as [$ft, $fi, $tt, $ti]) {
            foreach ([[$ft, $fi], [$tt, $ti]] as [$t, $i]) {
                if (! in_array($i, $perJenis[$t] ?? [], true)) {
                    $perJenis[$t][] = $i;
                }
            }
        }

        $nodes = [];
        foreach ($perJenis as $type => $ids) {
            $nodes += $this->nodesOf($orgId, $type, array_values(array_unique($ids)));
        }

        $edges = [];
        foreach ($pasangan as [$ft, $fi, $tt, $ti, $relasi, $label]) {
            $from = RelationCatalog::NODE_PREFIX[$ft].':'.$fi;
            $to = RelationCatalog::NODE_PREFIX[$tt].':'.$ti;
            // Tepi yang salah satu ujungnya bukan simpul milik org ini dibuang.
            // Inilah penjaga isolasi tenant DAN gerbang entitlement sekaligus:
            // jenis yang tidak diizinkan tidak menghasilkan simpul, sehingga
            // tepinya gugur di sini tanpa perlu penyaringan kedua.
            if (! isset($nodes[$from]) || ! isset($nodes[$to]) || $from === $to) {
                continue;
            }
            $edges[$from.'|'.$to.'|'.$relasi] = [
                'from' => $from, 'to' => $to, 'relation' => $relasi, 'label' => $label,
            ];
        }

        return [$nodes, $edges];
    }

    /**
     * Simpul milik org untuk sekumpulan id.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function nodesOf(string $orgId, string $type, array $ids): array
    {
        // Simpul turunan (mis. ringkasan RTP) tidak punya tabel sendiri —
        // ditangani terpisah, dengan gerbang entitlement yang sama.
        $turunan = RelationCatalog::derivedSources()[$type] ?? null;
        if ($turunan !== null) {
            if (! $ids || ($this->jenisDiizinkan !== null && ! isset($this->jenisDiizinkan[$type]))) {
                return [];
            }

            return $this->simpulTurunan($orgId, $type, $ids, $turunan);
        }

        $sumber = RelationCatalog::nodeSources()[$type] ?? null;
        if (! $sumber || ! $ids || ! $this->tabelAda($sumber['table'])) {
            return [];
        }
        // Modul yang entitlement-nya dicabut tidak menghasilkan simpul sama
        // sekali. Jumlahnya sengaja TIDAK dilaporkan: "3 simpul disembunyikan"
        // sudah membocorkan berapa banyak record yang tenant tidak lagi berhak
        // lihat.
        if ($this->jenisDiizinkan !== null && ! isset($this->jenisDiizinkan[$type])) {
            return [];
        }

        // Hanya kolom meta yang benar-benar ada yang diambil: tabel yang belum
        // punya kolomnya (modul lama, atau migrasi yang belum jalan di satu
        // lingkungan) tidak boleh membuat seluruh kuerinya galat.
        $specMeta = [];
        foreach (($sumber['meta'] ?? []) as $kunci => $kolomMeta) {
            if ($this->punyaKolom($sumber['table'], $kolomMeta)) {
                $specMeta[$kunci] = $kolomMeta;
            }
        }

        $kolom = array_values(array_unique(array_filter(array_merge(
            ['id', $sumber['label'], $sumber['code']],
            array_values($specMeta),
        ))));

        $rows = $this->baseQuery($orgId, $type, $sumber)->whereIn('id', $ids)->get($kolom);

        $out = [];
        foreach ($rows as $row) {
            $id = (string) $row->id;
            $nid = RelationCatalog::NODE_PREFIX[$type].':'.$id;
            $label = (string) ($row->{$sumber['label']} ?? '');
            $out[$nid] = [
                'id' => $nid,
                'type' => $type,
                'label' => $label !== '' ? $label : ucfirst(str_replace('_', ' ', $type)),
                'code' => $sumber['code'] ? ($row->{$sumber['code']} ?? null) : null,
                'meta' => RelationCatalog::metaFrom($specMeta, $row),
                'href' => $sumber['href'].$id,
            ];
        }

        return $out;
    }

    /**
     * Mengganti tiap simpul RINGKASAN turunan dengan satu simpul PER ITEM.
     *
     * Hanya dipanggil peta satu record. Di peta se-modul ringkasan justru yang
     * benar: puluhan DPIA yang masing-masing membawa lima item penanganan akan
     * membuat lingkarannya berisi ratusan titik yang tak seorang pun baca.
     * Peta satu record menjawab pertanyaan berbeda — "penanganan apa saja yang
     * dijanjikan DPIA ini, dan sudah sampai mana" — dan di sana "3 item" tidak
     * menjawab apa pun.
     *
     * Tepi ringkasan ikut dibuang dan diganti satu tepi per item, sehingga
     * tidak ada simpul yang menggantung tanpa penghubung.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $edges
     */
    private function bedahTurunan(string $orgId, array &$nodes, array &$edges): void
    {
        foreach (RelationCatalog::derivedSources() as $type => $spec) {
            $prefix = RelationCatalog::NODE_PREFIX[$type].':';

            // Kumpulkan dulu SELURUH pemilik, baru satu query. Peta satu RoPA
            // bisa memuat puluhan DPIA; menanyakannya satu per satu adalah N+1
            // di jalur yang dipanggil tiap kali peta dibuka.
            $pemilik = [];
            foreach (array_keys($nodes) as $nid) {
                if (str_starts_with((string) $nid, $prefix)) {
                    $pemilik[substr((string) $nid, strlen($prefix))] = (string) $nid;
                }
            }
            if ($pemilik === []) {
                continue;
            }
            $isi = $this->itemTurunan($orgId, $type, array_keys($pemilik), $spec);

            foreach ($pemilik as $ownerId => $nid) {
                $items = $isi[$ownerId] ?? [];
                if ($items === []) {
                    continue;
                }

                // Tepi lama dicatat dulu: pemiliknya bisa saja bukan `from`,
                // dan relasinya datang dari katalog — keduanya harus diwarisi
                // tepi per item supaya labelnya tetap benar.
                $asal = [];
                foreach ($edges as $ek => $e) {
                    if ($e['to'] === $nid || $e['from'] === $nid) {
                        $asal[] = $e;
                        unset($edges[$ek]);
                    }
                }
                unset($nodes[$nid]);

                foreach (RelationCatalog::rinciTurunan($items, $spec) as $i => $item) {
                    $iid = $prefix.$ownerId.':'.$i;
                    $nodes[$iid] = [
                        'id' => $iid,
                        'type' => $type,
                        'label' => $item['label'],
                        'code' => $item['code'],
                        'meta' => $item['meta'],
                        // Halaman RTP menyaring per DPIA lewat ?dpia_id — tanpa
                        // itu kliknya mendarat di seluruh daftar penanganan
                        // se-organisasi, yang bukan "data yang tepat".
                        'href' => $spec['href'].'?dpia_id='.$ownerId,
                    ];
                    foreach ($asal as $e) {
                        $from = $e['from'] === $nid ? $iid : $e['from'];
                        $to = $e['to'] === $nid ? $iid : $e['to'];
                        $edges[$from.'|'.$to.'|'.$e['relation']] = [
                            'from' => $from, 'to' => $to,
                            'relation' => $e['relation'], 'label' => $e['label'],
                        ];
                    }
                }
            }
        }
    }

    /**
     * Larik item mentah dari kolom JSON pemilik, dikunci id pemilik.
     *
     * @param  list<string>  $ownerIds
     * @param  array<string, mixed>  $spec
     * @return array<string, array<int, mixed>>
     */
    private function itemTurunan(string $orgId, string $type, array $ownerIds, array $spec): array
    {
        if ($ownerIds === [] || ! $this->tabelAda($spec['table']) || ! $this->punyaKolom($spec['table'], $spec['column'])) {
            return [];
        }

        $q = DB::table($spec['table'])->select('id', $spec['column']);
        // org_id TETAP disaring di sini walau id pemiliknya datang dari graf
        // yang sudah discoping: query builder mentah tidak kena global scope
        // `org`, jadi id tebakan dari modul lain akan terbaca tanpa penjaga ini.
        if ($this->punyaKolom($spec['table'], 'org_id')) {
            $q->where('org_id', $orgId);
        }
        // Item penanganan risiko hidup DI DALAM baris DPIA-nya. Kalau DPIA itu
        // bukan milik divisi user, isinya pun bukan — dan judul tiap item sering
        // menyebut sistem, vendor, atau kelemahan yang justru paling sensitif.
        $this->saringDivisi($q, $spec['table'], $type, $orgId);

        $out = [];
        foreach ($q->whereIn('id', $ownerIds)->get() as $row) {
            $isi = $row->{$spec['column']};
            $items = is_string($isi) ? json_decode($isi, true) : $isi;
            $out[(string) $row->id] = is_array($items) ? $items : [];
        }

        return $out;
    }

    /**
     * Simpul ringkasan yang diturunkan dari kolom JSON pemiliknya.
     *
     * Id-nya sama dengan id baris pemilik — itulah yang membuat tepinya bisa
     * dibentuk tanpa tabel penghubung. Baris berisi larik kosong tidak
     * menghasilkan simpul sama sekali, sejalan dengan resolver yang juga tidak
     * menghasilkan tepinya.
     *
     * @param  array<int, string>  $ids
     * @param  array<string, mixed>  $spec
     * @return array<string, array<string, mixed>>
     */
    private function simpulTurunan(string $orgId, string $type, array $ids, array $spec): array
    {
        if (! $this->tabelAda($spec['table']) || ! $this->punyaKolom($spec['table'], $spec['column'])) {
            return [];
        }

        $q = DB::table($spec['table']);
        if ($this->punyaKolom($spec['table'], 'org_id')) {
            $q->where('org_id', $orgId);
        }
        if ($this->punyaKolom($spec['table'], 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        // Ringkasannya mewarisi keterlihatan baris pemiliknya — lihat itemTurunan().
        $this->saringDivisi($q, $spec['table'], $type, $orgId);

        $out = [];
        foreach ($q->whereIn('id', $ids)->get(['id', $spec['column']]) as $row) {
            $isi = $row->{$spec['column']};
            $items = is_string($isi) ? json_decode($isi, true) : $isi;
            if (! is_array($items)) {
                continue;
            }
            $ringkas = RelationCatalog::ringkasTurunan($items, $spec);
            if ($ringkas['meta']['total'] === 0) {
                continue;
            }
            $nid = RelationCatalog::NODE_PREFIX[$type].':'.$row->id;
            $out[$nid] = [
                'id' => $nid,
                'type' => $type,
                'label' => $ringkas['label'],
                'code' => $ringkas['code'],
                'meta' => $ringkas['meta'],
                'href' => $spec['href'].'?dpia_id='.$row->id,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $sumber */
    private function baseQuery(string $orgId, string $type, array $sumber): Builder
    {
        $q = DB::table($sumber['table']);
        if ($this->punyaKolom($sumber['table'], 'org_id')) {
            $q->where('org_id', $orgId);
        }
        if (! empty($sumber['soft']) && $this->punyaKolom($sumber['table'], 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        // Insiden latihan bukan kejadian nyata — tidak boleh muncul di peta yang
        // dipakai sebagai bukti keterkaitan.
        if ($this->punyaKolom($sumber['table'], 'is_simulation')) {
            $q->where('is_simulation', false);
        }
        $this->saringDivisi($q, $sumber['table'], $type, $orgId);

        return $q;
    }

    /**
     * Label jalur untuk peta global + penempelan `lane` ke tiap simpul pusat.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @return array{urutan: array<int, string>|null}
     */
    private function laneLabels(string $orgId, string $type, array &$nodes): array
    {
        $def = RelationCatalog::lanes()[$type] ?? null;
        $sumber = RelationCatalog::nodeSources()[$type] ?? null;
        if (! $def || ! $sumber || ! $this->punyaKolom($sumber['table'], $def['column'])) {
            return ['urutan' => null];
        }

        $ids = [];
        foreach ($nodes as $n) {
            if ($n['type'] === $type) {
                $ids[] = substr($n['id'], strlen(RelationCatalog::NODE_PREFIX[$type]) + 1);
            }
        }
        if (! $ids) {
            return ['urutan' => null];
        }

        $status = $this->baseQuery($orgId, $type, $sumber)
            ->whereIn('id', $ids)
            ->pluck($def['column'], 'id');

        $terpakai = [];
        foreach ($nodes as $nid => $n) {
            if ($n['type'] !== $type) {
                continue;
            }
            $rid = substr($nid, strlen(RelationCatalog::NODE_PREFIX[$type]) + 1);
            $kode = (string) ($status[$rid] ?? '');
            $label = $def['order'][$kode] ?? 'Lainnya';
            $nodes[$nid]['lane'] = $label;
            $terpakai[$label] = true;
        }

        // Urutan jalur mengikuti urutan tahap yang dideklarasikan, bukan urutan
        // kemunculan — tahap adalah alur, dan alur yang teracak tidak terbaca.
        $urutan = [];
        foreach ($def['order'] as $label) {
            if (isset($terpakai[$label])) {
                $urutan[] = $label;
            }
        }
        if (isset($terpakai['Lainnya'])) {
            $urutan[] = 'Lainnya';
        }

        return ['urutan' => array_values(array_unique($urutan))];
    }

    private function tabelAda(string $table): bool
    {
        return $this->cacheTabel[$table] ??= Schema::hasTable($table);
    }

    /** Selalu lewat tabelAda() dulu: hasColumn pada tabel yang tidak ada bisa melempar. */
    private function punyaKolom(string $table, string $column): bool
    {
        $key = $table.'.'.$column;

        return $this->cacheKolom[$key] ??= ($this->tabelAda($table) && Schema::hasColumn($table, $column));
    }
}
