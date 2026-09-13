<?php

namespace App\Services\ConnectionMap;

use App\Models\Vendor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menerjemahkan RelationCatalog menjadi pasangan tepi yang konkret.
 *
 * SATU tempat yang menjawab "apa tersambung ke apa", dipakai bersama oleh peta
 * per-record, peta per-modul, dan pemindai DSPM. Sebelumnya pengetahuan itu
 * hidup terpisah di dua tempat, dan dua tempat yang seharusnya sama adalah
 * bentuk yang sudah pernah melahirkan bug F-03 di repo ini.
 *
 * Dua mode:
 *   - berbenih (`$seeds` diisi) — hanya relasi yang menyentuh record tertentu;
 *   - se-organisasi (`$seeds` null) — seluruh tautan milik satu org.
 *
 * Dua kehalusan yang WAJIB dipertahankan, karena keduanya tidak kentara:
 *
 *  1. PERAN MENENTUKAN JENIS TEPI. Pivot `ropa_vendor` dan
 *     `information_system_vendor` membawa kolom `role`; kewajiban tiap peran
 *     berbeda menurut UU PDP, jadi tepinya pun dibedakan — bukan diseragamkan
 *     jadi "diproses".
 *
 *  2. ENTRI CADANGAN KALAH DARI SUMBER UTAMA. Daftar `vendor_ids` di wizard
 *     hanya dipakai bila pasangan itu belum dinyatakan pivot. Tanpa aturan ini,
 *     pihak ketiga yang di pivot berperan `sub_processed_by` akan mendapat tepi
 *     KEDUA `processed_by` — hubungan yang sama tergambar dua kali dengan dua
 *     arti berbeda, dan yang kedua salah.
 */
class CatalogLinkResolver
{
    /** Batas baris yang dipindai saat mencari tautan di dalam kolom JSON. */
    private const MAX_JSON_SCAN = 2000;

    /** @var array<string, bool> */
    private array $cacheKolom = [];

    /** @var array<string, bool> */
    private array $cacheTabel = [];

