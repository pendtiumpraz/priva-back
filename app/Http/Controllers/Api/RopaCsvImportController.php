<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Ropa;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Impor RoPA dari berkas CSV.
 *
 * Melengkapi DocumentImportController yang berbasis dokumen dan bantuan AI;
 * jalur ini sengaja deterministik — tanpa AI, tanpa tebakan — karena impor
 * massal dari sistem lama harus dapat diulang dan dijelaskan hasilnya baris
 * per baris.
 *
 * Dua tahap, dan pemisahannya disengaja: `preview` melaporkan apa yang akan
 * terjadi tanpa menulis apa pun, `commit` baru menuliskannya. Impor RoPA
 * ratusan baris yang gagal di tengah tanpa pratinjau akan meninggalkan keadaan
 * separuh jadi yang lebih sulit dibereskan daripada mengulang dari awal.
 */
class RopaCsvImportController extends Controller
{
    /**
     * Kolom yang dikenali beserta padanannya.
     *
     * Kunci = nama kolom internal. Nilai = ragam judul kolom yang diterima,
     * dalam huruf kecil. Ragamnya sengaja luas karena berkas ekspor sistem
     * lama jarang memakai istilah yang sama, dan memaksa satu bentuk berarti
     * setiap klien harus menyunting berkasnya lebih dulu.
     */
    private const COLUMN_ALIASES = [
        'processing_activity' => ['processing_activity', 'kegiatan pemrosesan', 'nama kegiatan', 'aktivitas', 'activity'],
        'description' => ['description', 'deskripsi', 'keterangan'],
        'purpose' => ['purpose', 'tujuan', 'tujuan pemrosesan'],
        'legal_basis' => ['legal_basis', 'dasar hukum', 'basis hukum'],
        'entity' => ['entity', 'entitas', 'perusahaan'],
        'division' => ['division', 'divisi', 'departemen'],
        'work_unit' => ['work_unit', 'unit kerja', 'unit'],
        'risk_level' => ['risk_level', 'tingkat risiko', 'risiko'],
        'retention_period' => ['retention_period', 'masa retensi', 'retensi'],
        'data_categories' => ['data_categories', 'kategori data', 'jenis data'],
        'data_subjects' => ['data_subjects', 'subjek data', 'kategori subjek'],
        'recipients' => ['recipients', 'penerima', 'penerima data'],
        'security_measures' => ['security_measures', 'langkah keamanan', 'pengamanan'],
    ];

    /** Kolom yang isinya daftar, dipisah koma atau titik koma. */
    private const LIST_COLUMNS = ['data_categories', 'data_subjects', 'recipients'];

    private const RISK_LEVELS = ['low', 'medium', 'high'];

    private const MAX_ROWS = 2000;

    public function preview(Request $request): JsonResponse
    {
        $parsed = $this->parse($request);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }

