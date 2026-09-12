<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * RoPA ↔ pihak ketiga, dengan PERAN per tautan.
 *
 * Sebelum ini satu-satunya tautan adalah daftar UUID polos di
 * wizard_data.penggunaan_penyimpanan.vendor_ids[] — tidak bisa di-query dan
 * tidak menyimpan peran. UU PDP membedakan Pengendali, Prosesor, dan Pengendali
 * Bersama (Pasal 51 dst.), dan kewajiban tiap peran berbeda; regulator juga
 * bertanya "mana saja yang Prosesor". Peran karena itu wajib bisa di-query,
 * yakni pengecualian yang memang disebut CLAUDE.md terhadap aturan "simpan di
 * wizard_data saja".
 *
 * Bentuk tabel meniru information_system_ropa & consent_collection_ropa
 * (2026_04_27_000002) supaya seluruh tautan RoPA konsisten.
 *
 * Backfill best-effort: vendor_ids[] yang ada → peran dari vendors.type bila
 * dikenali, selain itu 'processor' — bagian wizard-nya memang "pihak yang
 * memproses data pribadi", dan peta koneksi pun melabelinya "diproses pihak ketiga".
 */
return new class extends Migration
{
    /** vendors.type lama (bebas teks) → peran baku. */
    private const ROLE_ALIASES = [
        'controller' => 'controller',
        'pengendali' => 'controller',
        'processor' => 'processor',
        'pemroses' => 'processor',
        'joint' => 'joint_controller',
        'joint_controller' => 'joint_controller',
        'joint-controller' => 'joint_controller',
        'pengendali_bersama' => 'joint_controller',
        'sub_processor' => 'sub_processor',
        'sub-processor' => 'sub_processor',
        'subprocessor' => 'sub_processor',
        'subprosesor' => 'sub_processor',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ropa_vendor')) {
            try {
                Schema::create('ropa_vendor', function (Blueprint $t) {
                    $t->uuid('ropa_id');
                    $t->uuid('vendor_id');
                    $t->uuid('org_id');
                    // controller | processor | joint_controller | sub_processor
                    $t->string('role', 32)->default('processor');
                    $t->text('purpose')->nullable();        // untuk apa data diberikan ke pihak ini
                    $t->json('data_shared')->nullable();    // kategori data yang dibagikan
                    $t->string('contract_ref', 255)->nullable();
                    $t->text('notes')->nullable();
                    $t->timestamps();

                    $t->primary(['ropa_id', 'vendor_id'], 'ropa_vendor_pk');
                    $t->index('vendor_id', 'ropa_vendor_vendor_idx');
                    $t->index(['org_id', 'role'], 'ropa_vendor_org_role_idx');
                    $t->foreign('ropa_id', 'ropa_vendor_ropa_fk')
                        ->references('id')->on('ropas')->cascadeOnDelete();
                    $t->foreign('vendor_id', 'ropa_vendor_vendor_fk')
                        ->references('id')->on('vendors')->cascadeOnDelete();
                    $t->foreign('org_id', 'ropa_vendor_org_fk')
                        ->references('id')->on('organizations')->cascadeOnDelete();
                });
            } catch (Throwable $e) {
                if (! str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
            }
        }

        if (! Schema::hasTable('ropas') || ! Schema::hasTable('vendors') || ! Schema::hasTable('ropa_vendor')) {
            return;
        }

        try {
            DB::table('ropas')
                ->whereNotNull('wizard_data')
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $ropa) {
                        $wizard = is_string($ropa->wizard_data) ? json_decode($ropa->wizard_data, true) : (array) $ropa->wizard_data;
                        $ids = $wizard['penggunaan_penyimpanan']['vendor_ids'] ?? null;
                        if (! is_array($ids) || ! $ids) {
                            continue;
                        }
                        $ids = array_values(array_unique(array_filter($ids, fn ($v) => is_string($v) && $v !== '')));
                        if (! $ids) {
                            continue;
                        }
                        // Hanya pihak ketiga milik org yang sama — tautan lintas tenant tidak pernah dibuat.
                        $vendors = DB::table('vendors')
                            ->whereIn('id', $ids)
                            ->where('org_id', $ropa->org_id)
                            ->get(['id', 'type']);
                        foreach ($vendors as $vendor) {
                            $key = strtolower(trim((string) $vendor->type));

                            DB::table('ropa_vendor')->updateOrInsert([
                                'ropa_id' => $ropa->id,
                                'vendor_id' => $vendor->id,
                            ], [
                                'org_id' => $ropa->org_id,
                                'role' => self::ROLE_ALIASES[$key] ?? 'processor',
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                });
        } catch (Throwable $e) {
            // Backfill best-effort — jangan menghalangi migrasi.
            Log::warning('Backfill ropa_vendor failed: '.$e->getMessage());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ropa_vendor');
    }
};
