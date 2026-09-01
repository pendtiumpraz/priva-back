<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kelengkapan field transfer lintas negara — PP 33/2026 Pasal 162 & 169(2).
 *   - transfer_sector       : lingkup sektor transfer (Pasal 162 huruf d)
 *   - storage_location      : tempat penyimpanan Data Pribadi (Pasal 162 huruf g)
 *   - onward_transfer_allowed / _detail : kemungkinan transfer lanjutan ke
 *     negara lain (Pasal 162 huruf i / 160(4))
 *   - accountability_doc     : dokumentasi akuntabilitas tertulis atas instrumen
 *     mengikat (SCC/BCR) yang dapat ditunjukkan ke Lembaga (Pasal 169(2))
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cross_border_transfers', function (Blueprint $table) {
            $table->string('transfer_sector', 150)->nullable()->after('transfer_mechanism');
            $table->string('storage_location', 200)->nullable()->after('transfer_sector');
            $table->boolean('onward_transfer_allowed')->default(false)->after('storage_location');
            $table->text('onward_transfer_detail')->nullable()->after('onward_transfer_allowed');
            $table->text('accountability_doc')->nullable()->after('onward_transfer_detail');
        });
    }

    public function down(): void
    {
        Schema::table('cross_border_transfers', function (Blueprint $table) {
            $table->dropColumn(['transfer_sector', 'storage_location', 'onward_transfer_allowed', 'onward_transfer_detail', 'accountability_doc']);
        });
    }
};
