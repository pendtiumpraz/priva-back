<?php

namespace App\Lms\Services;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use MuxPhp\Api\AssetsApi;
use MuxPhp\Configuration;
use MuxPhp\Models\CreateAssetRequest;
use MuxPhp\Models\InputSettings;
use MuxPhp\Models\PlaybackPolicy;

/**
 * Thin wrapper over the Mux API for LMS lesson video (M1).
 *
 * Two responsibilities:
 *   1. Ingest an mp4/HLS URL into Mux as an asset (admin / seeder side).
 *   2. Mint short-lived RS256 playback JWTs for SIGNED playback ids (read side).
 *
 * Credentials live in config('services.mux'). When unset the service reports
 * itself unconfigured so callers can degrade gracefully (YouTube keeps working).
 */
class MuxService
{
    /** Management API (ingest) usable — needs an access token. */
    public function configured(): bool
    {
        return filled(config('services.mux.token_id'))
            && filled(config('services.mux.token_secret'));
    }

    /** Signed playback usable — needs a signing key. */
    public function signingConfigured(): bool
    {
        return filled(config('services.mux.signing_key_id'))
            && filled(config('services.mux.signing_key_private_key'));
    }

    /**
     * Ingest a video URL into Mux and return the created asset + first playback
     * id. $policy is 'signed' (default) or 'public'.
     *
     * @return array{asset_id:string, playback_id:string, policy:string}
     */
    public function ingestFromUrl(string $url, ?string $policy = null): array
    {
        $policy = $policy ?: (string) config('services.mux.default_playback_policy', 'signed');
        $muxPolicy = $policy === 'signed' ? PlaybackPolicy::SIGNED : PlaybackPolicy::_PUBLIC;

        $request = new CreateAssetRequest([
            'input' => new InputSettings(['url' => $url]),
            'playback_policy' => [$muxPolicy],
        ]);

        $asset = $this->assetsApi()->createAsset($request)->getData();
        $playbackIds = $asset->getPlaybackIds() ?? [];

        if (empty($playbackIds)) {
            throw new \RuntimeException('Mux asset created without a playback id: '.$asset->getId());
        }

        return [
            'asset_id' => $asset->getId(),
            'playback_id' => $playbackIds[0]->getId(),
            'policy' => $policy,
        ];
    }

    /**
     * Mint a signed playback JWT for a Mux playback id.
     *
     * $aud: 'v' video, 't' thumbnail, 'g' animated gif, 's' storyboard.
     *
     * @return array{token:string, expires_at:int}
     */
    public function signPlaybackToken(string $playbackId, string $aud = 'v', ?int $ttl = null): array
    {
        if (! $this->signingConfigured()) {
            throw new \RuntimeException('Mux signing key not configured.');
        }

        $ttl = $ttl ?? (int) config('services.mux.playback_token_ttl', 21600);
        $expiresAt = now()->getTimestamp() + $ttl;

        $token = JWT::encode(
            [
                'sub' => $playbackId,
                'aud' => $aud,
                'exp' => $expiresAt,
            ],
            $this->signingPrivateKey(),
            'RS256',
            (string) config('services.mux.signing_key_id'), // -> kid header
        );

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    private function assetsApi(): AssetsApi
    {
        if (! $this->configured()) {
            throw new \RuntimeException('Mux access token not configured.');
        }

        $config = Configuration::getDefaultConfiguration()
            ->setUsername((string) config('services.mux.token_id'))
            ->setPassword((string) config('services.mux.token_secret'));

        return new AssetsApi(new Client(), $config);
    }

    /**
     * Mux returns the signing private key base64-encoded. Decode it to a PEM
     * for php-jwt. If an operator pasted a raw PEM instead, use it as-is.
     */
    private function signingPrivateKey(): string
    {
        $raw = trim((string) config('services.mux.signing_key_private_key'));

        if (str_contains($raw, 'BEGIN')) {
            return $raw;
        }

        $decoded = base64_decode($raw, true);

        return $decoded !== false ? $decoded : $raw;
    }
}
