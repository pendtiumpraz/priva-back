<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BreachIncident;
use App\Models\CrossBorderTransfer;
use App\Models\LiaAssessment;
use App\Models\Ropa;
use App\Models\TiaAssessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Peta koneksi satu RoPA ke seluruh modul yang terkait dengannya.
 *
 * Grafnya SEPENUHNYA diturunkan dari relasi yang sudah tercatat — bukan
 * digambar ulang atau ditebak. Kalau sebuah tepi muncul, artinya ada baris
 * penghubung sungguhan di basis data. Itu yang membuat tampilan ini layak
 * dipercaya sebagai bukti keterkaitan, bukan sekadar diagram hiasan.
 *
 * Arah tepi mengikuti aliran makna, bukan arah foreign key:
 *
 *   Sistem Informasi ─┐                    ┌─> DPIA ─> item RTP
 *   Titik Consent ────┼─> [ RoPA ] ────────┼─> Transfer Lintas Negara
 *                     ┘                    ├─> LIA / TIA
 *                                          └─> Insiden Kebocoran
 *
 * Sisi kiri adalah SUMBER data pemrosesan; sisi kanan adalah KONSEKUENSI dan
 * penilaian yang lahir dari pemrosesan itu. RoPA berada di tengah karena ia
 * memang simpul yang menautkan keduanya.
 *
 * Read-only. Tidak ada mutasi di sini; simpul hanya membawa `href` agar
 * frontend dapat membuka modul tujuannya di tab baru.
 */
