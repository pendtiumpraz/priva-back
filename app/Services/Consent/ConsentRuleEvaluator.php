<?php

namespace App\Services\Consent;

use App\Models\ConsentRule;
use App\Models\ConsentRuleDecision;
use App\Models\ConsentRuleSet;
use Illuminate\Database\Eloquent\Collection;

/**
 * Menjalankan satu set aturan atas seorang subjek.
 *
 * SEMANTIKA YANG DIPILIH
 * ----------------------
 * 1. Kondisi dalam satu aturan di-AND. Aturan tanpa kondisi tidak pernah cocok
 *    — penampung terakhir adalah `default_action`, bukan aturan kosong.
 *
 * 2. Aturan dievaluasi berurutan, dan untuk SATU SEGMEN yang pertama menang.
 *    Aturan berikutnya yang menyebut segmen sama tetap dicatat sebagai cocok,
 *    dengan `applied: false`, supaya tenant melihat aturannya tertutup — bukan
 *    diam-diam hilang.
 *
 * 3. `block` bersifat terminal dan menghentikan evaluasi.
 *
 * 4. Yang paling halus: PENGECUALIAN TIDAK MENGIZINKAN.
 *    Hanya `block` dan `include_segment` yang menjawab pertanyaan "boleh
 *    dikirim?". `exclude_segment` hanya mempersempit. Tanpa pemisahan ini,
 *    satu set yang isinya semata larangan akan berubah menjadi izin begitu
 *    salah satu larangannya menyala — subjek yang cocok dengan sebuah
 *    LARANGAN justru jadi lebih mudah dikirim daripada yang tidak cocok
 *    apa-apa. Pembalikan itu tidak akan pernah bisa dijelaskan kepada auditor.
 *
 *    Akibatnya, set berisi larangan saja dengan `default_action = block` akan
 *    menahan semuanya. Itu memang jawaban yang benar: tenant belum pernah
 *    menyatakan kapan pengiriman DIPERBOLEHKAN.
 */
class ConsentRuleEvaluator
{
    public function __construct(private ConsentStateResolver $resolver) {}

    public function evaluate(ConsentRuleSet $set, string $subject): ConsentDecision
    {
        return $this->evaluateMany($set, [$subject])[strtolower(trim($subject))];
    }

    /**
     * Versi berkelompok — satu pemuatan aturan dan satu kueri keadaan untuk
     * banyak subjek. Dipakai jalur ekstrak massal, yang menimbang ribuan orang
     * dalam satu jalan.
     *
     * evaluate() DIALIHKAN ke sini, dan keduanya memakai pejalan aturan yang
     * sama (jalankan()). Semantik "yang pertama menang", sifat terminal `block`,
     * dan aturan bahwa pengecualian tidak mengizinkan hanya boleh ditulis di
     * satu tempat; disalin, ia akan menyimpang dan jalur satuan akan menjawab
     * berbeda dari jalur massal untuk orang yang sama.
     *
     * @param  list<string>  $subjects
     * @return array<string,ConsentDecision> penanda subjek (huruf kecil) → keputusan
     */
    public function evaluateMany(ConsentRuleSet $set, array $subjects): array
    {
        /** @var Collection<int,ConsentRule> $rules */
        $rules = $set->rules()->where('is_active', true)->with('conditions')->get();

        // Semua pasangan yang dibutuhkan seluruh kondisi dikumpulkan dulu,
        // lalu diturunkan dalam SATU kueri. Menurunkan keadaan per aturan akan
        // menghasilkan kueri sebanyak aturan, dan — lebih buruk — membuka
        // peluang dua aturan membaca keadaan yang berbeda dalam satu evaluasi.
        $wanted = [];
        foreach ($rules as $rule) {
            foreach ($rule->conditions as $c) {
                $wanted[$c->collection_point_id.'|'.$c->consent_item_id] =
                    [(string) $c->collection_point_id, (string) $c->consent_item_id];
            }
        }

        $kosong = [];
        foreach ($wanted as $kunci => $_) {
            $kosong[$kunci] = ConsentStateResolver::NEVER;
        }

        $perSubjek = $this->resolver->resolveMany((string) $set->org_id, $subjects, array_values($wanted));

        $hasil = [];
        foreach ($subjects as $s) {
            $n = strtolower(trim($s));
            // Penanda kosong tidak punya keadaan apa pun — ia tetap dijalankan
            // melalui aturan (semuanya "belum pernah"), bukan dilewati, supaya
            // tindakan bawaan tetap berlaku baginya.
            $hasil[$n] = $this->jalankan($set, $rules, $perSubjek[$n] ?? $kosong);
        }

        return $hasil;
    }

