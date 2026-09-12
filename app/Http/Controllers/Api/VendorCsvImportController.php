<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Impor pihak ketiga dari berkas CSV.
 *
 * Mengikuti pola impor RoPA dan alasannya sama: dua tahap yang sengaja
 * dipisah — `preview` melaporkan apa yang akan terjadi tanpa menulis apa pun,
 * `commit` baru menuliskannya dalam satu transaksi. Impor ratusan baris yang
 * gagal di tengah meninggalkan keadaan separuh jadi yang lebih sulit
 * dibereskan daripada mengulang dari awal.
 *
 * Pembeda dari impor RoPA: baris yang membawa `external_ref` (id rekanan di
 * sistem asal) MEMPERBARUI pihak ketiga yang sudah ada, bukan membuat kembar.
 * Tanpa itu, mengimpor ulang berkas yang sama akan menggandakan seluruh isi
 * registri — tabel `vendors` sebelumnya tidak punya kunci unik sama sekali.
 */
class VendorCsvImportController extends Controller
{
    /**
     * Kolom yang dikenali beserta padanannya (huruf kecil).
     * Ragamnya luas karena berkas ekspor sistem pengadaan jarang seragam.
     */
    private const COLUMN_ALIASES = [
        'name' => ['name', 'nama', 'nama pihak ketiga', 'nama vendor', 'vendor', 'perusahaan'],
        'external_ref' => ['external_ref', 'id rekanan', 'kode rekanan', 'kode vendor', 'vendor id', 'supplier id'],
        'category' => ['category', 'kategori'],
        'type' => ['type', 'peran', 'role'],
        'country' => ['country', 'negara'],
        'website' => ['website', 'situs', 'situs web'],
        'privacy_policy_url' => ['privacy_policy_url', 'kebijakan privasi', 'url kebijakan privasi'],
        'contact_name' => ['contact_name', 'nama kontak', 'pic', 'nama pic'],
        'contact_email' => ['contact_email', 'email', 'email kontak', 'email pic'],
        'telepon' => ['telepon', 'phone', 'no telepon', 'telepon pic'],
        'npwp' => ['npwp', 'tax id'],
        'alamat' => ['alamat', 'address'],
        'pic_jabatan' => ['pic_jabatan', 'jabatan pic', 'jabatan'],
        'departemen_kontak' => ['departemen_kontak', 'divisi', 'departemen', 'divisi pengelola'],
        'services_provided' => ['services_provided', 'layanan', 'jasa', 'layanan yang diberikan'],
        'data_shared' => ['data_shared', 'data dibagikan', 'jenis data', 'data yang dibagikan'],
        'dpa_status' => ['dpa_status', 'status dpa'],
        'dpa_expires_at' => ['dpa_expires_at', 'dpa kedaluwarsa', 'masa berlaku dpa'],
        'description' => ['description', 'deskripsi', 'keterangan'],
    ];

    /** Kolom yang isinya daftar, dipisah koma atau titik koma. */
    private const LIST_COLUMNS = ['services_provided', 'data_shared'];

