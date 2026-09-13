<?php

namespace App\Services\Consent;

/**
 * Hasil penimbangan satu jalan ekstrak.
 *
 * Dihitung SEKALI di muka, lalu dipakai sebagai saringan per baris. Perhitungan
 * di muka itu disengaja: pada jalur CSV, galat evaluasi di tengah aliran unduhan
 * tidak bisa dibatalkan lagi — berkasnya sudah separuh terkirim ke peramban.
 */
class ConsentBulkVerdict
{
    /**
     * @param  array<string,string>  $setPerTitik  collectionPointId → ruleSetId
     * @param  array<string,array<string,ConsentDecision>>  $keputusan  ruleSetId → subjek → keputusan
     * @param  array<string,string>  $namaSet  ruleSetId → nama
     */
    public function __construct(
        private array $setPerTitik,
        private array $keputusan,
        private array $namaSet,
        private ?string $segment,
    ) {}

    public static function tanpaPenjagaan(): self
    {
        return new self([], [], [], null);
    }

    public function adaPenjagaan(): bool
    {
        return $this->setPerTitik !== [];
    }

    /**
     * Boleh dikirimkah baris dari titik ini, untuk subjek ini?
     *
     * Titik yang tidak dijaga selalu boleh — sama seperti sebelum fitur ini ada.
     */
    public function izinkan(?string $pointId, ?string $subject): bool
    {
        $setId = $pointId !== null ? ($this->setPerTitik[$pointId] ?? null) : null;
        if ($setId === null) {
            return true;
        }

        $keputusan = $this->keputusan[$setId][strtolower(trim((string) $subject))] ?? null;

        // Subjek yang tidak ada keputusannya berarti ia tidak ikut tertimbang —
        // dan sesuatu yang tidak tertimbang tidak boleh lolos dari gerbang.
        if ($keputusan === null) {
            return false;
        }

        return $this->segment !== null && $this->segment !== ''
            ? $keputusan->allowsSegment($this->segment)
            : ! $keputusan->blocked;
    }

    /**
     * Subjek yang ditahan, beserta keputusannya — bahan jejak dan ringkasan.
     *
     * @return list<array{rule_set_id:string,subject:string,decision:ConsentDecision}>
     */
    public function yangDitahan(): array
    {
        $out = [];
        foreach ($this->keputusan as $setId => $perSubjek) {
            foreach ($perSubjek as $subjek => $keputusan) {
                $lolos = $this->segment !== null && $this->segment !== ''
                    ? $keputusan->allowsSegment($this->segment)
                    : ! $keputusan->blocked;
                if (! $lolos) {
                    $out[] = ['rule_set_id' => (string) $setId, 'subject' => (string) $subjek, 'decision' => $keputusan];
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public function ringkasan(): array
    {
        $ditahan = $this->yangDitahan();

        return [
            'segment' => $this->segment,
            'rule_sets' => array_map(
                fn ($id) => ['id' => $id, 'name' => $this->namaSet[$id] ?? null],
                array_values(array_unique(array_values($this->setPerTitik)))
            ),
            'guarded_points' => count($this->setPerTitik),
            'subjects_weighed' => array_sum(array_map('count', $this->keputusan)),
            'subjects_withheld' => count($ditahan),
        ];
    }
}
