<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_base_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('module_key')->unique(); // e.g. 'ropa', 'dpia', 'breach', 'general'
            $table->string('title');                 // e.g. 'ROPA (Record of Processing Activities)'
            $table->text('content');                  // Full markdown content for this module
            $table->text('keywords');                 // Comma-separated keywords for matching
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_sections');
    }
};