    private const DPA_STATUSES = ['none', 'draft', 'signed', 'expired'];

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
            return response()->json(['message' => 'Tidak ada baris yang sah untuk diimpor.', 'data' => $parsed], 422);
        }

        $orgId = $request->user()->org_id;
        $created = [];
        $updated = [];

        DB::transaction(function () use ($parsed, $orgId, &$created, &$updated) {
            foreach ($parsed['valid_rows'] as $row) {
                $data = $row['data'];
                $existing = ! empty($data['external_ref'])
                    ? Vendor::where('org_id', $orgId)->where('external_ref', $data['external_ref'])->first()
                    : null;

                if ($existing) {
                    $existing->fill($data)->save();
                    $updated[] = ['line' => $row['line'], 'id' => $existing->id, 'name' => $existing->name];

                    continue;
                }

                $vendor = Vendor::create($data + [
                    'org_id' => $orgId,
                    // Tanpa ini baris hasil impor tidak terlihat oleh pengguna
                    // non-admin (lihat Vendor::scopeVisibleTo).
                    'assign_group' => '(All Group)',
                    'pdp_scope_status' => Vendor::SCOPE_UNSCREENED,
                ]);
                $created[] = ['line' => $row['line'], 'id' => $vendor->id, 'name' => $vendor->name];
            }
        });

        AuditLog::log('vendor_risk', $orgId, 'csv_imported', [
            'imported' => count($created),
            'updated' => count($updated),
            'skipped' => count($parsed['errors']),
        ], 'import');

        return response()->json([
            'message' => count($created).' pihak ketiga ditambahkan, '.count($updated).' diperbarui.',
            'imported' => count($created),
            'updated' => count($updated),
            'skipped' => count($parsed['errors']),
            'created' => $created,
            'updated_rows' => $updated,
            'errors' => $parsed['errors'],
        ], 201);
    }

    /** Contoh berkas CSV berisi judul kolom yang dikenali. */
    public function template()
    {
        $headers = array_keys(self::COLUMN_ALIASES);
        $sample = [
            'PT Cloud Mitra Nusantara', 'VND-00123', 'cloud_infrastructure', 'processor', 'Indonesia',
            'https://cloudmitra.co.id', 'https://cloudmitra.co.id/privasi', 'Budi Santoso',
            'budi@cloudmitra.co.id', '02150001234', '01.234.567.8-901.000', 'Jl. Jenderal Sudirman No. 1, Jakarta',
            'Manajer Kepatuhan', 'Teknologi Informasi', 'Hosting; Pencadangan', 'Nama; NIK; Kontak',
            'signed', '2027-06-30', 'Penyedia layanan awan untuk basis data nasabah',
        ];

        return response()->streamDownload(function () use ($headers, $sample) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            fputcsv($out, $sample);
            fclose($out);
        }, 'template_import_pihak_ketiga.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Baca dan validasi berkas, tanpa menulis apa pun.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function parse(Request $request): array|JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if (! $handle) {
            return response()->json(['message' => 'Berkas tidak dapat dibaca.'], 422);
        }

        // BOM dari Excel menempel di judul kolom pertama dan membuatnya tidak
        // pernah cocok dengan padanan mana pun — dibuang lebih dulu.
        $first = fgets($handle);
        fseek($handle, ($first !== false && str_starts_with($first, "\xEF\xBB\xBF")) ? 3 : 0);

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return response()->json(['message' => 'Berkas kosong atau tidak memiliki baris judul.'], 422);
        }

        $map = $this->mapHeader($header);
        if (! isset($map['name'])) {
            fclose($handle);

            return response()->json([
                'message' => 'Kolom nama pihak ketiga tidak ditemukan. Unduh templat untuk melihat judul kolom yang dikenali.',
                'detected_columns' => array_values(array_filter($header)),
                'recognized' => array_keys($map),
            ], 422);
        }

        $validRows = [];
        $errors = [];
        $line = 1;
        $seenRefs = [];
        $seenNames = [];

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

            // Duplikat DI DALAM berkas dilaporkan, bukan ditelan diam-diam:
            // hampir selalu tanda berkasnya salah disusun.
            $ref = mb_strtolower((string) ($data['external_ref'] ?? ''));
            if ($ref !== '' && isset($seenRefs[$ref])) {
                $rowErrors[] = 'Id rekanan ganda dengan baris '.$seenRefs[$ref].' pada berkas yang sama.';
            }
            $nameKey = mb_strtolower(trim((string) ($data['name'] ?? '')));
            if ($ref === '' && $nameKey !== '' && isset($seenNames[$nameKey])) {
                $rowErrors[] = 'Nama pihak ketiga ganda dengan baris '.$seenNames[$nameKey].' pada berkas yang sama.';
            }

            if ($rowErrors) {
                $errors[] = ['line' => $line, 'error' => implode(' ', $rowErrors), 'raw' => array_slice($row, 0, 3)];

                continue;
            }

            if ($ref !== '') {
                $seenRefs[$ref] = $line;
            }
            if ($nameKey !== '') {
                $seenNames[$nameKey] = $line;
            }
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
                if (! isset($map[$field]) && in_array($name, $aliases, true)) {
                    $map[$field] = $idx;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, string>} data + galat baris
     */
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
                $data[$field] = array_values(array_filter(array_map('trim', preg_split('/[;,]/', $value) ?: [])));

                continue;
            }
            $data[$field] = $value;
        }

        if (empty($data['name'])) {
            $errors[] = 'Nama pihak ketiga wajib diisi.';
        }
        if (isset($data['type'])) {
            $role = Vendor::normalizeRole($data['type']);
            if (! $role) {
                $errors[] = 'Peran tidak dikenali (gunakan controller, processor, joint_controller, atau sub_processor).';
            } else {
                $data['type'] = $role;
            }
        }
        if (isset($data['dpa_status']) && ! in_array(mb_strtolower($data['dpa_status']), self::DPA_STATUSES, true)) {
            $errors[] = 'Status DPA tidak dikenali (none, draft, signed, expired).';
        } elseif (isset($data['dpa_status'])) {
            $data['dpa_status'] = mb_strtolower($data['dpa_status']);
        }
        if (isset($data['contact_email']) && ! filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email kontak tidak sah.';
        }
        if (isset($data['dpa_expires_at']) && strtotime($data['dpa_expires_at']) === false) {
            $errors[] = 'Tanggal kedaluwarsa DPA tidak sah (gunakan YYYY-MM-DD).';
        }

        return [$data, $errors];
    }
}
