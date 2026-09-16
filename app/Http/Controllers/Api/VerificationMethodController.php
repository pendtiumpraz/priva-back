<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GuardianConsent;
use App\Models\VerificationMethod;
use App\Services\Consent\CacheConfigPublik;
use App\Services\Verifikasi\KlaimIdentitas;
use App\Services\Verifikasi\RegistriPenyedia;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Katalog metode verifikasi wali — PP 33/2026 Pasal 38 ayat (4).
 *
 * Dua jenis baris, dua hak:
 *   bawaan platform (org_id NULL)  terlihat semua tenant, HANYA BACA —
 *                                  `otp_email`, `otp_phone`;
 *   milik tenant                   dibuat, diubah, dihapus, diuji oleh tenant;
 *                                  kredensial penyedia (Dukcapil / e-KYC)
 *                                  ada di `config`, terenkripsi di model.
 *
 * Kredensial bersifat TULIS-SAJA: tidak pernah kembali lewat API (hanya nama
 * header dan nama bidang badan), dan pembaruan yang tidak mengirimkannya
 * mempertahankan yang lama. Nilai samaran `••••` yang dikirim balik oleh UI
 * juga berarti "pertahankan".
 *
 * Metode ini berlaku seluruh tenant (bukan per titik pengumpulan), jadi
 * tidak ada penyaringan divisi — hanya org_id.
 */
class VerificationMethodController extends Controller
{
    public function __construct(private readonly RegistriPenyedia $registri) {}

    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;

        $daftar = VerificationMethod::untukOrg($orgId)
            ->orderByRaw('(org_id IS NULL) DESC')
            ->orderBy('label')
            ->get();

        return response()->json([
            'data' => $daftar->map(fn (VerificationMethod $m) => $this->bentuk($m, $orgId))->values()->all(),
            'drivers' => VerificationMethod::driverDibuatTenant(),
            'confidence_levels' => VerificationMethod::KEYAKINAN,
            'placeholders' => VerificationMethod::PLACEHOLDER_KLAIM,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->aturan());
        $orgId = $request->user()->org_id;

        // Bentrok dengan bawaan platform ATAU milik sendiri — dua-duanya 409:
        // `untukOrg` akan mengembalikan keduanya dan widget tidak bisa memilih.
        if (VerificationMethod::untukOrg($orgId)->where('code', $data['code'])->exists()) {
            return response()->json(['error' => 'Kode metode sudah dipakai.', 'code' => 'SUDAH_ADA'], 409);
        }

        $m = VerificationMethod::create([
            'org_id' => $orgId,
            'code' => $data['code'],
            'label' => $data['label'],
            'driver' => $data['driver'],
            'confidence' => $data['confidence'] ?? $this->keyakinanBawaan($data['driver']),
            'is_active' => (bool) ($data['is_active'] ?? true),
            // "Teknologi yang tersedia" adalah standar bergerak — tanpa
            // tanggal tinjau, metode disetel sekali lalu basi diam-diam.
            'review_at' => $data['review_at'] ?? now()->addYear()->toDateString(),
            'config' => $this->configDariMasukan($data['driver'], $data['config'] ?? [], null),
        ]);

        $this->audit($request, 'verification_method.create', $m->id, ['code' => $m->code, 'driver' => $m->driver, 'confidence' => $m->confidence]);
        CacheConfigPublik::segarkanOrg($orgId);

        return response()->json(['data' => $this->bentuk($m, $orgId)], 201);
    }

    public function update(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $m = $this->milikTenant($orgId, $id);

        $data = $request->validate($this->aturan(sometimes: true));

        if (isset($data['code']) && $data['code'] !== $m->code
            && VerificationMethod::untukOrg($orgId)->where('code', $data['code'])->where('id', '!=', $m->id)->exists()) {
            return response()->json(['error' => 'Kode metode sudah dipakai.', 'code' => 'SUDAH_ADA'], 409);
        }

        $driver = $data['driver'] ?? $m->driver;

        $m->fill(collect($data)->only(['code', 'label', 'driver', 'confidence', 'is_active', 'review_at'])->all());
        if (array_key_exists('config', $data)) {
            $m->config = $this->configDariMasukan($driver, $data['config'] ?? [], $m->config);
        } elseif ($driver !== $m->getOriginal('driver')) {
            // Ganti driver tanpa config baru: pastikan config lama masih sah untuk driver barunya.
            $m->config = $this->configDariMasukan($driver, [], $m->config);
        }
        $m->save();

        // Audit mencatat BIDANG yang berubah, tidak pernah isi config.
        $this->audit($request, 'verification_method.update', $m->id, ['fields' => array_keys($data)]);
        CacheConfigPublik::segarkanOrg($orgId);

        return response()->json(['data' => $this->bentuk($m->fresh() ?? $m, $orgId)]);
    }

