<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\VectorEmbedding;
use App\Services\CurrentOrgContext;
use App\Services\EmbeddingService;
use App\Services\VectorSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Sprint RAG — Cross-tenant isolation guarantees untuk VectorSearchService.
 *
 * SECURITY-CRITICAL: Test ini memverifikasi bahwa pipeline RAG TIDAK PERNAH
 * mengembalikan baris embedding milik tenant lain — baik via:
 *   1. `VectorSearchService::search($orgId, ...)` (defense layer 2: WHERE org_id)
 *   2. `VectorSearchService::findRelated($orgId, ...)` (same, plus source lookup)
 *   3. `VectorEmbedding` Eloquent (defense layer 3: BelongsToOrg global scope)
 *   4. Guard `$orgId` non-empty di service signature (defense layer 0: fail-fast)
 *
 * Scope: layer Application/Service, BUKAN test cosine similarity pgvector
 * (butuh Postgres + pgvector + TEI server). Test environment phpunit memakai
 * SQLite in-memory; pgvector-dependent assertion akan auto-skip. EmbeddingService
 * dimock supaya tidak ada HTTP call ke TEI/OpenAI dan vector dimensi fixed.
 *
 * @see docs/RAG_IMPLEMENTATION_SPEC.md (section "Cross-Tenant Isolation Rules")
 */
class VectorSearchTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;
    private Organization $orgB;
    private User $userA;
    private User $userB;

    /** @var array<int,string> source_ids di org A — diperlukan untuk assertion strict. */
    private array $sourceIdsA = [];

    /** @var array<int,string> source_ids di org B — diperlukan untuk assertion strict. */
    private array $sourceIdsB = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Reset tenant context — beberapa test akan set ulang via app(CurrentOrgContext)
        // untuk simulate "request user X dari org A". Awal-awal harus bersih supaya
        // global scope tidak no-op kebawa dari test sebelumnya.
        app(CurrentOrgContext::class)->clear();

        $this->orgA = Organization::create([
            'name' => 'PT Tenant Alpha',
            'slug' => 'tenant-a-'.Str::random(6),
        ]);

        $this->orgB = Organization::create([
            'name' => 'PT Tenant Bravo',
            'slug' => 'tenant-b-'.Str::random(6),
        ]);

        $this->userA = User::factory()->create([
            'org_id' => $this->orgA->id,
            'role' => 'admin',
        ]);

        $this->userB = User::factory()->create([
            'org_id' => $this->orgB->id,
            'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        // Pastikan tenant context bersih supaya tidak bocor ke test berikut.
        app(CurrentOrgContext::class)->clear();
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================
    // CRITICAL: search() org_id filtering
    // =========================================================

    public function test_vector_search_filters_by_org_id(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'VectorSearchService::search() butuh Postgres pgvector untuk eksekusi raw SQL '
                .'(`embedding <=> ?::vector`). SQLite tidak punya operator pgvector — service '
                .'akan early-return [] untuk safety. Test ini cuma valid di Postgres env.'
            );
        }

        $this->seedEmbeddings(orgId: $this->orgA->id, count: 5, sourceIdBag: $this->sourceIdsA);
        $this->seedEmbeddings(orgId: $this->orgB->id, count: 5, sourceIdBag: $this->sourceIdsB);

        $this->bindMockedEmbeddingService();
        $svc = app(VectorSearchService::class);

        // Query as org A.
        $resultsA = $svc->search($this->orgA->id, 'kueri uji apa saja', topK: 20, minSimilarity: 0.0);

        foreach ($resultsA as $row) {
            $this->assertContains(
                $row['source_id'],
                $this->sourceIdsA,
                'search() org A mengembalikan source_id yang BUKAN milik org A — cross-tenant LEAK.'
            );
            $this->assertNotContains(
                $row['source_id'],
                $this->sourceIdsB,
                'search() org A mengembalikan source_id milik org B — cross-tenant LEAK.'
            );
        }

        // Query as org B.
        $resultsB = $svc->search($this->orgB->id, 'kueri uji apa saja', topK: 20, minSimilarity: 0.0);

        foreach ($resultsB as $row) {
            $this->assertContains(
                $row['source_id'],
                $this->sourceIdsB,
                'search() org B mengembalikan source_id yang BUKAN milik org B — cross-tenant LEAK.'
            );
            $this->assertNotContains(
                $row['source_id'],
                $this->sourceIdsA,
                'search() org B mengembalikan source_id milik org A — cross-tenant LEAK.'
            );
        }

        // Sanity: kedua belah org HARUS dapat result (data sudah di-seed 5/5).
        // Threshold longgar (minSimilarity=0.0, topK=20) supaya jumlah real hasil
        // ditentukan oleh org_id filter, bukan cosine threshold.
        $this->assertNotEmpty($resultsA, 'search() org A: harus return setidaknya 1 row dengan threshold 0.0.');
        $this->assertNotEmpty($resultsB, 'search() org B: harus return setidaknya 1 row dengan threshold 0.0.');
    }

    public function test_vector_search_throws_on_empty_org_id(): void
    {
        $this->bindMockedEmbeddingService();
        $svc = app(VectorSearchService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('orgId is required');

        // Empty orgId harus fail-fast SEBELUM query — guard di service.
        $svc->search('', 'kueri uji');
    }

    // =========================================================
    // CRITICAL: findRelated() org_id filtering
    // =========================================================

    public function test_findRelated_isolates_by_org(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'VectorSearchService::findRelated() butuh Postgres pgvector — service akan '
                .'early-return [] di non-Postgres environment.'
            );
        }

        // Seed 1 row "anchor" di tiap org dengan source_type=ropa supaya findRelated()
        // bisa lookup vektor sumber, lalu beberapa row lain untuk dijadikan kandidat hasil.
        $ropaIdA = (string) Str::uuid();
        $ropaIdB = (string) Str::uuid();

        $this->insertRawEmbedding(
            orgId: $this->orgA->id,
            sourceType: 'ropa',
            sourceId: $ropaIdA,
            excerpt: 'RoPA anchor org A',
        );
        $this->insertRawEmbedding(
            orgId: $this->orgB->id,
            sourceType: 'ropa',
            sourceId: $ropaIdB,
            excerpt: 'RoPA anchor org B',
        );

        // Tambah beberapa row "tetangga" di tiap org.
        $this->seedEmbeddings(orgId: $this->orgA->id, count: 4, sourceIdBag: $this->sourceIdsA);
        $this->seedEmbeddings(orgId: $this->orgB->id, count: 4, sourceIdBag: $this->sourceIdsB);

        $this->bindMockedEmbeddingService();
        $svc = app(VectorSearchService::class);

        // findRelated dari sudut org A → tidak boleh ada source_id milik org B di hasil.
        $relatedA = $svc->findRelated($this->orgA->id, 'ropa', $ropaIdA, topK: 20);

        foreach ($relatedA as $row) {
            $this->assertNotSame(
                $ropaIdB,
                $row['source_id'],
                'findRelated() org A mengembalikan anchor row org B — cross-tenant LEAK.'
            );
            $this->assertNotContains(
                $row['source_id'],
                $this->sourceIdsB,
                'findRelated() org A mengembalikan source_id milik org B — cross-tenant LEAK.'
            );
        }

        // findRelated dari org B dengan source_id (ropaIdA) yang sebenarnya milik org A:
        // service WAJIB tidak menemukan anchor (WHERE org_id = orgB.id) sehingga return [].
        $crossLookup = $svc->findRelated($this->orgB->id, 'ropa', $ropaIdA, topK: 20);
        $this->assertSame(
            [],
            $crossLookup,
            'findRelated() org B dengan source_id milik org A harus return [] (anchor tidak ditemukan dalam org B).'
        );
    }

    // =========================================================
    // CRITICAL: BelongsToOrg global scope active on VectorEmbedding model
    // =========================================================

    public function test_belongs_to_org_global_scope_active_on_VectorEmbedding(): void
    {
        // Insert pakai DB::table langsung supaya bypass creating event (mutator
        // BelongsToOrg yang auto-isi org_id). Ini menguji bahwa GLOBAL SCOPE
        // tetap aktif waktu QUERY, terlepas dari bagaimana row di-insert.
        $now = now();

        $idA1 = (string) Str::uuid();
        $idA2 = (string) Str::uuid();
        $idB1 = (string) Str::uuid();
        $idB2 = (string) Str::uuid();

        $rows = [
            $this->rawRow($idA1, $this->orgA->id, 'ropa', (string) Str::uuid(), 'org A row 1', $now),
            $this->rawRow($idA2, $this->orgA->id, 'dpia', (string) Str::uuid(), 'org A row 2', $now),
            $this->rawRow($idB1, $this->orgB->id, 'ropa', (string) Str::uuid(), 'org B row 1', $now),
            $this->rawRow($idB2, $this->orgB->id, 'dpia', (string) Str::uuid(), 'org B row 2', $now),
        ];

        foreach ($rows as $r) {
            DB::table('vector_embeddings')->insert($r);
        }

        // Context = org A → query Eloquent hanya kembalikan row org A.
        app(CurrentOrgContext::class)->set($this->orgA->id);

        $visibleAsA = VectorEmbedding::all();
        $idsAsA = $visibleAsA->pluck('id')->all();

        $this->assertContains($idA1, $idsAsA, 'Eloquent dalam context org A harus melihat row org A.');
        $this->assertContains($idA2, $idsAsA, 'Eloquent dalam context org A harus melihat row org A.');
        $this->assertNotContains(
            $idB1,
            $idsAsA,
            'BelongsToOrg global scope TIDAK aktif — row org B bocor ke context org A.'
        );
        $this->assertNotContains(
            $idB2,
            $idsAsA,
            'BelongsToOrg global scope TIDAK aktif — row org B bocor ke context org A.'
        );

        // Switch context ke org B → query Eloquent hanya kembalikan row org B.
        app(CurrentOrgContext::class)->set($this->orgB->id);

        $visibleAsB = VectorEmbedding::all();
        $idsAsB = $visibleAsB->pluck('id')->all();

        $this->assertContains($idB1, $idsAsB, 'Eloquent dalam context org B harus melihat row org B.');
        $this->assertContains($idB2, $idsAsB, 'Eloquent dalam context org B harus melihat row org B.');
        $this->assertNotContains(
            $idA1,
            $idsAsB,
            'BelongsToOrg global scope TIDAK aktif — row org A bocor ke context org B.'
        );
        $this->assertNotContains(
            $idA2,
            $idsAsB,
            'BelongsToOrg global scope TIDAK aktif — row org A bocor ke context org B.'
        );

        // Cleanup context.
        app(CurrentOrgContext::class)->clear();
    }

    // =========================================================
    // Helpers
    // =========================================================

    /**
     * Bind a mocked EmbeddingService ke container. Fixed dimension 1024 dan
     * fixed vector value supaya VectorSearchService::search() bisa generate
     * SQL `vector_cast` string yang deterministik tanpa HTTP call ke TEI.
     *
     * NOTE: di test environment SQLite, service akan early-return [] via
     * isPostgres() guard SEBELUM memanggil $embedding->embed() — tapi kita
     * tetap bind mock supaya constructor injection resolve dan supaya kalau
     * test diijalankan di Postgres CI lane, embed() return vektor valid.
     */
    private function bindMockedEmbeddingService(): void
    {
        $this->mock(EmbeddingService::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(array_fill(0, 1024, 0.1));
            $mock->shouldReceive('batchEmbed')->andReturn([array_fill(0, 1024, 0.1)]);
            $mock->shouldReceive('getDimension')->andReturn(1024);
            $mock->shouldReceive('getProviderName')->andReturn('test');
            $mock->shouldReceive('getModelName')->andReturn('test-model');
            $mock->shouldReceive('isAvailable')->andReturn(true);
        });
    }

    /**
     * Seed N vector_embeddings rows untuk org tertentu, memakai DB::insert
     * langsung supaya kompatibel dengan SQLite (kolom embedding bertipe JSON
     * di non-Postgres — lihat migration 2026_05_19_120001) dan menghindari
     * mutator BelongsToOrg.
     *
     * @param  array<int,string>  $sourceIdBag  By-ref bucket untuk mengumpulkan source_id yg di-seed.
     */
    private function seedEmbeddings(string $orgId, int $count, array &$sourceIdBag): void
    {
        $now = now();
        for ($i = 0; $i < $count; $i++) {
            $sid = (string) Str::uuid();
            $sourceIdBag[] = $sid;
            $this->insertRawEmbedding(
                orgId: $orgId,
                sourceType: 'ropa',
                sourceId: $sid,
                excerpt: 'seeded excerpt #'.$i.' org '.$orgId,
                now: $now,
            );
        }
    }

    /**
     * Insert satu row vector_embeddings via DB::table — bypass model events.
     * Dipakai juga untuk anchor row di findRelated test.
     */
    private function insertRawEmbedding(
        string $orgId,
        string $sourceType,
        string $sourceId,
        string $excerpt,
        ?\DateTimeInterface $now = null,
    ): string {
        $id = (string) Str::uuid();
        DB::table('vector_embeddings')->insert(
            $this->rawRow($id, $orgId, $sourceType, $sourceId, $excerpt, $now ?? now())
        );

        return $id;
    }

    /**
     * Build a raw row array sesuai schema vector_embeddings.
     * - `embedding`: di Postgres kolom ini bertipe vector(1024). Insert via
     *   PDO param-bind butuh format string `[v1,v2,...]` agar pgvector auto-cast.
     *   Di SQLite kolom bertipe JSON → string yang sama juga lolos sebagai blob.
     * - `metadata`: JSON string (bukan array) supaya driver SQLite menerima.
     */
    private function rawRow(
        string $id,
        string $orgId,
        string $sourceType,
        string $sourceId,
        string $excerpt,
        \DateTimeInterface $now,
    ): array {
        $vec = array_fill(0, 1024, 0.1);
        $embeddingValue = DB::getDriverName() === 'pgsql'
            ? '['.implode(',', $vec).']'
            : json_encode($vec);

        return [
            'id' => $id,
            'org_id' => $orgId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'content_hash' => hash('sha256', $id.$excerpt),
            'embedding' => $embeddingValue,
            'content_excerpt' => $excerpt,
            'metadata' => json_encode(['seed' => true]),
            'embedding_provider' => 'test',
            'embedding_model' => 'test-model',
            'embedding_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ];
    }
}
