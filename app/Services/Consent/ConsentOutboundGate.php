<?php

namespace App\Services\Consent;

use App\Models\ConsentCollectionPoint;
use App\Models\ConsentRuleDecision;
use App\Models\ConsentRuleSet;
use Illuminate\Support\Facades\Log;

/**
 * Gerbang tunggal antara penangkapan consent dan segala pengiriman ke luar.
 *
 * SATU KEPUTUSAN UNTUK SEMUA TUJUAN
 * ---------------------------------
 * Di jalur penangkapan, webhook dan dorongan CRM dikirim berdampingan. Menjaga
 * salah satunya saja lebih buruk daripada tidak menjaga sama sekali: layar akan
 * menyatakan "Ditahan" sementara datanya tetap mengalir ke CRM. Karena itu
 * gerbang ini memutuskan SEKALI, dan pemanggilnya wajib menghormati keputusan
 * itu untuk seluruh tujuan.
 *
 * TIDAK DIJAGA = PERSIS SEPERTI SEBELUMNYA
 * ----------------------------------------
 * Titik pengumpulan tanpa set aturan (atau dengan set yang dinonaktifkan)
 * mengembalikan null, dan pemanggil mengirim seperti biasa. Tenant yang belum
 * pernah menyusun aturan tidak boleh tiba-tiba berhenti menerima webhook hanya
 * karena fitur ini dipasang.
 *
 * GAGAL = MENAHAN
 * ---------------
 * Bila evaluasi melempar galat, gerbang menahan pengiriman dan tetap mencatat
 * jejaknya. Arah ini dipilih sadar: pelanggaran tidak bisa ditarik kembali,
 * sedangkan webhook yang tertahan masih bisa dikirim ulang — dan tertahannya
 * segera terasa, sehingga bugnya muncul ke permukaan alih-alih mengendap.
 */
class ConsentOutboundGate
{
    public function __construct(private ConsentRuleEvaluator $evaluator) {}

    /**
     * @return ConsentDecision|null null = titik ini tidak dijaga; kirim seperti biasa.
     */
    public function decide(ConsentCollectionPoint $cp, string $subject, string $context): ?ConsentDecision
    {
        if (empty($cp->consent_rule_set_id)) {
            return null;
        }

        $set = ConsentRuleSet::withoutGlobalScope('org')
            ->where('org_id', $cp->org_id)
            ->where('is_active', true)
            ->find($cp->consent_rule_set_id);

        // Set yang dinonaktifkan sengaja diperlakukan sama dengan tak terpasang:
        // itu jalan keluar cepat bagi tenant untuk mematikan penjagaan tanpa
        // harus membongkar sambungannya.
        if (! $set) {
            return null;
        }

        try {
            $keputusan = $this->evaluator->evaluate($set, $subject);
        } catch (\Throwable $e) {
            Log::error('Evaluasi aturan consent gagal; pengiriman ditahan.', [
                'collection_point_id' => $cp->id,
                'rule_set_id' => $set->id,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);

            $keputusan = new ConsentDecision(
                blocked: true,
                allowed: [],
                excluded: [],
                matched: [],
                states: [],
                reason: 'Evaluasi aturan gagal; pengiriman ditahan sebagai langkah aman.',
            );
        }

        $this->catat($set, $cp, $subject, $context, $keputusan);

        return $keputusan;
    }

    /**
     * Jejak ditulis di luar blok try evaluasi, dan kegagalannya tidak boleh
     * menjatuhkan penangkapan consent. Catatan persetujuan subjek adalah
     * artefak yang paling penting di sini — ia sudah tersimpan sebelum gerbang
     * ini dipanggil, dan tidak boleh ikut hilang hanya karena jejaknya gagal
     * ditulis.
     */
    private function catat(
        ConsentRuleSet $set,
        ConsentCollectionPoint $cp,
        string $subject,
        string $context,
        ConsentDecision $keputusan,
    ): void {
        try {
            ConsentRuleDecision::create([
                'org_id' => $set->org_id,
                'rule_set_id' => $set->id,
                'collection_point_id' => $cp->id,
                'context' => $context,
                'subject_identifier' => $subject,
                'blocked' => $keputusan->blocked,
                'segments' => ['allowed' => $keputusan->allowed, 'excluded' => $keputusan->excluded],
                'matched' => $keputusan->matched,
                'states' => $keputusan->states,
                'decided_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Gagal menulis jejak keputusan consent.', [
                'collection_point_id' => $cp->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Bagian keputusan yang ikut dikirim ke penerima webhook.
     *
     * Yang dikirim adalah KEPUTUSANNYA, bukan riwayat consent subjek. Penerima
     * perlu tahu segmen mana yang terlarang agar bisa menaatinya; ia tidak
     * perlu — dan tidak berhak — menerima seluruh jejak persetujuan orang itu.
     *
     * @return array<string,mixed>
     */
    public static function payload(ConsentDecision $keputusan): array
    {
        return [
            'allowed_segments' => $keputusan->allowed,
            'excluded_segments' => $keputusan->excluded,
            'reason' => $keputusan->reason,
        ];
    }
}
