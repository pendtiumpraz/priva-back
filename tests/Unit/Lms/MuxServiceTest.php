<?php

namespace Tests\Unit\Lms;

use App\Lms\Services\MuxService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Tests\TestCase;

/**
 * Unit coverage for MuxService::signPlaybackToken — the credential-independent
 * half of M1 (a throwaway RSA key stands in for the real Mux signing key).
 */
class MuxServiceTest extends TestCase
{
    private string $privatePem;

    private string $publicPem;

    protected function setUp(): void
    {
        parent::setUp();
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privatePem);
        $this->privatePem = $privatePem;
        $this->publicPem = openssl_pkey_get_details($res)['key'];
    }

    private function configureSigning(string $privateKeyValue): void
    {
        config([
            'services.mux.signing_key_id' => 'kid-123',
            'services.mux.signing_key_private_key' => $privateKeyValue,
            'services.mux.playback_token_ttl' => 21600,
        ]);
    }

    /** Header carries the kid; payload carries sub/aud; signature verifies. */
    public function test_signs_token_with_kid_header_and_claims(): void
    {
        $this->configureSigning($this->privatePem);

        $out = (new MuxService())->signPlaybackToken('plbk_abc');

        [$headerB64] = explode('.', $out['token']);
        $header = json_decode(base64_decode(strtr($headerB64, '-_', '+/')), true);
        $this->assertSame('kid-123', $header['kid']);
        $this->assertSame('RS256', $header['alg']);

        $decoded = JWT::decode($out['token'], new Key($this->publicPem, 'RS256'));
        $this->assertSame('plbk_abc', $decoded->sub);
        $this->assertSame('v', $decoded->aud);
        $this->assertEqualsWithDelta(now()->getTimestamp() + 21600, $out['expires_at'], 5);
    }

    /** Mux hands back the private key base64-encoded; it must be decoded to PEM. */
    public function test_accepts_base64_encoded_private_key(): void
    {
        $this->configureSigning(base64_encode($this->privatePem));

        $out = (new MuxService())->signPlaybackToken('plbk_b64');

        $decoded = JWT::decode($out['token'], new Key($this->publicPem, 'RS256'));
        $this->assertSame('plbk_b64', $decoded->sub);
    }

    /** Custom aud + ttl are honoured. */
    public function test_custom_aud_and_ttl(): void
    {
        $this->configureSigning($this->privatePem);

        $out = (new MuxService())->signPlaybackToken('plbk_thumb', 't', 60);

        $decoded = JWT::decode($out['token'], new Key($this->publicPem, 'RS256'));
        $this->assertSame('t', $decoded->aud);
        $this->assertEqualsWithDelta(now()->getTimestamp() + 60, $out['expires_at'], 5);
    }

    public function test_throws_when_signing_not_configured(): void
    {
        config(['services.mux.signing_key_id' => null, 'services.mux.signing_key_private_key' => null]);

        $this->expectException(\RuntimeException::class);
        (new MuxService())->signPlaybackToken('plbk_x');
    }
}
