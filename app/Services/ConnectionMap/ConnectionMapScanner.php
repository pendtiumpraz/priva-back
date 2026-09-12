<?php

namespace App\Services\ConnectionMap;

use App\Models\BreachIncident;
use App\Models\ConsentCollectionPoint;
use App\Models\CrossBorderTransfer;
use App\Models\Dpia;
use App\Models\DsrRequest;
use App\Models\InformationSystem;
use App\Models\LiaAssessment;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TiaAssessment;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Scanner Peta Koneksi seluruh modul (DSPM).
 *
 * Perluasan RopaGraphController dari "satu RoPA" menjadi "satu organisasi":
 * membaca seluruh catatan modul milik sebuah org lalu menurunkan graf
 * keterkaitannya dari relasi yang BENAR-BENAR tercatat — pivot, foreign key,
 * dan tautan di wizard_data. Tidak ada tepi yang ditebak; kalau sebuah tepi
 * muncul, ada baris penghubung sungguhan di belakangnya.
 *
 * Bentuk hasil: organisasi di pusat, lalu modul, lalu catatannya. Tepi
 * menghubungkan catatan antar-modul dan arahnya mengikuti aliran makna
 * (sumber → pemrosesan → konsekuensi), sama seperti peta satu RoPA.
 *
 * Isolasi tenant dijaga berlapis karena scanner ini juga aman dipanggil di
 * luar request (artisan/queue), tempat global scope `org` tidak aktif:
 *   1. setiap query memfilter `org_id` secara eksplisit;
 *   2. tabel penghubung tanpa org_id (dsr_request_scopes, dsr_executions)
 *      dibatasi lewat induknya yang ber-org_id;
 *   3. tepi hanya dipasang bila KEDUA ujungnya simpul org ini — baris
 *      penghubung yang salah-scope pun tidak dapat menarik catatan tenant
 *      lain ke peta.
 *
 * Label simpul hanya memakai data non-pribadi (nama aktivitas, kode, nama
 * sistem/pihak ketiga). DSR hanya membawa nomor + jenis permintaan: nama dan
 * email pemohon TIDAK pernah masuk hasil scan, karena JSON ini dapat berakhir
 * di storage eksternal dan diunduh.
 */
class ConnectionMapScanner
{
    public const SCHEMA_VERSION = 1;

    /**
     * Pengaman agar peta tetap dapat digambar. Bila sebuah modul melebihinya,
     * yang dipotong lebih dulu adalah catatan yang paling sedikit terhubung;
     * jumlah aslinya tetap dilaporkan di `modules[].total`.
     */
    public const MAX_NODES_PER_MODULE = 400;

    /** Urutan modul searah jarum jam di peta — modul yang saling bertaut diletakkan berdekatan. */
    public const MODULE_ORDER = [
        'ropa', 'dpia', 'rtp', 'lia', 'tia', 'cross_border', 'third_party',
        'breach', 'dsr', 'data_discovery', 'consent',
    ];

    /** Arti tepi, dibaca dari `from` ke `to`. */
    public const RELATION_LABELS = [
        'supplies' => 'memasok data',
        'consent_basis' => 'dasar consent',
        'assessed_by_dpia' => 'dinilai DPIA',
        'treated_by' => 'ditangani RTP',
        'transfers' => 'mentransfer',
        'balanced_by_lia' => 'dinilai LIA',
        'referenced_by_lia' => 'dirujuk LIA',
        'assessed_by_tia' => 'dinilai TIA',
        'impacted_by' => 'terdampak insiden',
        'processed_by' => 'diproses pihak ketiga',
        'shared_to_controller' => 'dibagikan ke pengendali lain',
        'joint_controller_with' => 'pengendali bersama',
        'sub_processed_by' => 'diproses subprosesor',
        'involves_third_party' => 'melibatkan pihak ketiga',
        'received_by' => 'diterima pihak ketiga',
        'targets' => 'menyasar sistem',
    ];