        return response()->json(['data' => $parsed]);
    }

    public function commit(Request $request): JsonResponse
    {
        $parsed = $this->parse($request);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }

        if (empty($parsed['valid_rows'])) {
            return response()->json([
                'message' => 'Tidak ada baris yang sah untuk diimpor.',
                'data' => $parsed,
            ], 422);
        }

        $orgId = $request->user()->org_id;
        $userId = $request->user()->id;
        $created = [];

        // Seluruh impor dalam satu transaksi: impor separuh jadi lebih sulit
        // dibereskan daripada impor yang gagal seluruhnya dan diulang.
        DB::transaction(function () use ($parsed, $orgId, $userId, &$created) {
            foreach ($parsed['valid_rows'] as $row) {
                $ropa = $this->createRopa($row['data'], $orgId, $userId);
                $created[] = [
                    'line' => $row['line'],
                    'id' => $ropa->id,
                    'registration_number' => $ropa->registration_number,
                    'processing_activity' => $ropa->processing_activity,
                ];
            }
        });

        AuditLog::log('ropa', $orgId, 'csv_imported', [
            'imported' => count($created),
            'skipped' => count($parsed['errors']),
        ], 'import');

        return response()->json([
            'message' => count($created).' RoPA berhasil diimpor.',
            'imported' => count($created),
            'skipped' => count($parsed['errors']),
            'created' => $created,
            'errors' => $parsed['errors'],
        ], 201);
    }

    /** Contoh berkas CSV berisi judul kolom yang dikenali. */
    public function template()
    {
        $headers = array_keys(self::COLUMN_ALIASES);
        $sample = [
            'Pembukaan Rekening Digital', 'Onboarding nasabah via aplikasi',
            'Pemenuhan kontrak layanan perbankan', 'Pelaksanaan kontrak',
            'PT Bank Contoh', 'Retail Banking', 'Unit Onboarding', 'high',
            '5 tahun', 'Identitas; Kontak; Keuangan', 'Nasabah Perorangan',
            'Biro Kredit; Vendor KYC', 'Enkripsi; Pembatasan akses',
        ];

        return response()->streamDownload(function () use ($headers, $sample) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            fputcsv($out, $sample);
            fclose($out);
        }, 'template_import_ropa.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Baca dan validasi berkas, tanpa menulis apa pun.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function parse(Request $request): array|JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');
        if (! $handle) {
            return response()->json(['message' => 'Berkas tidak dapat dibaca.'], 422);
        }

        // BOM dari Excel akan menempel pada judul kolom pertama dan membuatnya
        // tidak pernah cocok dengan padanan mana pun — dibuang lebih dulu.
        $first = fgets($handle);
        if ($first !== false && str_starts_with($first, "\xEF\xBB\xBF")) {
            $first = substr($first, 3);
        }
        rewind($handle);
        if ($first !== false) {
            fseek($handle, str_starts_with((string) fgets($handle), "\xEF\xBB\xBF") ? 3 : 0);
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return response()->json(['message' => 'Berkas kosong atau tidak memiliki baris judul.'], 422);
        }

        $map = $this->mapHeader($header);
        if (! isset($map['processing_activity'])) {
            fclose($handle);

            return response()->json([
                'message' => 'Kolom kegiatan pemrosesan tidak ditemukan. Unduh templat untuk melihat judul kolom yang dikenali.',
                'detected_columns' => array_values(array_filter($header)),
                'recognized' => array_keys($map),
            ], 422);
        }

        $validRows = [];
        $errors = [];
        $line = 1;
        $seenActivities = [];

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            if (count($validRows) + count($errors) >= self::MAX_ROWS) {
                $errors[] = ['line' => $line, 'error' => 'Melebihi batas '.self::MAX_ROWS.' baris per impor.'];
                break;
            }

            [$data, $rowErrors] = $this->buildRow($row, $map);

            $activityKey = mb_strtolower(trim((string) ($data['processing_activity'] ?? '')));
            if ($activityKey !== '' && isset($seenActivities[$activityKey])) {
                // Duplikat DI DALAM berkas dilaporkan sebagai galat, bukan
                // dilewati diam-diam: hampir selalu tanda berkasnya salah
                // disusun, dan menelannya akan menyembunyikan kesalahan itu.
                $rowErrors[] = 'Kegiatan pemrosesan ganda di baris '.$seenActivities[$activityKey].' pada berkas yang sama.';
            }

            if ($rowErrors) {
                $errors[] = ['line' => $line, 'error' => implode(' ', $rowErrors), 'raw' => array_slice($row, 0, 3)];

                continue;
            }

            $seenActivities[$activityKey] = $line;
            $validRows[] = ['line' => $line, 'data' => $data];
        }
        fclose($handle);

        return [
            'recognized_columns' => array_keys($map),
            'ignored_columns' => array_values(array_diff(
                array_map(fn ($h) => trim((string) $h), array_filter($header)),
                array_values(array_map(fn ($i) => trim((string) $header[$i]), $map))
            )),
            'total_rows' => count($validRows) + count($errors),
            'valid' => count($validRows),
            'invalid' => count($errors),
            'valid_rows' => $validRows,
            'preview' => array_slice(array_column($validRows, 'data'), 0, 10),
            'errors' => $errors,
        ];
    }

    /** @return array<string, int> nama kolom internal → indeks kolom di berkas */
    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $raw) {
            $name = mb_strtolower(trim((string) $raw));
            if ($name === '') {
                continue;
            }
            foreach (self::COLUMN_ALIASES as $field => $aliases) {
                if (isset($map[$field])) {
                    continue;
                }
                if (in_array($name, $aliases, true)) {
                    $map[$field] = $idx;
                    break;
                }
            }
        }

        return $map;
    }

    /** @return array{0: array<string, mixed>, 1: array<int, string>} */
    private function buildRow(array $row, array $map): array
    {
        $data = [];
        $errors = [];

        foreach ($map as $field => $idx) {
            $value = trim((string) ($row[$idx] ?? ''));
            if ($value === '') {
                continue;
            }

            if (in_array($field, self::LIST_COLUMNS, true)) {
                $parts = preg_split('/[;,]+/', $value) ?: [];
                $data[$field] = array_values(array_filter(array_map('trim', $parts), fn ($v) => $v !== ''));

                continue;
            }

            if ($field === 'risk_level') {
                $normalized = mb_strtolower($value);
                $normalized = match ($normalized) {
                    'rendah' => 'low',
                    'sedang', 'menengah' => 'medium',
                    'tinggi' => 'high',
                    default => $normalized,
                };
                if (! in_array($normalized, self::RISK_LEVELS, true)) {
                    $errors[] = "Tingkat risiko \"{$value}\" tidak dikenali (gunakan low/medium/high atau rendah/sedang/tinggi).";

                    continue;
                }
                $data[$field] = $normalized;

                continue;
            }

            $data[$field] = $value;
        }

        if (empty($data['processing_activity'])) {
            $errors[] = 'Kegiatan pemrosesan wajib diisi.';
        } elseif (mb_strlen($data['processing_activity']) > 255) {
            $errors[] = 'Kegiatan pemrosesan melebihi 255 karakter.';
        }

        return [$data, $errors];
    }

    /**
     * Simpan satu RoPA dengan pengulangan saat kode bentrok.
     *
     * Penomoran RoPA memakai penghitung per-org di atas batasan unik GLOBAL,
     * jadi impor massal adalah justru keadaan yang paling mungkin memicu
     * bentrokan — memanggil create() langsung akan menghidupkan kembali temuan
     * dataroom F-03.
     */
    private function createRopa(array $data, string $orgId, string $userId): Ropa
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return Ropa::create(array_merge($data, [
                    'org_id' => $orgId,
                    'created_by' => $userId,
                    'registration_number' => $this->nextCode(),
                    'status' => $data['status'] ?? 'draft',
                ]));
            } catch (QueryException $e) {
                $msg = strtolower($e->getMessage());
                $duplicate = str_contains($msg, 'unique') || str_contains($msg, 'duplicate');
                if (! $duplicate || $attempt === 2) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Gagal membuat nomor RoPA setelah 3 percobaan.');
    }

    private function nextCode(): string
    {
        $year = date('Y');
        $prefix = 'ROPA-'.$year.'-';
        $max = 0;
        foreach (Ropa::withTrashed()->where('registration_number', 'like', $prefix.'%')->pluck('registration_number') as $code) {
            $num = (int) substr((string) $code, strrpos((string) $code, '-') + 1);
            $max = max($max, $num);
        }

        return $prefix.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}
