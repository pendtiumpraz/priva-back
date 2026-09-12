<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daur hidup pihak ketiga + kontrak sebagai catatan tersendiri.
 *
 * Sebelum ini TPRM hanya mengenal siklus ASESMEN (saring → nilai → setujui →
 * pantau). Pihak ketiganya sendiri tidak punya status apa pun: tidak ada
 * "aktif", tidak ada tanggal mulai/berakhir, tidak ada pemilik internal, dan
 * tidak ada jalan keluar — rekomendasi "putus kontrak" pun hanya menutup jadwal
 * pemantauan tanpa mengubah apa-apa pada pihak ketiganya.
 *
 * Kontrak dipisah ke tabelnya sendiri (bukan menumpang `vendors.documents`)
 * karena satu pihak ketiga bisa punya banyak kontrak dengan masa berlaku
 * berbeda, dan masa berlaku itu harus bisa di-query untuk lini masa dan
 * pengingat kedaluwarsa. Kolom `access_token` memungkinkan pihak ketiga
 * mengunggah sendiri kontraknya lewat tautan publik — bila perusahaan tidak
 * mengunggah, pihak ketiga yang melakukannya.
 */
return new class extends Migration
{
    /** @var array<string, string> kolom daur hidup di `vendors` */
    private const VENDOR_COLUMNS = [
        'lifecycle_status', 'owner_user_id', 'activated_at',
        'terminated_at', 'termination_reason', 'offboarding_checklist', 'offboarded_at',
    ];

    public function up(): void
    {
        if (Schema::hasTable('vendors')) {
            Schema::table('vendors', function (Blueprint $t) {
                if (! Schema::hasColumn('vendors', 'lifecycle_status')) {
                    // prospective | in_onboarding | active | suspended | offboarding | terminated
                    $t->string('lifecycle_status', 24)->default('prospective');
                }
                if (! Schema::hasColumn('vendors', 'owner_user_id')) {
                    $t->uuid('owner_user_id')->nullable();   // penanggung jawab internal
                }
                if (! Schema::hasColumn('vendors', 'activated_at')) {
                    $t->timestamp('activated_at')->nullable();
                }
                if (! Schema::hasColumn('vendors', 'terminated_at')) {
                    $t->timestamp('terminated_at')->nullable();
                }
                if (! Schema::hasColumn('vendors', 'termination_reason')) {
                    $t->text('termination_reason')->nullable();
                }
                if (! Schema::hasColumn('vendors', 'offboarding_checklist')) {
                    // [{key, label, done, done_at, done_by, evidence:{...}, notes}]
                    $t->json('offboarding_checklist')->nullable();
                }
                if (! Schema::hasColumn('vendors', 'offboarded_at')) {
                    $t->timestamp('offboarded_at')->nullable();
                }
            });

            if (! Schema::hasColumn('vendors', 'lifecycle_idx_added')) {
                try {
                    Schema::table('vendors', function (Blueprint $t) {
                        $t->index(['org_id', 'lifecycle_status'], 'vendors_org_lifecycle_idx');
                    });
                } catch (Throwable $e) {
                    // indeks sudah ada — abaikan
                }
            }
        }

        if (! Schema::hasTable('vendor_contracts')) {
            Schema::create('vendor_contracts', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('org_id');
                $t->uuid('vendor_id');

                $t->string('title');
                $t->string('contract_type', 32)->default('dpa'); // dpa|nda|msa|sow|other
                $t->string('contract_number')->nullable();
                $t->date('start_at')->nullable();
                $t->date('end_at')->nullable();
                $t->boolean('auto_renew')->default(false);
                $t->unsignedSmallInteger('notice_days')->nullable(); // masa pemberitahuan sebelum berakhir
                $t->string('status', 24)->default('draft');          // draft|active|expired|terminated

                // Berkas kontrak: {path, driver, filename, size, uploaded_at}
                $t->json('file')->nullable();
                $t->string('uploaded_side', 16)->nullable();         // tenant | third_party
                $t->uuid('uploaded_by')->nullable();                 // null bila lewat tautan publik

                // Tautan ke hasil telaah kontrak berbantuan AI (tabel contract_reviews).
                $t->uuid('contract_review_id')->nullable();

                // Tautan publik sekali-unggah untuk pihak ketiga.
                $t->uuid('access_token')->nullable()->unique();
                $t->timestamp('token_expires_at')->nullable();
                $t->timestamp('token_consumed_at')->nullable();

                $t->text('notes')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->index(['org_id', 'vendor_id'], 'vendor_contracts_org_vendor_idx');
                $t->index(['org_id', 'status'], 'vendor_contracts_org_status_idx');
                $t->index(['org_id', 'end_at'], 'vendor_contracts_org_end_idx');
                $t->foreign('org_id', 'vendor_contracts_org_fk')
                    ->references('id')->on('organizations')->cascadeOnDelete();
                $t->foreign('vendor_id', 'vendor_contracts_vendor_fk')
                    ->references('id')->on('vendors')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_contracts');

        if (! Schema::hasTable('vendors')) {
            return;
        }

        try {
            Schema::table('vendors', function (Blueprint $t) {
                $t->dropIndex('vendors_org_lifecycle_idx');
            });
        } catch (Throwable $e) {
            // indeks mungkin tidak pernah dibuat — abaikan
        }

        Schema::table('vendors', function (Blueprint $t) {
            foreach (self::VENDOR_COLUMNS as $column) {
                if (Schema::hasColumn('vendors', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