    public function destroy(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $m = $this->milikTenant($orgId, $id);

        // Rujukan dari guardian_consents LONGGAR (kode, bukan FK): bukti
        // verifikasi yang sudah terjadi tetap utuh setelah metodenya dihapus.
        $m->delete();

        $this->audit($request, 'verification_method.delete', $id, ['code' => $m->code, 'driver' => $m->driver]);
        CacheConfigPublik::segarkanOrg($orgId);

        return response()->json(['message' => 'Metode verifikasi dihapus.']);
    }

    /**
     * Uji metode dengan klaim yang DIPILIH admin (mis. NIK-nya sendiri).
     * Klaim tidak disimpan; yang diaudit hanya status hasilnya. Ini satu-
     * satunya cara jujur membuktikan integrasi jalan — HEAD ke endpoint
     * tidak membuktikan apa-apa tentang kontrak responsnya.
     */
    public function test(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $m = VerificationMethod::untukOrg($orgId)->find($id);
        if (! $m) {
            abort(404, 'Metode verifikasi tidak ditemukan.');
        }
        if (! $m->kuat()) {
            return response()->json(['error' => 'Metode ini bukan metode verifikasi identitas; tidak ada yang bisa diuji.', 'code' => 'BUKAN_METODE_KUAT'], 422);
        }

        $klaim = $request->validate([
            'nik' => 'required|digits:16',
            'name' => 'required|string|max:120',
            'birth_date' => 'required|date_format:Y-m-d|before:today',
        ]);

        $hasil = $this->registri->periksa($m, new KlaimIdentitas($klaim['nik'], $klaim['name'], $klaim['birth_date']));

        $this->audit($request, 'verification_method.test', $m->id, ['code' => $m->code, 'status' => $hasil->status, 'http_status' => $hasil->httpStatus]);

        return response()->json(['data' => $hasil->toArray()]);
    }

    // ───────────────────────── internal ─────────────────────────

    /** @return array<string, mixed> */
    private function aturan(bool $sometimes = false): array
    {
        $wajib = $sometimes ? 'sometimes' : 'required';
        $s = $sometimes ? 'sometimes|' : '';

        return [
            'code' => [$wajib, 'string', 'regex:/^[a-z0-9_]{3,48}$/'],
            'label' => $s.'required|string|max:120',
            // OTP milik platform; tenant membuat metode KUAT atau pernyataan
            // tenant. `mock` ikut daftar hanya di luar produksi (RegistriPenyedia).
            'driver' => [$wajib, Rule::in(VerificationMethod::driverDibuatTenant())],
            'confidence' => ['sometimes', Rule::in(VerificationMethod::KEYAKINAN)],
            'is_active' => 'sometimes|boolean',
            'review_at' => 'sometimes|nullable|date',
            'config' => 'sometimes|nullable|array',
            'config.endpoint' => 'sometimes|nullable|url|max:500',
            'config.method' => ['sometimes', 'nullable', Rule::in(['GET', 'POST', 'get', 'post'])],
            'config.timeout' => 'sometimes|nullable|integer|min:1|max:'.VerificationMethod::TIMEOUT_MAKS,
            'config.headers' => 'sometimes|nullable|array',
            'config.headers.*' => 'nullable|string|max:2000',
            'config.body' => 'sometimes|nullable|array',
            'config.birth_date_format' => 'sometimes|nullable|string|max:20',
            'config.match_all' => 'sometimes|nullable|array',
            'config.match_all.*.path' => 'required|string|max:200',
            'config.match_all.*.equals' => 'present',
            'config.mismatch_any' => 'sometimes|nullable|array',
            'config.mismatch_any.*.path' => 'required|string|max:200',
            'config.mismatch_any.*.equals' => 'present',
            'config.reference_path' => 'sometimes|nullable|string|max:200',
            'config.reason_path' => 'sometimes|nullable|string|max:200',
            'config.accept_nik' => 'sometimes|nullable|array|max:50',
            'config.accept_nik.*' => 'digits:16',
        ];
    }

    private function keyakinanBawaan(string $driver): string
    {
        // Dukcapil/e-KYC membuktikan identitas → tinggi. Simulasi tidak
        // membuktikan apa pun → rendah, supaya data staging jujur. Pernyataan
        // tenant → sedang: kami tidak memeriksanya, tenant yang menanggung.
        return match ($driver) {
            VerificationMethod::DRIVER_MOCK => 'rendah',
            VerificationMethod::DRIVER_TENANT => 'sedang',
            default => 'tinggi',
        };
    }

