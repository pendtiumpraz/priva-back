<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Processing categories — user-defined classifications (HR, FIN, IT, MKT, ...)
 * used for ROPA/DPIA naming: `ROPA-{CODE}-{NNN}`. Each tenant maintains
 * its own list. Seeding a few starter entries is left to the UI
 * (allowCreate on LazySearchSelect) so tenants choose what fits them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('processing_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->string('code', 16);          // short uppercase slug, e.g. "HR", "FIN"
            $table->string('label', 100);        // full name shown in UI, e.g. "Human Resources"
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('ropa_counter')->default(0); // last NNN used for ROPA in current year
            $table->unsignedInteger('dpia_counter')->default(0); // last NNN used for DPIA in current year
            $table->unsignedSmallInteger('counter_year')->nullable(); // year the counters belong to
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['org_id', 'code']);
            $table->index(['org_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_categories');
    }
};
