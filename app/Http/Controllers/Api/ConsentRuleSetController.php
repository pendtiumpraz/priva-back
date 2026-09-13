<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\ConsentRule;
use App\Models\ConsentRuleCondition;
use App\Models\ConsentRuleDecision;
use App\Models\ConsentRuleSet;
use App\Services\Consent\ConsentRuleEvaluator;
use App\Services\Consent\ConsentStateResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Penyusun aturan consent — pintu bagi mesin di App\Services\Consent.
 *
 * Daftar aturan disimpan SEKALIGUS, bukan satu per satu (lihat replaceRules).
 * Urutan adalah bagian dari maknanya, dan urutan adalah sifat DAFTAR — bukan
 * sifat tiap barisnya. Menyimpan per baris berarti ada saat ketika separuh
 * susunan baru dan separuh lama berlaku bersamaan; bagi mesin yang memutuskan
 * pengiriman data pribadi, saat seperti itu tidak boleh ada.
 */
class ConsentRuleSetController extends Controller
{
    public function __construct(private ConsentRuleEvaluator $evaluator) {}

    public function index(Request $request)
    {
        $sets = ConsentRuleSet::where('org_id', $request->user()->org_id)
            ->withCount('rules')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $sets]);
    }

    public function show(Request $request, string $id)
    {
        $set = $this->findSet($request, $id);
        $set->load(['rules.conditions']);

        $set->setAttribute('collection_point_ids', ConsentCollectionPoint::where('org_id', $set->org_id)
            ->where('consent_rule_set_id', $set->id)
            ->pluck('id'));

        return response()->json(['data' => $set]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'description' => 'nullable|string|max:2000',
            'default_action' => ['required', Rule::in(ConsentRuleSet::DEFAULT_ACTIONS)],
            'is_active' => 'boolean',
        ]);

        $set = ConsentRuleSet::create([
            'org_id' => $request->user()->org_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'default_action' => $data['default_action'],
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Set aturan dibuat.', 'data' => $set], 201);
    }

    public function update(Request $request, string $id)
    {
        $set = $this->findSet($request, $id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:191',
            'description' => 'nullable|string|max:2000',
            'default_action' => ['sometimes', Rule::in(ConsentRuleSet::DEFAULT_ACTIONS)],
            'is_active' => 'boolean',
        ]);

        $set->update($data);

        return response()->json(['message' => 'Set aturan diperbarui.', 'data' => $set]);
    }

    public function destroy(Request $request, string $id)
    {
        $set = $this->findSet($request, $id);
        $set->delete();

        return response()->json(['message' => 'Set aturan dihapus.']);
    }

    /**
     * Mengganti SELURUH daftar aturan sekaligus, dalam satu transaksi.
     *
     * `sequence` diambil dari posisi di larik, bukan dari nilai yang dikirim
     * klien — supaya urutan yang dilihat penyusun di layar persis urutan yang
     * dijalankan mesin, tanpa bergantung pada klien menomori dengan benar.
     */
    public function replaceRules(Request $request, string $id)
    {
        $set = $this->findSet($request, $id);

        $request->validate([
            'rules' => 'present|array|max:100',
            'rules.*.name' => 'required|string|max:191',
            'rules.*.action' => ['required', Rule::in(ConsentRule::ACTIONS)],
            'rules.*.segment' => 'nullable|string|max:191',
            'rules.*.is_active' => 'boolean',
            // Minimal satu kondisi: aturan tanpa kondisi tidak pernah menyala,
            // jadi menyimpannya berarti menyimpan sesuatu yang pasti tak
            // berguna. Mesin tetap menanganinya, tapi sebagai pertahanan
            // berlapis — bukan jalur normal.
            'rules.*.conditions' => 'required|array|min:1|max:20',
            'rules.*.conditions.*.collection_point_id' => 'required|uuid',
            'rules.*.conditions.*.consent_item_id' => 'required|uuid',
            'rules.*.conditions.*.state' => ['required', Rule::in(ConsentStateResolver::STATES)],
        ]);

        /** @var array<int,array<string,mixed>> $rules */
        $rules = $request->input('rules', []);

        $this->assertSegmentPresent($rules);
        $this->assertPairsBelongToOrg($rules, (string) $set->org_id);

        DB::transaction(function () use ($set, $rules) {
            ConsentRuleCondition::where('rule_id', function ($q) use ($set) {
                $q->select('id')->from('consent_rules')->where('rule_set_id', $set->id);
            })->delete();
            ConsentRule::where('rule_set_id', $set->id)->delete();

            foreach ($rules as $i => $r) {
                $rule = ConsentRule::create([
                    'org_id' => $set->org_id,
                    'rule_set_id' => $set->id,
                    'sequence' => $i,
                    'name' => $r['name'],
                    'action' => $r['action'],
                    'segment' => $r['action'] === ConsentRule::ACTION_BLOCK ? null : ($r['segment'] ?? null),
                    'is_active' => $r['is_active'] ?? true,
                ]);

                foreach ($r['conditions'] as $c) {
                    ConsentRuleCondition::create([
                        'org_id' => $set->org_id,
                        'rule_id' => $rule->id,
                        'collection_point_id' => $c['collection_point_id'],
                        'consent_item_id' => $c['consent_item_id'],
                        'state' => $c['state'],
                    ]);
                }
            }
        });

        $set->load(['rules.conditions']);

        return response()->json(['message' => 'Aturan disimpan.', 'data' => $set]);
    }

    /**
     * Menjalankan set atas seorang subjek TANPA menulis jejak.
     *
     * Pratinjau memakai mesin yang sama persis dengan jalur sungguhan. Kalau
     * pratinjau punya penerapan sendiri, ia akan berbohong tepat ketika paling
     * dibutuhkan — yaitu saat keduanya berbeda.
     */
    public function preview(Request $request, string $id)
    {
        $set = $this->findSet($request, $id);

        $request->validate([
            'subject_identifier' => 'required|string|max:191',
        ]);

        $keputusan = $this->evaluator->evaluate($set, (string) $request->input('subject_identifier'));

        return response()->json(['data' => $keputusan->toArray()]);
    }

    public function decisions(Request $request, string $id)
    {
        $set = $this->findSet($request, $id);

        $rows = ConsentRuleDecision::where('org_id', $set->org_id)
            ->where('rule_set_id', $set->id)
            ->orderByDesc('decided_at')
            ->limit(100)
            ->get();

        // Nama titik pengumpulan dilampirkan di sini, bukan lewat relasi yang
        // dimuat lazy per baris — jejak ini dibaca sebagai daftar, dan satu
        // kueri jauh lebih baik daripada seratus.
        $nama = ConsentCollectionPoint::where('org_id', $set->org_id)
            ->whereIn('id', $rows->pluck('collection_point_id')->filter()->unique())
            ->pluck('name', 'id');

        $rows->each(fn ($r) => $r->setAttribute(
            'collection_point_name',
            $r->collection_point_id ? ($nama[$r->collection_point_id] ?? null) : null
        ));

        return response()->json(['data' => $rows]);
    }

    /**
     * Menentukan titik pengumpulan mana yang dijaga set ini.
     *
     * Ditulis dengan forceFill, BUKAN lewat $fillable: ModuleCrudController
     * memperbarui collection point dengan `update($request->all())`, sehingga
     * kolom apa pun yang fillable di sana bisa diisi langsung dari payload —
     * termasuk id set milik organisasi lain. Kolom ini sengaja dibiarkan di
     * luar $fillable supaya satu-satunya jalan menetapkannya adalah endpoint
     * ini, yang memeriksa kepemilikannya.
     */
    public function bindPoints(Request $request, string $id)
    {
        $set = $this->findSet($request, $id);

        $request->validate([
            'collection_point_ids' => 'present|array|max:200',
            'collection_point_ids.*' => 'uuid',
        ]);

        /** @var array<int,string> $diminta */
        $diminta = $request->input('collection_point_ids', []);

        $sah = ConsentCollectionPoint::where('org_id', $set->org_id)
            ->whereIn('id', $diminta)
            ->pluck('id')
            ->all();

        if (count($sah) !== count(array_unique($diminta))) {
            throw ValidationException::withMessages([
                'collection_point_ids' => 'Ada titik pengumpulan yang tidak ditemukan.',
            ]);
        }

        DB::transaction(function () use ($set, $sah) {
            // Lepaskan titik yang tadinya dijaga set ini tapi tidak lagi diminta.
            ConsentCollectionPoint::where('org_id', $set->org_id)
                ->where('consent_rule_set_id', $set->id)
                ->whereNotIn('id', $sah ?: ['-'])
                ->get()
                ->each(fn ($cp) => $cp->forceFill(['consent_rule_set_id' => null])->save());

            ConsentCollectionPoint::where('org_id', $set->org_id)
                ->whereIn('id', $sah ?: ['-'])
                ->get()
                ->each(fn ($cp) => $cp->forceFill(['consent_rule_set_id' => $set->id])->save());
        });

        return response()->json([
            'message' => 'Penjagaan titik pengumpulan diperbarui.',
            'data' => ['collection_point_ids' => $sah],
        ]);
    }

    /**
     * Pilihan untuk penyusun: titik pengumpulan milik tenant beserta itemnya.
     *
     * Dikirim sebagai satu pohon supaya penyusun tidak perlu memanggil ulang
     * tiap kali baris kondisi bertambah — dan supaya item yang ditawarkan
     * DIJAMIN milik titik yang dipilih.
     */
    public function options(Request $request)
    {
        $points = ConsentCollectionPoint::where('org_id', $request->user()->org_id)
            ->orderBy('name')
            ->get(['id', 'collection_id', 'name', 'kind', 'webhook_url', 'consent_rule_set_id']);

        $items = ConsentItem::whereIn('collection_point_id', $points->pluck('id'))
            ->where('is_active', true)
            ->orderBy('title')
            ->get(['id', 'collection_point_id', 'title', 'category']);

        $byPoint = $items->groupBy('collection_point_id');

        return response()->json([
            'data' => $points->map(fn ($p) => [
                'id' => $p->id,
                'collection_id' => $p->collection_id,
                'name' => $p->name,
                'kind' => $p->kind,
                // Penyusun perlu tahu titik mana yang sebenarnya PUNYA tujuan
                // kirim: menjaga titik tanpa webhook tidak menjaga apa pun.
                'has_webhook' => ! empty($p->webhook_url),
                'guarded_by' => $p->consent_rule_set_id,
                'items' => $byPoint->get($p->id, collect())->values(),
            ])->values(),
        ]);
    }

    // ------------------------------------------------------------------ privat

    private function findSet(Request $request, string $id): ConsentRuleSet
    {
        // org_id dicocokkan EKSPLISIT: ConsentRuleSet memang memakai
        // BelongsToOrg, tapi menggantungkan kendali akses pada scope global
        // berarti kendali itu ikut mati di konteks mana pun yang lupa
        // menetapkan CurrentOrgContext.
        $set = ConsentRuleSet::where('org_id', $request->user()->org_id)->find($id);

        if (! $set) {
            abort(404, 'Set aturan tidak ditemukan.');
        }

        return $set;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rules
     */
    private function assertSegmentPresent(array $rules): void
    {
        foreach ($rules as $i => $r) {
            if ($r['action'] === ConsentRule::ACTION_BLOCK) {
                continue;
            }
            if (trim((string) ($r['segment'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    "rules.{$i}.segment" => 'Tindakan segmen membutuhkan nama segmen.',
                ]);
            }
        }
    }

    /**
     * Titik pengumpulan harus milik tenant ini, DAN item harus milik titik yang
     * disebut di baris yang sama.
     *
     * Syarat kedua bukan kerewelan: pasangan (titik, item) yang tidak sepadan
     * tidak akan pernah cocok dengan log mana pun, sehingga aturannya diam-diam
     * mati. Ditolak di sini, ia menjadi pesan galat; dibiarkan lolos, ia
     * menjadi aturan yang tampak aktif di layar dan tidak pernah berjalan.
     *
     * @param  array<int,array<string,mixed>>  $rules
     */
    private function assertPairsBelongToOrg(array $rules, string $orgId): void
    {
        $pointIds = [];
        $itemIds = [];
        foreach ($rules as $r) {
            foreach ($r['conditions'] as $c) {
                $pointIds[] = $c['collection_point_id'];
                $itemIds[] = $c['consent_item_id'];
            }
        }

        if ($pointIds === []) {
            return;
        }

        $milikTenant = ConsentCollectionPoint::where('org_id', $orgId)
            ->whereIn('id', array_unique($pointIds))
            ->pluck('id')
            ->all();

        // Item dicari hanya di dalam titik milik tenant, sehingga item milik
        // organisasi lain tidak akan pernah ikut terpetakan.
        $indukItem = ConsentItem::whereIn('id', array_unique($itemIds))
            ->whereIn('collection_point_id', $milikTenant)
            ->pluck('collection_point_id', 'id')
            ->all();

        foreach ($rules as $i => $r) {
            foreach ($r['conditions'] as $j => $c) {
                if (! in_array($c['collection_point_id'], $milikTenant, true)) {
                    throw ValidationException::withMessages([
                        "rules.{$i}.conditions.{$j}.collection_point_id" => 'Titik pengumpulan tidak ditemukan.',
                    ]);
                }
                if (($indukItem[$c['consent_item_id']] ?? null) !== $c['collection_point_id']) {
                    throw ValidationException::withMessages([
                        "rules.{$i}.conditions.{$j}.consent_item_id" => 'Consent item bukan milik titik pengumpulan yang dipilih.',
                    ]);
                }
            }
        }
    }
}
