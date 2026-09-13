<?php

namespace App\Services\ConnectionMap;

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

    /** @return array<string, mixed> */
    public function forRecord(string $orgId, string $type, string $id): array
    {
        $pusat = RelationCatalog::NODE_PREFIX[$type].':'.$id;

        [$nodes, $edges] = $this->expand($orgId, [$type => [$id]]);

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
        ];
    }

    /** @return array<string, mixed> */
    public function forModule(string $orgId, string $type): array
    {
        $sumber = RelationCatalog::nodeSources()[$type] ?? null;
        if (! $sumber) {
            return $this->kosong($type);
        }

        $total = $this->baseQuery($orgId, $sumber)->count();
        $ids = $this->baseQuery($orgId, $sumber)
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
        ];
    }

    /** @return array<string, mixed> */
    private function kosong(string $type): array
    {
        return ['nodes' => [], 'edges' => [], 'center' => null, 'center_type' => $type, 'lanes' => null, 'truncated' => null];
    }

    /**
     * Satu langkah penelusuran dari sekumpulan benih, lalu simpulnya diambil.
     *
     * @param  array<string, array<int, string>>  $benih  jenis → daftar id
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function expand(string $orgId, array $benih): array
    {
        // Seluruh pengetahuan relasi datang dari resolver bersama — pemindai
        // DSPM memakai yang sama, sehingga kedua peta tidak mungkin menyimpang.
        $pasangan = $this->resolver->resolve($orgId, $benih);

        // Simpul diambil per jenis dengan penyaring org — inilah penjaga tenant.
        $perJenis = [];
        foreach ($benih as $type => $ids) {
            $perJenis[$type] = array_merge($perJenis[$type] ?? [], $ids);
        }
        foreach ($pasangan as [$ft, $fi, $tt, $ti]) {
            $perJenis[$ft][] = $fi;
            $perJenis[$tt][] = $ti;
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

        $rows = $this->baseQuery($orgId, $sumber)->whereIn('id', $ids)->get($kolom);

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

    /** @param array<string, mixed> $sumber */
    private function baseQuery(string $orgId, array $sumber): Builder
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

        $status = $this->baseQuery($orgId, $sumber)
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