    /**
     * @param  array<string, array<int, string>>|null  $seeds  jenis → id; null = seluruh org
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     *                                                                                       [fromType, fromId, toType, toId, relation, label]
     */
    public function resolve(string $orgId, ?array $seeds = null): array
    {
        $utama = [];
        $cadangan = [];

        foreach (RelationCatalog::relations() as $r) {
            if ($seeds !== null && ! isset($seeds[$r['from']]) && ! isset($seeds[$r['to']])) {
                continue;
            }
            $pasangan = $this->pasanganUntuk($orgId, $r, $seeds);
            if (! empty($r['is_fallback'])) {
                $cadangan = array_merge($cadangan, $pasangan);
            } else {
                $utama = array_merge($utama, $pasangan);
            }
        }

        // Kunci sengaja MENGABAIKAN slug relasi: cadangan harus kalah walaupun
        // slug-nya berbeda dari yang dihasilkan sumber utama — justru itu
        // kasusnya, ketika pivot menyatakan `sub_processed_by` sementara wizard
        // hanya tahu `processed_by`.
        $sudahAda = [];
        foreach ($utama as $p) {
            $sudahAda[$p[0].'|'.$p[1].'|'.$p[2].'|'.$p[3]] = true;
        }
        foreach ($cadangan as $p) {
            if (! isset($sudahAda[$p[0].'|'.$p[1].'|'.$p[2].'|'.$p[3]])) {
                $utama[] = $p;
            }
        }

        return $utama;
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, array<int, string>>|null  $seeds
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private function pasanganUntuk(string $orgId, array $r, ?array $seeds): array
    {
        if (! $this->tabelAda($r['table'])) {
            return [];
        }

        if ($r['kind'] === 'pivot') {
            return $this->dariPivot($orgId, $r, $seeds);
        }
        if ($r['kind'] === 'fk') {
            return $this->dariForeignKey($orgId, $r, $seeds);
        }
        if ($r['kind'] === 'json_summary') {
            return $this->dariRingkasanJson($orgId, $r, $seeds);
        }

        return $this->dariJson($orgId, $r, $seeds);
    }

    /**
     * Simpul turunan: satu ringkasan per baris pemilik, ber-id SAMA dengan
     * pemiliknya.
     *
     * Tidak ada tabel tujuan yang perlu dicocokkan — yang menentukan ada atau
     * tidaknya tepi hanyalah apakah kolom JSON-nya berisi. Baris dengan larik
     * kosong sengaja TIDAK menghasilkan tepi: DPIA tanpa satu pun item
     * penanganan risiko memang belum ditangani, dan menggambar simpul "0 item"
     * hanya menambah derau.
     *
     * @param  array<string, mixed>  $r
     * @param  array<string, array<int, string>>|null  $seeds
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private function dariRingkasanJson(string $orgId, array $r, ?array $seeds): array
    {
        if (! $this->punyaKolom($r['table'], $r['column'])) {
            return [];
        }

        $q = DB::table($r['table']);
        if ($this->punyaKolom($r['table'], 'org_id')) {
            $q->where('org_id', $orgId);
        }
        if ($this->punyaKolom($r['table'], 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        // Benih boleh menyebut sisi pemilik ATAU sisi turunan — keduanya memakai
        // id yang sama, jadi keduanya menyaring baris yang sama.
        $benih = $seeds[$r['from']] ?? $seeds[$r['to']] ?? null;
        if ($benih !== null) {
            $q->whereIn('id', $benih);
        }

        $out = [];
        foreach ($q->get(['id', $r['column']]) as $row) {
            $isi = $row->{$r['column']};
            $items = is_string($isi) ? json_decode($isi, true) : $isi;
            if (! is_array($items) || ! array_filter($items, 'is_array')) {
                continue;
            }
            $id = (string) $row->id;
            $out[] = [$r['from'], $id, $r['to'], $id, $r['relation'], $r['label']];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, array<int, string>>|null  $seeds
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private function dariPivot(string $orgId, array $r, ?array $seeds): array
    {
        $kiri = $r['from_key'];
        $kanan = $r['to_key'];
        $kolomPeran = $r['role_column'] ?? null;

        $q = DB::table($r['table']);
        if ($this->punyaKolom($r['table'], 'org_id')) {
            $q->where('org_id', $orgId);
        }
        $this->saringBenih($q, $seeds, [$r['from'] => $kiri, $r['to'] => $kanan]);

        $ambil = [$kiri, $kanan];
        if ($kolomPeran) {
            $ambil[] = $kolomPeran;
        }

        $out = [];
        foreach ($q->get($ambil) as $row) {
            $relasi = $r['relation'];
            if ($kolomPeran) {
                $peran = Vendor::normalizeRole($row->{$kolomPeran}) ?? Vendor::ROLE_PROCESSOR;
                $relasi = RelationCatalog::ROLE_RELATIONS[$peran] ?? $r['relation'];
            }
            $out[] = [
                $r['from'], (string) $row->{$kiri},
                $r['to'], (string) $row->{$kanan},
                $relasi, RelationCatalog::labelFor($relasi),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, array<int, string>>|null  $seeds
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private function dariForeignKey(string $orgId, array $r, ?array $seeds): array
    {
        $ownerType = $r['owner_type'] ?? $r['owner'];
        $ownerIdCol = $r['owner_column'] ?? 'id';
        $kolomTarget = $r['column'];
        $ownerAdalahFrom = $ownerType === $r['from'];

        $q = DB::table($r['table'])->whereNotNull($kolomTarget);
        if ($this->punyaKolom($r['table'], 'org_id')) {
            $q->where('org_id', $orgId);
        }
        if ($this->punyaKolom($r['table'], 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        $this->saringBenih($q, $seeds, $ownerAdalahFrom
            ? [$r['from'] => $ownerIdCol, $r['to'] => $kolomTarget]
            : [$r['to'] => $ownerIdCol, $r['from'] => $kolomTarget]);

        $out = [];
        foreach ($q->get([$ownerIdCol, $kolomTarget]) as $row) {
            $ownerId = (string) $row->{$ownerIdCol};
            $targetId = (string) $row->{$kolomTarget};
            $out[] = $ownerAdalahFrom
                ? [$r['from'], $ownerId, $r['to'], $targetId, $r['relation'], RelationCatalog::labelFor($r['relation'])]
                : [$r['from'], $targetId, $r['to'], $ownerId, $r['relation'], RelationCatalog::labelFor($r['relation'])];
        }

        return $out;
    }

    /**
     * Kolom JSON tidak dapat disaring andal lintas MySQL/Postgres/SQLite, jadi
     * barisnya dibaca lalu disaring di PHP — dengan batas tegas.
     *
     * @param  array<string, mixed>  $r
     * @param  array<string, array<int, string>>|null  $seeds
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private function dariJson(string $orgId, array $r, ?array $seeds): array
    {
        $ownerType = $r['owner_type'] ?? $r['owner'];
        $ownerIdCol = $r['owner_column'] ?? 'id';
        $kolomJson = $r['column'];
        $ownerAdalahFrom = $ownerType === $r['from'];
        $targetType = $ownerAdalahFrom ? $r['to'] : $r['from'];

        $q = DB::table($r['table']);
        if ($this->punyaKolom($r['table'], 'org_id')) {
            $q->where('org_id', $orgId);
        }
        if ($this->punyaKolom($r['table'], 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        // Bila benihnya si PEMILIK baris, barisnya dapat disaring langsung.
        // Bila benihnya di sisi target, isinya baru diketahui setelah JSON
        // dibaca — jadi dibatasi dan disaring di PHP.
        $benihPemilik = $seeds[$ownerType] ?? null;
        if ($benihPemilik !== null) {
            $q->whereIn($ownerIdCol, $benihPemilik);
        } else {
            $q->limit(self::MAX_JSON_SCAN);
        }

        $cariTarget = isset($seeds[$targetType]) ? array_flip($seeds[$targetType]) : null;

        $out = [];
        foreach ($q->get([$ownerIdCol, $kolomJson]) as $row) {
            $isi = $row->{$kolomJson};
            $data = is_string($isi) ? json_decode($isi, true) : $isi;
            if (! is_array($data)) {
                continue;
            }
            if (! empty($r['path'])) {
                $data = data_get($data, $r['path']);
            }

            foreach ($this->targetDariJson($r, $data) as $t) {
                if ($benihPemilik === null && $cariTarget !== null && ! isset($cariTarget[$t])) {
                    continue;
                }
                $ownerId = (string) $row->{$ownerIdCol};
                $out[] = $ownerAdalahFrom
                    ? [$r['from'], $ownerId, $r['to'], $t, $r['relation'], RelationCatalog::labelFor($r['relation'])]
                    : [$r['from'], $t, $r['to'], $ownerId, $r['relation'], RelationCatalog::labelFor($r['relation'])];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<string>
     */
    private function targetDariJson(array $r, mixed $data): array
    {
        if ($r['kind'] === 'json_array') {
            return array_values(array_filter(is_array($data) ? $data : [], fn ($v) => is_string($v) && $v !== ''));
        }

        if ($r['kind'] === 'json_items') {
            $out = [];
            foreach (is_array($data) ? $data : [] as $item) {
                $v = is_array($item) ? ($item[$r['item_key']] ?? null) : null;
                if (is_string($v) && $v !== '') {
                    $out[] = $v;
                }
            }

            return $out;
        }

        return is_string($data) && $data !== '' ? [$data] : [];
    }

    /**
     * Saring kueri pada sisi-sisi yang benihnya diketahui.
     *
     * Bila KEDUA sisi berbenih (peta satu record yang jenisnya sama di kedua
     * ujung), keduanya dipakai sebagai OR — memakai AND akan menuntut satu baris
     * menyentuh dua benih sekaligus dan membuang tautan yang sah.
     *
     * @param  array<string, array<int, string>>|null  $seeds
     * @param  array<string, string>  $kolomPerJenis
     */
    private function saringBenih(Builder $q, ?array $seeds, array $kolomPerJenis): void
    {
        if ($seeds === null) {
            return;
        }

        $aktif = array_intersect_key($kolomPerJenis, $seeds);
        if (! $aktif) {
            return;
        }

        $q->where(function ($w) use ($aktif, $seeds) {
            foreach ($aktif as $jenis => $kolom) {
                $w->orWhereIn($kolom, $seeds[$jenis]);
            }
        });
    }

    private function tabelAda(string $table): bool
    {
        return $this->cacheTabel[$table] ??= Schema::hasTable($table);
    }

    private function punyaKolom(string $table, string $column): bool
    {
        $key = $table.'.'.$column;

        return $this->cacheKolom[$key] ??= ($this->tabelAda($table) && Schema::hasColumn($table, $column));
    }
}