class RopaGraphController extends Controller
{
    public function show(Request $request, string $id): JsonResponse
    {
        $ropa = Ropa::with([
            'informationSystems:id,name,source_type,pdp_alert_count',
            'consentPoints:id,collection_id,name,kind',
            'dpias:id,registration_number,ropa_id,risk_level,status,mitigation_tracking',
            'dpiaCoverages:id,registration_number,risk_level,status,mitigation_tracking',
        ])->findOrFail($id);

        $nodes = [];
        $edges = [];

        // Simpul pusat.
        $nodes[] = [
            'id' => 'ropa:'.$ropa->id,
            'type' => 'ropa',
            'label' => $ropa->processing_activity ?: 'RoPA',
            'code' => $ropa->registration_number,
            'meta' => array_filter([
                'risk' => $ropa->risk_level,
                'status' => $ropa->status,
                'entity' => $ropa->entity,
                'division' => $ropa->division,
            ]),
            'href' => '/ropa?open='.$ropa->id,
        ];

        // ---- SUMBER (kiri) : Sistem Informasi / Data Discovery ----
        foreach ($ropa->informationSystems as $sys) {
            $nid = 'system:'.$sys->id;
            $nodes[] = [
                'id' => $nid,
                'type' => 'data_discovery',
                'label' => $sys->name ?: 'Sistem',
                'code' => $sys->source_type,
                'meta' => array_filter(['pdp_alerts' => $sys->pdp_alert_count]),
                'href' => '/data-discovery?open='.$sys->id,
            ];
            // Sumber mengalir MASUK ke RoPA.
            $edges[] = self::edge($nid, 'ropa:'.$ropa->id, 'memasok');
        }

        // ---- SUMBER (kiri) : Titik pengumpulan Consent ----
        foreach ($ropa->consentPoints as $cp) {
            $nid = 'consent:'.$cp->id;
            $nodes[] = [
                'id' => $nid,
                'type' => 'consent',
                'label' => $cp->name ?: 'Titik Consent',
                'code' => $cp->collection_id,
                'meta' => array_filter(['jenis' => $cp->kind]),
                'href' => '/consent?open='.$cp->id,
            ];
            $edges[] = self::edge($nid, 'ropa:'.$ropa->id, 'dasar consent');
        }

        // ---- KONSEKUENSI (kanan) : DPIA, dan tiap DPIA -> item RTP ----
        // hasMany dan belongsToMany digabung; satu DPIA dapat muncul lewat
        // keduanya, jadi didedup berdasarkan id agar tidak menggandakan simpul.
        $dpias = $ropa->dpias->concat($ropa->dpiaCoverages)->unique('id');

        foreach ($dpias as $dpia) {
            $nid = 'dpia:'.$dpia->id;
            $nodes[] = [
                'id' => $nid,
                'type' => 'dpia',
                'label' => $dpia->registration_number ?: 'DPIA',
                'code' => $dpia->registration_number,
                'meta' => array_filter(['risk' => $dpia->risk_level, 'status' => $dpia->status]),
                'href' => '/dpia?open='.$dpia->id,
            ];
            $edges[] = self::edge('ropa:'.$ropa->id, $nid, 'menilai');

            // Item Risk Treatment Plan hidup sebagai baris pada mitigation_tracking
            // DPIA, bukan tabel tersendiri. Ditampilkan sebagai satu simpul
            // ringkasan per DPIA supaya grafnya tidak meledak jadi puluhan simpul
            // kecil; jumlahnya dibawa di meta, dan tautannya ke RTP teragregat.
            $items = is_array($dpia->mitigation_tracking) ? $dpia->mitigation_tracking : [];
            if ($items) {
                $done = collect($items)->filter(fn ($i) => ($i['status'] ?? null) === 'completed')->count();
                $rid = 'rtp:'.$dpia->id;
                $nodes[] = [
                    'id' => $rid,
                    'type' => 'rtp',
                    'label' => count($items).' item penanganan risiko',
                    'code' => $done.'/'.count($items).' selesai',
                    'meta' => ['total' => count($items), 'done' => $done],
                    'href' => '/risk-treatment-plan',
                ];
                $edges[] = self::edge($nid, $rid, 'menghasilkan');
            }
        }

        // ---- KONSEKUENSI (kanan) : Transfer Lintas Negara ----
        foreach (CrossBorderTransfer::where('linked_ropa_id', $ropa->id)->get() as $cbt) {
            $nid = 'crossborder:'.$cbt->id;
            $nodes[] = [
                'id' => $nid,
                'type' => 'cross_border',
                'label' => $cbt->destination_entity ?: 'Transfer Lintas Negara',
                'code' => $cbt->destination_country ?? null,
                'meta' => array_filter(['status' => $cbt->status ?? null]),
                'href' => '/cross-border?open='.$cbt->id,
            ];
            $edges[] = self::edge('ropa:'.$ropa->id, $nid, 'mentransfer');
        }

        // ---- KONSEKUENSI (kanan) : LIA ----
        foreach (LiaAssessment::where('linked_ropa_id', $ropa->id)->get() as $lia) {
            $nid = 'lia:'.$lia->id;
            $nodes[] = [
                'id' => $nid,
                'type' => 'lia',
                'label' => $lia->lia_code ?: 'LIA',
                'code' => $lia->lia_code ?? null,
                'meta' => array_filter(['status' => $lia->status ?? null]),
                'href' => '/lia?open='.$lia->id,
            ];
            $edges[] = self::edge('ropa:'.$ropa->id, $nid, 'menimbang');
        }

        // ---- KONSEKUENSI (kanan) : TIA ----
        foreach (TiaAssessment::where('linked_ropa_id', $ropa->id)->get() as $tia) {
            $nid = 'tia:'.$tia->id;
            $nodes[] = [
                'id' => $nid,
                'type' => 'tia',
                'label' => $tia->tia_code ?: 'TIA',
                'code' => $tia->tia_code ?? null,
                'meta' => array_filter(['status' => $tia->status ?? null]),
                'href' => '/tia?open='.$tia->id,
            ];
            $edges[] = self::edge('ropa:'.$ropa->id, $nid, 'menilai transfer');
        }

        // ---- KONSEKUENSI (kanan) : Insiden Kebocoran ----
        // Breach menautkan lewat kolom tunggal lama ATAU larik baru; keduanya
        // diperiksa supaya tautan yang dibuat sebelum migrasi larik tetap muncul.
        $breaches = BreachIncident::where('linked_ropa_id', $ropa->id)
            ->orWhereJsonContains('linked_ropa_ids', $ropa->id)
            ->get();

        foreach ($breaches as $breach) {
            $nid = 'breach:'.$breach->id;
            $nodes[] = [
                'id' => $nid,
                'type' => 'breach',
                'label' => $breach->title ?: 'Insiden',
                'code' => $breach->incident_code ?? null,
                'meta' => array_filter(['severity' => $breach->severity ?? null]),
                'href' => '/breach?open='.$breach->id,
            ];
            $edges[] = self::edge('ropa:'.$ropa->id, $nid, 'terdampak');
        }

        return response()->json([
            'data' => [
                'nodes' => $nodes,
                'edges' => $edges,
                'center' => 'ropa:'.$ropa->id,
            ],
        ]);
    }

    /** @return array<string, string> */
    private static function edge(string $from, string $to, string $label): array
    {
        return ['from' => $from, 'to' => $to, 'label' => $label];
    }
}
