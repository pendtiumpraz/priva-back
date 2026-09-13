<?php

namespace App\Services\Consent;

use App\Models\ConsentCollectionPoint;
use App\Models\ConsentLog;
use App\Models\ConsentRuleDecision;
use App\Models\ConsentRuleSet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Gerbang untuk jalur ekstrak massal.
 *
 * Bentuknya berbeda dari ConsentOutboundGate: di sana satu penangkapan, satu
 * subjek, satu titik. Di sini ribuan baris yang bisa berasal dari banyak titik
 * pengumpulan sekaligus, masing-masing berpotensi dijaga set aturan yang
 * berbeda. Karena itu penimbangannya dikelompokkan — satu pemuatan aturan dan
 * satu kueri keadaan per set, bukan per orang. Menimbang per orang berarti dua
 * kueri dikalikan jumlah baris, dan jalur ini memang dipakai untuk jumlah
 * besar.
 *
 * SEGMEN DINYATAKAN OLEH PELAKSANA EKSTRAK
 * ----------------------------------------
 * Aturan berbicara dalam segmen ("marketing_konvensional"), sedangkan ekstrak
 * tidak punya gagasan segmen sama sekali. Maka pelaksananya yang menyatakan
 * ekstrak ini untuk segmen apa. Tanpa itu, yang berlaku hanya larangan penuh —
 * dan pengecualian per segmen tidak akan berpengaruh. Ini disebutkan di layar,
 * karena diam-diam mengabaikan separuh aturan adalah cara yang buruk untuk
 * menjaga apa pun.
 */
class ConsentBulkGate
{
    public function __construct(private ConsentRuleEvaluator $evaluator) {}

    /**
     * Menimbang seluruh baris yang tercakup kueri, SEBELUM satu pun dikirim.
     *
     * @param  Builder<ConsentLog>  $query
     */
    public function timbang(string $orgId, Builder $query, ?string $segment): ConsentBulkVerdict
    {
        $titikIds = (clone $query)->distinct()->pluck('collection_id')->filter()->all();
        if ($titikIds === []) {
            return ConsentBulkVerdict::tanpaPenjagaan();
        }

        // Hanya set yang AKTIF yang menjaga — sama seperti di jalur
        // penangkapan, supaya tenant punya satu tuas untuk mematikan penjagaan
        // tanpa membongkar sambungannya.
        $setPerTitik = ConsentCollectionPoint::where('org_id', $orgId)
            ->whereIn('id', $titikIds)
            ->whereNotNull('consent_rule_set_id')
            ->pluck('consent_rule_set_id', 'id')
            ->all();

        if ($setPerTitik === []) {
            return ConsentBulkVerdict::tanpaPenjagaan();
        }

        $sets = ConsentRuleSet::withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->where('is_active', true)
            ->whereIn('id', array_values(array_unique($setPerTitik)))
            ->get();

        $aktif = $sets->pluck('id')->all();
        $setPerTitik = array_filter($setPerTitik, fn ($s) => in_array($s, $aktif, true));

        if ($setPerTitik === []) {
            return ConsentBulkVerdict::tanpaPenjagaan();
        }

        $keputusan = [];
        $namaSet = [];

        foreach ($sets as $set) {
            $namaSet[(string) $set->id] = (string) $set->name;

            $titikSet = array_keys(array_filter($setPerTitik, fn ($s) => $s === $set->id));
            if ($titikSet === []) {
                continue;
            }

            // DISTINCT di sisi basis data: satu orang lazim punya banyak baris
            // log, dan keputusannya sama untuk semuanya.
            $subjek = (clone $query)
                ->whereIn('collection_id', $titikSet)
                ->distinct()
                ->pluck('email')
                ->filter()
                ->map(fn ($e) => strtolower(trim((string) $e)))
                ->unique()
                ->values()
                ->all();

            $keputusan[(string) $set->id] = $subjek === []
                ? []
                : $this->evaluator->evaluateMany($set, $subjek);
        }

        return new ConsentBulkVerdict($setPerTitik, $keputusan, $namaSet, $segment);
    }

    /**
     * Menulis jejak bagi subjek yang DITAHAN saja.
     *
     * Pilihan yang perlu dinyatakan terang: subjek yang lolos tidak dicatat satu
     * per satu. Satu jalan ekstrak bisa meliputi puluhan ribu orang, dan
     * menulis sebaris jejak untuk tiap orang akan mengubah tabel kepatuhan
     * menjadi timbunan yang jauh lebih besar daripada data yang dijaganya.
     * Bukti bagi yang lolos adalah catatan jalannya sendiri — penapis, segmen,
     * set aturan yang berlaku, dan jumlahnya — yang disimpan di
     * `extract_runs.gate_summary`. Yang ditahan dicatat utuh, karena di situlah
     * mesin benar-benar turun tangan dan di situlah buktinya diperlukan.
     */
    public function catatYangDitahan(string $orgId, ConsentBulkVerdict $verdict, ?string $runId): void
    {
        $ditahan = $verdict->yangDitahan();
        if ($ditahan === []) {
            return;
        }

        try {
            foreach (array_chunk($ditahan, 200) as $kelompok) {
                foreach ($kelompok as $baris) {
                    /** @var ConsentDecision $k */
                    $k = $baris['decision'];
                    ConsentRuleDecision::create([
                        'org_id' => $orgId,
                        'rule_set_id' => $baris['rule_set_id'],
                        'collection_point_id' => null,
                        'context' => $runId !== null ? 'extract:'.substr($runId, 0, 8) : 'extract',
                        'subject_identifier' => $baris['subject'],
                        'blocked' => true,
                        'segments' => ['allowed' => $k->allowed, 'excluded' => $k->excluded],
                        'matched' => $k->matched,
                        'states' => $k->states,
                        'decided_at' => now(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Sama seperti di gerbang penangkapan: gagal menulis jejak tidak
            // boleh membatalkan penahanannya. Penahanan sudah terjadi; yang
            // hilang hanya catatannya, dan itu dicatat di log aplikasi.
            Log::error('Gagal menulis jejak keputusan ekstrak.', [
                'org_id' => $orgId,
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
