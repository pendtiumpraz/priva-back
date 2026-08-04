<?php

namespace App\Services;

use App\Models\Dpia;
use App\Models\Ropa;
use Carbon\Carbon;

/**
 * Daftar sumber data yang boleh dipakai widget dashboard kustom, beserta
 * izin modul yang menjaganya.
 *
 * Sengaja berupa daftar tertutup, bukan kueri bebas yang dikirim klien. Widget
 * yang menerima kueri bebas berarti membiarkan penyusun dashboard membaca apa
 * pun di basis data — termasuk data yang izinnya tidak ia miliki, dan termasuk
 * data tenant lain bila satu saja penyaring org terlupa.
 *
 * Setiap sumber menyatakan `permission`-nya sendiri. Penyaringan dilakukan saat
 * render, bukan saat menyimpan, karena hak akses seseorang dapat berubah
 * setelah dashboard tersimpan — dan yang berlaku haruslah hak saat melihat.
 */
class DashboardWidgetRegistry
{
    /**
     * @return array<string, array{label: string, permission: string, type: string}>
     */
    public static function sources(): array
    {
        return [
            'ropa.total' => ['label' => 'Total RoPA', 'permission' => 'ropa', 'type' => 'stat'],
            'ropa.by_risk' => ['label' => 'RoPA menurut tingkat risiko', 'permission' => 'ropa', 'type' => 'breakdown'],
            'ropa.by_status' => ['label' => 'RoPA menurut status', 'permission' => 'ropa', 'type' => 'breakdown'],
            'ropa.by_division' => ['label' => 'RoPA menurut divisi', 'permission' => 'ropa', 'type' => 'breakdown'],
            'ropa.high_risk' => ['label' => 'RoPA berisiko tinggi', 'permission' => 'ropa', 'type' => 'stat'],
            'dpia.total' => ['label' => 'Total DPIA', 'permission' => 'dpia', 'type' => 'stat'],
            'dpia.by_status' => ['label' => 'DPIA menurut status', 'permission' => 'dpia', 'type' => 'breakdown'],
            'rtp.by_status' => ['label' => 'Rencana penanganan risiko menurut status', 'permission' => 'dpia', 'type' => 'breakdown'],
            'rtp.overdue' => ['label' => 'Penanganan risiko melewati tenggat', 'permission' => 'dpia', 'type' => 'stat'],
        ];
    }

    public static function has(string $source): bool
    {
        return array_key_exists($source, self::sources());
    }

    public static function permissionFor(string $source): ?string
    {
        return self::sources()[$source]['permission'] ?? null;
    }

    /**
     * Hitung satu sumber. Seluruh kueri melewati model ber-BelongsToOrg
     * sehingga penyaringan org berlaku otomatis pada konteks HTTP.
     *
     * @return array<string, mixed>
     */
    public static function compute(string $source): array
    {
        return match ($source) {
            'ropa.total' => ['value' => Ropa::count()],
            'ropa.high_risk' => ['value' => Ropa::where('risk_level', 'high')->count()],
            'ropa.by_risk' => ['breakdown' => self::groupCount(Ropa::query(), 'risk_level')],
            'ropa.by_status' => ['breakdown' => self::groupCount(Ropa::query(), 'status')],
            'ropa.by_division' => ['breakdown' => self::groupCount(Ropa::query(), 'division')],
            'dpia.total' => ['value' => Dpia::count()],
            'dpia.by_status' => ['breakdown' => self::groupCount(Dpia::query(), 'status')],
            'rtp.by_status' => ['breakdown' => self::rtpBreakdown('status')],
            'rtp.overdue' => ['value' => self::rtpOverdueCount()],
            default => ['error' => 'Sumber tidak dikenali.'],
        };
    }

    /** @return array<int, array{label: string, count: int}> */
    private static function groupCount($query, string $column): array
    {
        return $query->selectRaw("{$column} as label, COUNT(*) as total")
            ->groupBy($column)
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->label !== null && $row->label !== '' ? (string) $row->label : 'Tidak diisi',
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * Item RTP tersimpan sebagai JSON di dpias.mitigation_tracking, jadi
     * pengelompokannya dilakukan di memori. Kolomnya bukan relasi, sehingga
     * tidak ada cara mengagregasinya lewat SQL secara portabel lintas MySQL,
     * PostgreSQL, dan SQLite.
     *
     * @return array<int, array{label: string, count: int}>
     */
    private static function rtpBreakdown(string $key): array
    {
        $counts = [];
        foreach (Dpia::query()->pluck('mitigation_tracking') as $items) {
            foreach ((array) ($items ?? []) as $item) {
                $label = (string) ($item[$key] ?? 'tidak diketahui');
                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }
        }
        arsort($counts);

        return array_map(
            fn ($label, $count) => ['label' => $label, 'count' => $count],
            array_keys($counts),
            array_values($counts)
        );
    }

    private static function rtpOverdueCount(): int
    {
        $today = now()->startOfDay();
        $count = 0;
        foreach (Dpia::query()->pluck('mitigation_tracking') as $items) {
            foreach ((array) ($items ?? []) as $item) {
                $due = $item['due_date'] ?? null;
                $status = $item['status'] ?? null;
                if (! $due || in_array($status, ['done', 'completed', 'verified'], true)) {
                    continue;
                }
                try {
                    if (Carbon::parse($due)->startOfDay()->lt($today)) {
                        $count++;
                    }
                } catch (\Throwable) {
                    // Tanggal yang tidak dapat dibaca tidak dihitung sebagai
                    // terlambat — menghitungnya akan memunculkan angka
                    // menakutkan yang tidak dapat ditelusuri ke item mana pun.
                }
            }
        }

        return $count;
    }
}
