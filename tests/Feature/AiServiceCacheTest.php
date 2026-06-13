<?php

namespace Tests\Feature;

use App\Services\AiService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\PolicyGenerator\PolicyGeneratorTestCase;

/**
 * AiService response-cache behavior. Two hardening invariants:
 *  - cache is isolated per tenant (no cross-tenant content bleed), and
 *  - unparseable AI output is NOT cached (no 24h lock-in of a bad response).
 * Plus a regression lock: a valid response IS still cached.
 *
 * Reuses PolicyGeneratorTestCase's provider seeding. seedAiProvider() always
 * uses model_id 'test-model', so two orgs resolve the SAME model — which means
 * the ONLY thing isolating their cache keys is org_id.
 */
class AiServiceCacheTest extends PolicyGeneratorTestCase
{
    private function content(string $body): array
    {
        return ['choices' => [['message' => ['content' => $body]]]];
    }

    public function test_valid_response_is_cached_for_same_tenant_and_prompt(): void
    {
        $org = $this->makeOrg();
        $this->seedAiProvider($org);
        Http::fake(['*chat/completions*' => Http::response($this->content('{"ok":true}'), 200)]);

        $ai = new AiService($org->id);
        $first = $ai->ask('system', 'user');
        $second = $ai->ask('system', 'user');

        $this->assertSame(true, $first['ok']);
        $this->assertSame(true, $second['ok']);
        // Second identical call served from cache → provider hit only once.
        Http::assertSentCount(1);
    }

    public function test_cache_is_isolated_per_tenant(): void
    {
        $orgA = $this->makeOrg();
        $this->seedAiProvider($orgA);
        $orgB = $this->makeOrg();
        $this->seedAiProvider($orgB);

        Http::fake(['*chat/completions*' => Http::sequence()
            ->push($this->content('{"v":"A"}'), 200)
            ->push($this->content('{"v":"B"}'), 200)]);

        // SAME model + SAME prompt across two tenants — only org_id differs.
        $resultA = (new AiService($orgA->id))->ask('system', 'user');
        $resultB = (new AiService($orgB->id))->ask('system', 'user');

        $this->assertSame('A', $resultA['v']);
        // Without per-tenant cache keys this would wrongly return org A's 'A'.
        $this->assertSame('B', $resultB['v']);
        Http::assertSentCount(2);
    }

    public function test_unparseable_response_is_not_cached(): void
    {
        $org = $this->makeOrg();
        $this->seedAiProvider($org);

        Http::fake(['*chat/completions*' => Http::sequence()
            ->push($this->content('totally not json'), 200)   // → ['raw'=>...] fallback
            ->push($this->content('{"ok":true}'), 200)]);      // a later good response

        $ai = new AiService($org->id);
        $first = $ai->ask('system', 'user');
        $second = $ai->ask('system', 'user');

        $this->assertArrayHasKey('raw', $first);
        // The bad response must NOT have been cached → retry hits the provider and parses.
        $this->assertSame(true, $second['ok']);
        Http::assertSentCount(2);
    }
}
