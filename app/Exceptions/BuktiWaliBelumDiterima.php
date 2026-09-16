<?php

namespace App\Exceptions;

use App\Models\DsrRequest;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Permohonan DSR oleh WALI atas hak yang MERUSAK (hapus, tarik consent,
 * batasi, keberatan, koreksi) tidak boleh dieksekusi sebelum bukti kewenangan
 * walinya diterima — PP 33/2026 Pasal 38 ayat (5)–(7), Pasal 39 ayat (5).
 *
 * Dilempar dari hook `saving` model DsrRequest, bukan dari satu controller:
 * permohonan DSR berubah status lewat banyak pintu (universal CRUD, agen AI,
 * kunci API mitra, kanal surel), dan gerbang yang hidup di satu controller
 * akan dilewati pintu yang lain tanpa tanda apa pun.
 *
 * Laravel memanggil render() otomatis → 422 JSON dengan kode stabil.
 */
class BuktiWaliBelumDiterima extends RuntimeException
{
    public const KODE = 'BUKTI_WALI_BELUM_DITERIMA';

    public function __construct(public readonly DsrRequest $dsr)
    {
        parent::__construct(
            'Permohonan ini diajukan oleh wali atas hak yang berdampak merusak; bukti kewenangan wali belum diterima DPO. Putuskan bukti kewenangannya lebih dulu.',
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => $this->getMessage(),
            'code' => self::KODE,
            'request_id' => $this->dsr->request_id,
            'guardian_proof_status' => $this->dsr->guardian_proof_status,
        ], 422);
    }
}
