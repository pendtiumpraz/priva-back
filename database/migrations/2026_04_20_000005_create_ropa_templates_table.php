<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ropa_templates')) return;

        Schema::create('ropa_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('industry', 40);              // banking | healthcare | retail | fintech | insurance | government | telco | general
            $table->string('activity_code', 80)->nullable();
            $table->text('description')->nullable();
            $table->jsonb('wizard_data');                // pre-filled wizard payload
            $table->boolean('is_system')->default(true); // seeded vs user-created
            $table->uuid('org_id')->nullable();          // null = global seeded; per-tenant custom later
            $table->boolean('is_active')->default(true);
            $table->integer('usage_count')->default(0);
            $table->timestamps();

            $table->index('industry');
            $table->index(['is_active', 'industry']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ropa_templates');
    }
};
