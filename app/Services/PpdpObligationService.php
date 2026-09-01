<?php

namespace App\Services;

use App\Models\PpdpAppointment;

/**
 * Evaluasi kewajiban menunjuk PPDP (PP 33/2026 Pasal 142) untuk sebuah org.
 *
 * Sumber sinyal pemicu adalah self-assessment yang tercatat pada record
 * penunjukan (atau, bila belum ada penunjukan, pada draft asesmen yang
 * dikirim frontend). Service ini tidak menebak dari data ROPA — keputusan
 * "wajib/tidak" dikembalikan berdasarkan pemicu yang eksplisit dijawab, agar
 * dapat dipertanggungjawabkan di hadapan pemeriksa.
 */
class PpdpObligationService
{
    /**
     * Ringkas status kewajiban + kepatuhan penunjukan untuk satu org.
     *
     * @return array<string, mixed>
     */
    public function evaluate(string $orgId): array
    {
        $active = PpdpAppointment::query()
            ->where('org_id', $orgId)
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->first();

        // Basis asesmen pemicu: penunjukan aktif, jika tidak ada pakai record
        // terakhir (mis. status inactive) supaya jawaban pemicu tidak hilang.
        $basis = $active ?? PpdpAppointment::query()
            ->where('org_id', $orgId)
            ->orderByDesc('created_at')
            ->first();

        $triggers = [];
        foreach (PpdpAppointment::TRIGGERS as $key => $label) {
            $triggers[] = [
                'key' => $key,
                'label' => $label,
                'active' => (bool) ($basis?->{$key} ?? false),
            ];
        }

        $mandatory = (bool) ($basis?->is_mandatory ?? false);
        $hasActive = (bool) $active;

        return [
            'has_active_ppdp' => $hasActive,
            'mandatory' => $mandatory,
            'triggers' => $triggers,
            'reasons' => $basis?->mandatory_reasons ?? [],
            // Gap kepatuhan: wajib menunjuk tapi belum ada penunjukan aktif.
            'gap' => $mandatory && ! $hasActive,
            'competency_complete' => (bool) ($active?->competency_complete ?? false),
            'active_appointment_id' => $active?->id,
        ];
    }
}
