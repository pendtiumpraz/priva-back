<?php

namespace App\Services\Consent;

/**
 * Hasil satu evaluasi, beserta alasannya.
 *
 * `allowed` dan `excluded` adalah DUA daftar, bukan satu daftar dan
 * kebalikannya. Sebuah segmen bisa berstatus tak tersebut sama sekali — dan
 * bagi pemanggil itu berbeda dari "dilarang". Pemanggil WAJIB menghormati
 * `excluded` apa pun isi `allowed`.
 */
class ConsentDecision
{
    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $excluded
     * @param  list<array{rule_id:string,name:string,action:string,segment:string|null,applied:bool}>  $matched
     * @param  array<string,string>  $states
     */
    public function __construct(
        public readonly bool $blocked,
        public readonly array $allowed,
        public readonly array $excluded,
        public readonly array $matched,
        public readonly array $states,
        public readonly string $reason,
    ) {}

    public function allowsSegment(string $segment): bool
    {
        if ($this->blocked || in_array($segment, $this->excluded, true)) {
            return false;
        }

        return $this->allowed === [] || in_array($segment, $this->allowed, true);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'blocked' => $this->blocked,
            'allowed' => $this->allowed,
            'excluded' => $this->excluded,
            'matched' => $this->matched,
            'states' => $this->states,
            'reason' => $this->reason,
        ];
    }
}