    /** Peran tautan RoPA ↔ pihak ketiga (pivot `ropa_vendor`) → jenis tepi. */
    private const ROLE_RELATIONS = [
        Vendor::ROLE_CONTROLLER => 'shared_to_controller',
        Vendor::ROLE_PROCESSOR => 'processed_by',
        Vendor::ROLE_JOINT_CONTROLLER => 'joint_controller_with',
        Vendor::ROLE_SUB_PROCESSOR => 'sub_processed_by',
    ];

    /** @return array<string, mixed> */
    public function scan(Organization $org): array
    {
        $orgId = (string) $org->id;
        $nodes = [];
        $links = [];
        $totals = array_fill_keys(self::MODULE_ORDER, 0);

        // ---- RoPA: tulang punggung — hampir semua tautan lintas modul menunjuk ke sini.
        $ropas = Ropa::query()->where('org_id', $orgId)
            ->get(['id', 'registration_number', 'processing_activity', 'risk_level', 'status', 'wizard_data']);
        $totals['ropa'] = $ropas->count();

        // Peran pihak ketiga per kegiatan (pivot `ropa_vendor`): Pengendali,
        // Prosesor, Pengendali Bersama, atau Subprosesor — kewajiban tiap peran
        // berbeda, jadi tepinya pun dibedakan.
        $thirdPartyRoles = [];
        foreach (DB::table('ropa_vendor')->where('org_id', $orgId)->get(['ropa_id', 'vendor_id', 'role']) as $p) {
            $thirdPartyRoles[$p->ropa_id][$p->vendor_id] = (string) $p->role;
        }

        foreach ($ropas as $r) {
            $this->addNode($nodes, 'ropa:'.$r->id, 'ropa', $r->processing_activity ?: ($r->registration_number ?: 'RoPA'),
                $r->registration_number, ['risk' => $r->risk_level, 'status' => $r->status], '/ropa?open='.$r->id);

            foreach ($thirdPartyRoles[$r->id] ?? [] as $tpId => $role) {
                $links[] = ['ropa:'.$r->id, 'thirdparty:'.$tpId, self::ROLE_RELATIONS[$role] ?? 'processed_by'];
            }

            // RoPA lama yang belum tersinkron ke pivot: daftar UUID di wizard
            // (bagian Penggunaan & Penyimpanan) tetap dibaca, tanpa peran.
            $thirdPartyIds = data_get($r->wizard_data, 'penggunaan_penyimpanan.vendor_ids');
            foreach (is_array($thirdPartyIds) ? $thirdPartyIds : [] as $tpId) {
                if (is_string($tpId) && $tpId !== '' && ! isset($thirdPartyRoles[$r->id][$tpId])) {
                    $links[] = ['ropa:'.$r->id, 'thirdparty:'.$tpId, 'processed_by'];
                }
            }
        }

        // ---- Data Discovery: sistem informasi sebagai sumber data pemrosesan.
        $systems = InformationSystem::query()->where('org_id', $orgId)
            ->get(['id', 'name', 'source_type', 'pdp_alert_count']);
        $totals['data_discovery'] = $systems->count();
        foreach ($systems as $s) {
            $this->addNode($nodes, 'system:'.$s->id, 'data_discovery', $s->name ?: 'Sistem',
                $s->source_type, ['pdp_alerts' => $s->pdp_alert_count ?: null], '/data-discovery?open='.$s->id);
        }
        foreach (DB::table('information_system_ropa')->where('org_id', $orgId)->get(['information_system_id', 'ropa_id']) as $p) {
            $links[] = ['system:'.$p->information_system_id, 'ropa:'.$p->ropa_id, 'supplies'];
        }

        // ---- Consent: titik pengumpulan sebagai dasar pemrosesan.
        $consents = ConsentCollectionPoint::query()->where('org_id', $orgId)
            ->get(['id', 'collection_id', 'name', 'kind']);
        $totals['consent'] = $consents->count();
        foreach ($consents as $c) {
            $this->addNode($nodes, 'consent:'.$c->id, 'consent', $c->name ?: 'Titik Consent',
                $c->collection_id, ['kind' => $c->kind], '/consent?open='.$c->id);
        }
        foreach (DB::table('consent_collection_ropa')->where('org_id', $orgId)->get(['collection_point_id', 'ropa_id']) as $p) {
            $links[] = ['consent:'.$p->collection_point_id, 'ropa:'.$p->ropa_id, 'consent_basis'];
        }

        // ---- DPIA, plus satu simpul ringkasan RTP per DPIA.
        $dpias = Dpia::query()->where('org_id', $orgId)
            ->get(['id', 'registration_number', 'ropa_id', 'risk_level', 'status', 'mitigation_tracking']);
        $totals['dpia'] = $dpias->count();
        foreach ($dpias as $d) {
            $id = 'dpia:'.$d->id;
            $this->addNode($nodes, $id, 'dpia', $d->registration_number ?: 'DPIA', $d->registration_number,
                ['risk' => $d->risk_level, 'status' => $d->status], '/dpia?open='.$d->id);
            if ($d->ropa_id) {
                $links[] = ['ropa:'.$d->ropa_id, $id, 'assessed_by_dpia'];
            }

            // Item RTP hidup sebagai baris mitigation_tracking, bukan tabel
            // sendiri — diringkas jadi satu simpul per DPIA seperti peta satu RoPA.
            $items = array_values(array_filter(is_array($d->mitigation_tracking) ? $d->mitigation_tracking : [], 'is_array'));
            if ($items) {
                $done = count(array_filter($items, fn ($i) => ($i['status'] ?? null) === 'completed'));
                $this->addNode($nodes, 'rtp:'.$d->id, 'rtp', count($items).' item penanganan risiko',
                    $done.'/'.count($items).' selesai', ['total' => count($items), 'done' => $done], '/risk-treatment-plan');
                $links[] = [$id, 'rtp:'.$d->id, 'treated_by'];
                $totals['rtp']++;
            }
        }
        // DPIA ↔ RoPA banyak-ke-banyak (di luar FK ropa_id lama); duplikat dengan
        // FK di atas dilebur saat tepi didedup.
        foreach (DB::table('dpia_ropa')->where('org_id', $orgId)->get(['dpia_id', 'ropa_id']) as $p) {
            $links[] = ['ropa:'.$p->ropa_id, 'dpia:'.$p->dpia_id, 'assessed_by_dpia'];
        }

        // ---- Pihak ketiga.
        $thirdParties = Vendor::query()->where('org_id', $orgId)
            ->get(['id', 'name', 'type', 'country', 'risk_level']);
        $totals['third_party'] = $thirdParties->count();
        foreach ($thirdParties as $v) {
            // Peran bawaan di registri; peran sesungguhnya per kegiatan ada di tepi.
            $defaultRole = Vendor::normalizeRole($v->type);
            $this->addNode($nodes, 'thirdparty:'.$v->id, 'third_party', $v->name ?: 'Pihak Ketiga',
                $v->country, ['risk' => $v->risk_level, 'role' => $defaultRole ? Vendor::ROLE_LABELS[$defaultRole] : null],
                '/vendor-risk?open='.$v->id);
        }

        // ---- Transfer lintas negara.
        $transfers = CrossBorderTransfer::query()->where('org_id', $orgId)
            ->get(['id', 'destination_country', 'destination_entity', 'status', 'risk_level', 'linked_ropa_id', 'vendor_id']);
        $totals['cross_border'] = $transfers->count();
        foreach ($transfers as $t) {
            $id = 'crossborder:'.$t->id;
            $this->addNode($nodes, $id, 'cross_border', $t->destination_entity ?: 'Transfer Lintas Negara',
                $t->destination_country, ['status' => $t->status, 'risk' => $t->risk_level], '/cross-border?open='.$t->id);
            if ($t->linked_ropa_id) {
                $links[] = ['ropa:'.$t->linked_ropa_id, $id, 'transfers'];
            }
            if ($t->vendor_id) {
                $links[] = [$id, 'thirdparty:'.$t->vendor_id, 'received_by'];
            }
        }

        // ---- LIA.
        $lias = LiaAssessment::query()->where('org_id', $orgId)
            ->get(['id', 'lia_code', 'title', 'status', 'linked_ropa_id', 'linked_dpia_id']);
        $totals['lia'] = $lias->count();
        foreach ($lias as $l) {
            $id = 'lia:'.$l->id;
            $this->addNode($nodes, $id, 'lia', $l->title ?: ($l->lia_code ?: 'LIA'), $l->lia_code,
                ['status' => $l->status], '/lia?open='.$l->id);
            if ($l->linked_ropa_id) {
                $links[] = ['ropa:'.$l->linked_ropa_id, $id, 'balanced_by_lia'];
            }
            if ($l->linked_dpia_id) {
                $links[] = ['dpia:'.$l->linked_dpia_id, $id, 'referenced_by_lia'];
            }
        }

        // ---- TIA — dapat bertaut ke RoPA, transfer lintas negara, dan pihak ketiga.
        $tias = TiaAssessment::query()->where('org_id', $orgId)
            ->get(['id', 'tia_code', 'title', 'status', 'overall_risk_level', 'linked_ropa_id', 'linked_cross_border_id', 'linked_vendor_id']);
        $totals['tia'] = $tias->count();
        foreach ($tias as $t) {
            $id = 'tia:'.$t->id;
            $this->addNode($nodes, $id, 'tia', $t->title ?: ($t->tia_code ?: 'TIA'), $t->tia_code,
                ['status' => $t->status, 'risk' => $t->overall_risk_level], '/tia?open='.$t->id);
            if ($t->linked_ropa_id) {
                $links[] = ['ropa:'.$t->linked_ropa_id, $id, 'assessed_by_tia'];
            }
            if ($t->linked_cross_border_id) {
                $links[] = ['crossborder:'.$t->linked_cross_border_id, $id, 'assessed_by_tia'];
            }
            if ($t->linked_vendor_id) {
                $links[] = ['thirdparty:'.$t->linked_vendor_id, $id, 'assessed_by_tia'];
            }
        }

        // ---- Insiden kebocoran. Insiden simulasi (latihan) tidak dipetakan —
        // itu bukan kejadian nyata dan akan menyesatkan pembacaan postur.
        $breaches = BreachIncident::query()->where('org_id', $orgId)->where('is_simulation', false)
            ->get(['id', 'incident_code', 'title', 'severity', 'status', 'linked_ropa_id', 'linked_ropa_ids', 'linked_vendor_ids']);
        $totals['breach'] = $breaches->count();
        foreach ($breaches as $b) {
            $id = 'breach:'.$b->id;
            $this->addNode($nodes, $id, 'breach', $b->title ?: 'Insiden', $b->incident_code,
                ['severity' => $b->severity, 'status' => $b->status], '/breach?open='.$b->id);
            // Kolom tunggal lama DAN larik baru sama-sama dibaca, supaya tautan
            // yang dibuat sebelum migrasi larik tetap muncul.
            $ropaIds = is_array($b->linked_ropa_ids) ? $b->linked_ropa_ids : [];
            if ($b->linked_ropa_id) {
                $ropaIds[] = $b->linked_ropa_id;
            }
            foreach ($ropaIds as $rid) {
                if (is_string($rid) && $rid !== '') {
                    $links[] = ['ropa:'.$rid, $id, 'impacted_by'];
                }
            }

            // Pihak ketiga yang DIPASTIKAN terlibat pada insiden ini. Dugaan
            // hasil penelusuran sengaja tidak digambar: peta ini menampilkan
            // hubungan yang sudah ditegaskan orang, bukan kemungkinan.
            foreach (is_array($b->linked_vendor_ids) ? $b->linked_vendor_ids : [] as $vid) {
                if (is_string($vid) && $vid !== '') {
                    $links[] = [$id, 'thirdparty:'.$vid, 'involves_third_party'];
                }
            }
        }

        // ---- DSR: hanya permintaan yang benar-benar menyasar sistem (scope atau
        // eksekusi). Permintaan yang belum dipetakan ke sistem tidak punya
        // koneksi apa pun untuk digambar; jumlahnya tetap tercatat di `total`.
        $totals['dsr'] = DsrRequest::query()->where('org_id', $orgId)->count();
        $dsrPairs = DB::table('dsr_request_scopes')
            ->join('dsr_requests', 'dsr_requests.id', '=', 'dsr_request_scopes.dsr_request_id')
            ->where('dsr_requests.org_id', $orgId)
            ->whereNull('dsr_requests.deleted_at')
            ->get(['dsr_request_scopes.dsr_request_id as dsr_id', 'dsr_request_scopes.information_system_id as system_id'])
            ->concat(
                DB::table('dsr_executions')
                    ->join('dsr_requests', 'dsr_requests.id', '=', 'dsr_executions.dsr_request_id')
                    ->where('dsr_requests.org_id', $orgId)
                    ->whereNull('dsr_requests.deleted_at')
                    ->whereNull('dsr_executions.deleted_at')
                    ->get(['dsr_executions.dsr_request_id as dsr_id', 'dsr_executions.information_system_id as system_id'])
            );
        foreach ($dsrPairs->pluck('dsr_id')->unique()->chunk(500) as $chunk) {
            $dsrs = DsrRequest::query()->where('org_id', $orgId)->whereIn('id', $chunk->values()->all())
                ->get(['id', 'request_id', 'request_type', 'status']);
            foreach ($dsrs as $d) {
                $this->addNode($nodes, 'dsr:'.$d->id, 'dsr', $d->request_id ?: 'DSR', $d->request_type,
                    ['status' => $d->status], '/dsr?open='.$d->id);
            }
        }
        foreach ($dsrPairs as $p) {
            $links[] = ['dsr:'.$p->dsr_id, 'system:'.$p->system_id, 'targets'];
        }

        // ---- Tepi. Kedua ujung WAJIB simpul org ini — penjaga isolasi tenant
        // terakhir. Kunci dedup (from|to|relasi) melebur tautan ganda, mis. DPIA
        // yang tertaut lewat FK ropa_id sekaligus pivot dpia_ropa.
        $edges = [];
        foreach ($links as [$from, $to, $relation]) {
            if ($from === $to || ! isset($nodes[$from], $nodes[$to])) {
                continue;
            }
            $edges[$from.'|'.$to.'|'.$relation] = [
                'from' => $from,
                'to' => $to,
                'relation' => $relation,
                'label' => self::RELATION_LABELS[$relation],
            ];
        }

        [$nodes, $edges] = $this->capPerModule($nodes, $edges);

        $degree = $this->degrees($edges);
        $neighborTypes = [];
        foreach ($edges as $e) {
            $neighborTypes[$e['from']][$nodes[$e['to']]['type']] = true;
            $neighborTypes[$e['to']][$nodes[$e['from']]['type']] = true;
        }

        $modules = [];
        foreach (self::MODULE_ORDER as $type) {
            $ids = array_keys(array_filter($nodes, fn ($n) => $n['type'] === $type));
            $modules[] = [
                'type' => $type,
                'total' => $totals[$type],
                'included' => count($ids),
                'linked' => count(array_filter($ids, fn ($id) => ($degree[$id] ?? 0) > 0)),
            ];
        }

        $center = 'org:'.$orgId;
        $orgNode = [
            'id' => $center,
            'type' => 'org',
            'label' => (string) ($org->name ?: 'Organisasi'),
            'code' => null,
            'meta' => [],
            'href' => null,
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'org' => ['id' => $orgId, 'name' => $orgNode['label']],
            'center' => $center,
            'modules' => $modules,
            'nodes' => array_values([$center => $orgNode] + $nodes),
            'edges' => array_values($edges),
            'insights' => $this->insights($nodes, $neighborTypes),
            'stats' => [
                'nodes' => count($nodes),
                'edges' => count($edges),
                'linked_nodes' => count(array_filter($degree)),
                'modules_linked' => count(array_filter($modules, fn ($m) => $m['linked'] > 0)),
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $meta
     */
    private function addNode(array &$nodes, string $id, string $type, string $label, ?string $code, array $meta, ?string $href): void
    {
        $nodes[$id] = [
            'id' => $id,
            'type' => $type,
            'label' => mb_substr($label, 0, 160),
            'code' => $code !== null && $code !== '' ? $code : null,
            'meta' => array_filter($meta, fn ($v) => $v !== null && $v !== ''),
            'href' => $href,
        ];
    }

    /**
     * Terapkan MAX_NODES_PER_MODULE: simpul paling sedikit terhubung dipotong
     * lebih dulu, lalu tepi yang menunjuk simpul terpotong ikut dibuang.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, array<string, string>>  $edges
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, string>>}
     */
    private function capPerModule(array $nodes, array $edges): array
    {
        $degree = $this->degrees($edges);
        $byType = [];
        foreach ($nodes as $id => $n) {
            $byType[$n['type']][] = $id;
        }

        $drop = [];
        foreach ($byType as $ids) {
            if (count($ids) <= self::MAX_NODES_PER_MODULE) {
                continue;
            }
            usort($ids, fn ($a, $b) => ($degree[$b] ?? 0) <=> ($degree[$a] ?? 0));
            foreach (array_slice($ids, self::MAX_NODES_PER_MODULE) as $id) {
                $drop[$id] = true;
            }
        }
        if (! $drop) {
            return [$nodes, $edges];
        }

        return [
            array_diff_key($nodes, $drop),
            array_filter($edges, fn ($e) => ! isset($drop[$e['from']]) && ! isset($drop[$e['to']])),
        ];
    }

    /**
     * @param  array<string, array<string, string>>  $edges
     * @return array<string, int>
     */
    private function degrees(array $edges): array
    {
        $degree = [];
        foreach ($edges as $e) {
            $degree[$e['from']] = ($degree[$e['from']] ?? 0) + 1;
            $degree[$e['to']] = ($degree[$e['to']] ?? 0) + 1;
        }

        return $degree;
    }

    /**
     * Celah postur yang terbaca langsung dari bentuk graf: catatan yang
     * seharusnya bertaut ke modul lain tetapi tidak. Hanya aturan yang
     * menemukan sesuatu yang dilaporkan.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, array<string, true>>  $neighborTypes
     * @return list<array{key: string, severity: string, count: int, node_ids: list<string>}>
     */
    private function insights(array $nodes, array $neighborTypes): array
    {
        $rules = [
            // UU PDP Pasal 34: pemrosesan berisiko tinggi wajib DPIA.
            ['high_risk_ropa_without_dpia', 'high', 'ropa',
                fn ($n, $nt) => in_array(strtolower((string) ($n['meta']['risk'] ?? '')), ['high', 'tinggi', 'critical'], true) && ! isset($nt['dpia'])],
            // Data di sistem yang tidak tercatat di aktivitas pemrosesan mana pun.
            ['system_without_ropa', 'medium', 'data_discovery', fn ($n, $nt) => ! isset($nt['ropa'])],
            ['cross_border_without_tia', 'medium', 'cross_border', fn ($n, $nt) => ! isset($nt['tia'])],
            ['breach_without_ropa', 'medium', 'breach', fn ($n, $nt) => ! isset($nt['ropa'])],
            ['ropa_without_system', 'low', 'ropa', fn ($n, $nt) => ! isset($nt['data_discovery'])],
            ['consent_without_ropa', 'low', 'consent', fn ($n, $nt) => ! isset($nt['ropa'])],
        ];

        $out = [];
        foreach ($rules as [$key, $severity, $type, $test]) {
            $ids = [];
            foreach ($nodes as $id => $n) {
                if ($n['type'] === $type && $test($n, $neighborTypes[$id] ?? [])) {
                    $ids[] = $id;
                }
            }
            if ($ids) {
                $out[] = ['key' => $key, 'severity' => $severity, 'count' => count($ids), 'node_ids' => $ids];
            }
        }

        return $out;
    }
}
