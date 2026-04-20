<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leak_detections')) {
            // Table already exists (likely from a half-run migration where
            // MySQL auto-committed the DDL but the migration row wasn't
            // written). Skip safely so `artisan migrate` can continue.
            return;
        }

        Schema::create('leak_detections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('system_id');
            $table->uuid('org_id');
            $table->uuid('user_id')->nullable();

            $table->string('table_name', 200);
            $table->string('match_mode', 20)->default('exact');
            $table->json('columns');              // list of column names that were checked
            $table->json('query_template')->nullable(); // SELECT ... with `?` placeholders, no values

            $table->integer('found_count')->default(0);
            $table->boolean('leak_confirmed')->default(false);
            $table->json('sample_masked')->nullable(); // masked sample rows

            $table->timestamps();

            $table->index(['system_id', 'created_at']);
            $table->index(['org_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leak_detections');
    }
};
