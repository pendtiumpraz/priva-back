<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom sharding metadata + descriptive fields ke information_systems.
 * Required untuk DSR scope picker (pilih shards mana yang affected per system).
 *
 * Kolom baru:
 *   - code: short identifier untuk SQL Pack filename (e.g., "CB" = Core Banking)
 *   - description: keterangan untuk DPO scope picker UI
 *   - is_sharded: boolean — apakah system pakai sharded DB
 *   - shards: JSON array shard names (e.g., ["shard_01", "shard_02", ...])
 *   - connection_type: legacy DB type identifier (mysql, postgresql, dll) — derived dari source_type
 *
 * Reuses existing source_type + connection_config kalau sudah diisi.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('information_systems')) return;

        $cols = [
            'code' => fn(Blueprint $t) => $t->string('code', 32)->nullable()->after('name'),
            'description' => fn(Blueprint $t) => $t->text('description')->nullable()->after('code'),
            'is_sharded' => fn(Blueprint $t) => $t->boolean('is_sharded')->default(false)->after('description'),
            'shards' => fn(Blueprint $t) => $t->json('shards')->nullable()->after('is_sharded'),
            'connection_type' => fn(Blueprint $t) => $t->string('connection_type', 32)->nullable()->after('source_type'),
        ];

        foreach ($cols as $name => $fn) {
            if (Schema::hasColumn('information_systems', $name)) continue;
            try {
                Schema::table('information_systems', function (Blueprint $t) use ($fn) { $fn($t); });
            } catch (\Illuminate\Database\QueryException $e) {
                $code = $e->errorInfo[1] ?? null;
                if ($code === 1060 || in_array($e->getCode(), ['42701', '42S21'], true)) continue;
                throw $e;
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('information_systems')) return;
        foreach (['code', 'description', 'is_sharded', 'shards', 'connection_type'] as $col) {
            if (Schema::hasColumn('information_systems', $col)) {
                try { Schema::table('information_systems', fn(Blueprint $t) => $t->dropColumn($col)); }
                catch (\Throwable $e) {}
            }
        }
    }
};