    /**
     * Pejalan aturan yang murni: tidak menyentuh basis data sama sekali.
     *
     * @param  Collection<int,ConsentRule>  $rules
     * @param  array<string,string>  $states
     */
    private function jalankan(ConsentRuleSet $set, Collection $rules, array $states): ConsentDecision
    {
        $matched = [];
        $decidedSegment = [];
        $allowed = [];
        $excluded = [];
        $authorized = false;

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $states)) {
                continue;
            }

            if ($rule->action === ConsentRule::ACTION_BLOCK) {
                $matched[] = $this->trace($rule, true);

                return new ConsentDecision(
                    blocked: true,
                    allowed: [],
                    excluded: $excluded,
                    matched: $matched,
                    states: $states,
                    reason: 'Ditahan oleh aturan "'.$rule->name.'".',
                );
            }

            $segment = is_string($rule->segment) ? trim($rule->segment) : '';
            if ($segment === '') {
                // Tindakan segmen tanpa segmen tidak bisa dijalankan. Tetap
                // dicatat sebagai cocok-tapi-tak-berlaku, karena menyembunyikan
                // aturan salah susun membuatnya tak pernah diperbaiki.
                $matched[] = $this->trace($rule, false);

                continue;
            }

            $first = ! isset($decidedSegment[$segment]);
            $matched[] = $this->trace($rule, $first);

            if (! $first) {
                continue;
            }

            $decidedSegment[$segment] = $rule->action;

            if ($rule->action === ConsentRule::ACTION_INCLUDE) {
                $allowed[] = $segment;
                $authorized = true;
            } else {
                $excluded[] = $segment;
            }
        }

        $blocked = $authorized
            ? false
            : $set->default_action === ConsentRuleSet::ACTION_BLOCK;

        return new ConsentDecision(
            blocked: $blocked,
            allowed: $allowed,
            excluded: $excluded,
            matched: $matched,
            states: $states,
            reason: $this->reason($authorized, $blocked, $matched, $allowed, $excluded),
        );
    }

    /**
     * Evaluasi + tulis jejaknya. Evaluasi sendiri sengaja tetap murni supaya
     * bisa dipanggil untuk pratinjau di UI tanpa mengotori jejak keputusan.
     */
    public function evaluateAndRecord(ConsentRuleSet $set, string $subject): ConsentDecision
    {
        $decision = $this->evaluate($set, $subject);

        ConsentRuleDecision::create([
            'org_id' => $set->org_id,
            'rule_set_id' => $set->id,
            'subject_identifier' => $subject,
            'blocked' => $decision->blocked,
            'segments' => ['allowed' => $decision->allowed, 'excluded' => $decision->excluded],
            'matched' => $decision->matched,
            'states' => $decision->states,
            'decided_at' => now(),
        ]);

        return $decision;
    }

    /**
     * @param  array<string,string>  $states
     */
    private function matches(ConsentRule $rule, array $states): bool
    {
        $conditions = $rule->conditions;
        if ($conditions->isEmpty()) {
            return false;
        }

        foreach ($conditions as $c) {
            $actual = $states[$c->collection_point_id.'|'.$c->consent_item_id] ?? ConsentStateResolver::NEVER;
            if ($actual !== $c->state) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{rule_id:string,name:string,action:string,segment:string|null,applied:bool}
     */
    private function trace(ConsentRule $rule, bool $applied): array
    {
        return [
            'rule_id' => (string) $rule->id,
            'name' => (string) $rule->name,
            'action' => (string) $rule->action,
            'segment' => $rule->segment !== null ? (string) $rule->segment : null,
            'applied' => $applied,
        ];
    }

    /**
     * @param  list<array{rule_id:string,name:string,action:string,segment:string|null,applied:bool}>  $matched
     * @param  list<string>  $allowed
     * @param  list<string>  $excluded
     */
    private function reason(bool $authorized, bool $blocked, array $matched, array $allowed, array $excluded): string
    {
        if ($matched === []) {
            return $blocked
                ? 'Tidak ada aturan yang cocok; tindakan bawaan menahan pengiriman.'
                : 'Tidak ada aturan yang cocok; tindakan bawaan mengizinkan pengiriman.';
        }

        if ($blocked) {
            return 'Ada aturan yang cocok, tetapi tidak satu pun mengizinkan pengiriman; tindakan bawaan menahan.';
        }

        $bagian = [];
        if ($authorized) {
            $bagian[] = 'diizinkan untuk segmen '.implode(', ', $allowed);
        } else {
            $bagian[] = 'diizinkan oleh tindakan bawaan';
        }
        if ($excluded !== []) {
            $bagian[] = 'dikecualikan dari '.implode(', ', $excluded);
        }

        return ucfirst(implode('; ', $bagian)).'.';
    }
}