    /**
     * Gabungkan config baru ke yang lama (semantik PATCH), dengan dua aturan
     * rahasia: kunci `headers`/`body` yang tidak dikirim dipertahankan; nilai
     * samaran `••••` di dalamnya berarti "jangan ubah yang ini". `null`
     * eksplisit menghapus. Lalu periksa kelengkapan untuk driver-nya.
     *
     * @param  array<string, mixed>  $baru
     * @param  array<string, mixed>|null  $lama
     * @return array<string, mixed>|null
     */
    private function configDariMasukan(string $driver, array $baru, ?array $lama): ?array
    {
        $lama ??= [];

        foreach (['headers', 'body'] as $rahasia) {
            if (isset($baru[$rahasia]) && is_array($baru[$rahasia])) {
                foreach ($baru[$rahasia] as $k => $v) {
                    if ($v === VerificationMethod::TERSAMAR) {
                        if (isset($lama[$rahasia][$k])) {
                            $baru[$rahasia][$k] = $lama[$rahasia][$k];
                        } else {
                            unset($baru[$rahasia][$k]);
                        }
                    }
                }
            }
        }

        $gabung = array_merge($lama, $baru);
        $gabung = array_filter($gabung, fn ($v) => $v !== null);

        if (isset($gabung['method'])) {
            $gabung['method'] = strtoupper((string) $gabung['method']);
        }
        if (isset($gabung['timeout'])) {
            $gabung['timeout'] = (int) $gabung['timeout'];
        }

        if (in_array($driver, [VerificationMethod::DRIVER_DUKCAPIL, VerificationMethod::DRIVER_EKYC], true)) {
            $endpoint = trim((string) ($gabung['endpoint'] ?? ''));
            if ($endpoint === '') {
                throw ValidationException::withMessages(['config.endpoint' => ['Endpoint penyedia wajib untuk driver ini.']]);
            }
            // Kredensial tenant dan NIK wali tidak boleh lewat jalur polos.
            if (RegistriPenyedia::produksi() && ! str_starts_with(strtolower($endpoint), 'https://')) {
                throw ValidationException::withMessages(['config.endpoint' => ['Endpoint penyedia harus HTTPS.']]);
            }
            if (empty($gabung['match_all']) || ! is_array($gabung['match_all'])) {
                throw ValidationException::withMessages(['config.match_all' => ['Minimal satu aturan kecocokan (path + equals) wajib diisi.']]);
            }
        }

        return $gabung === [] ? null : $gabung;
    }

    /** Milik tenant ini — bawaan platform ditolak TERBUKA, bukan 404 yang menyamar. */
    private function milikTenant(string $orgId, string $id): VerificationMethod
    {
        $m = VerificationMethod::untukOrg($orgId)->find($id);
        if (! $m) {
            abort(404, 'Metode verifikasi tidak ditemukan.');
        }
        if ($m->bawaanPlatform()) {
            abort(response()->json([
                'error' => 'Metode bawaan platform hanya bisa dibaca. Buat metode milik organisasi Anda sendiri.',
                'code' => 'BAWAAN_PLATFORM',
            ], 403));
        }

        return $m;
    }

    /** @param  array<string, mixed>  $perubahan */
    private function audit(Request $request, string $aksi, string $recordId, array $perubahan = []): void
    {
        $user = $request->user();
        AuditLog::create([
            'module' => 'consent',
            'record_id' => $recordId,
            'action' => $aksi,
            'user_id' => $user->id,
            'user_name' => $user->name ?? null,
            'user_role' => $user->role ?? null,
            'changes' => $perubahan,
            'ip_address' => $request->ip(),
        ]);
    }

    /** @return array<string, mixed> */
    private function bentuk(VerificationMethod $m, string $orgId): array
    {
        return [
            'id' => $m->id,
            'code' => $m->code,
            'label' => $m->label,
            'driver' => $m->driver,
            'confidence' => $m->confidence,
            'is_active' => (bool) $m->is_active,
            'is_platform_default' => $m->bawaanPlatform(),
            'strong' => $m->kuat(),
            // Terdaftar ≠ bisa dijalankan; widget hanya menawarkan yang kedua.
            'runnable' => $m->dapatDijalankan(),
            'review_at' => $m->review_at?->toDateString(),
            'review_due' => $m->review_at !== null && $m->review_at->isPast(),
            'config' => $m->kuat() ? $m->configPublik() : null,
            'usage_count' => GuardianConsent::withoutGlobalScope('org')
                ->where('org_id', $orgId)
                ->where('verification_method_code', $m->code)
                ->count(),
            'created_at' => $m->created_at?->toIso8601String(),
            'updated_at' => $m->updated_at?->toIso8601String(),
        ];
    }
}
