<?php

namespace App\Lms\Services;

use App\Models\AppSetting;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Crypt;
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
 * Credentials are platform-global, managed by root/superadmin via Platform
 * Config (AppSetting keys `lms.mux.*`), falling back to config('services.mux')
 * (.env) for self-hosted setups. When unset the service reports itself
 * unconfigured so callers degrade gracefully (YouTube keeps working).
 */
class MuxService
{
    /** AppSetting keys whose value is stored encrypted (Crypt). */
    private const SECRET_KEYS = ['token_secret', 'signing_key_private_key'];

    /** AppSetting key prefix for Mux platform config. */
    public const PREFIX = 'lms.mux.';

    /**
     * Resolve a Mux setting: AppSetting (DB, root-managed) first — decrypting
     * secret keys — then config('services.mux.*') (.env) as a fallback.
     */
    private function cfg(string $key, $default = null)
    {
        // DB-resilient: if app_settings is unavailable (e.g. a unit test without
        // migrations, or DB down), fall back to config('services.mux') (.env)
        // instead of throwing.
        try {
            $stored = AppSetting::get(self::PREFIX.$key);
        } catch (\Throwable $e) {
            $stored = null;
        }
        if (filled($stored)) {
            if (in_array($key, self::SECRET_KEYS, true)) {
                try {
                    return Crypt::decryptString($stored);
                } catch (\Throwable $e) {
                    return $stored; // tolerate a legacy plaintext value
                }
            }

            return $stored;
        }

        return config('services.mux.'.$key, $default);
    }

    /** Management API (ingest) usable — needs an access token. */
    public function configured(): bool
    {
        return filled($this->cfg('token_id')) && filled($this->cfg('token_secret'));
    }

    /** Signed playback usable — needs a signing key. */
    public function signingConfigured(): bool
    {
        return filled($this->cfg('signing_key_id')) && filled($this->cfg('signing_key_private_key'));
    }

    /**
     * Ingest a video URL into Mux and return the created asset + first playback
     * id. $policy is 'signed' (default) or 'public'.
     *
     * @return array{asset_id:string, playback_id:string, policy:string}
     */
    public function ingestFromUrl(string $url, ?string $policy = null): array
    {
        $policy = $policy ?: (string) $this->cfg('default_playback_policy', 'signed');
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

        $ttl = $ttl ?? (int) $this->cfg('playback_token_ttl', 21600);
        $expiresAt = now()->getTimestamp() + $ttl;

        $token = JWT::encode(
            [
                'sub' => $playbackId,
                'aud' => $aud,
                'exp' => $expiresAt,
            ],
            $this->signingPrivateKey(),
            'RS256',
            (string) $this->cfg('signing_key_id'), // -> kid header
        );

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    private function assetsApi(): AssetsApi
    {
        if (! $this->configured()) {
            throw new \RuntimeException('Mux access token not configured.');
        }

        $config = Configuration::getDefaultConfiguration()
            ->setUsername((string) $this->cfg('token_id'))
            ->setPassword((string) $this->cfg('token_secret'));

        return new AssetsApi(new Client, $config);
    }

    /**
     * Mux returns the signing private key base64-encoded. Decode it to a PEM
     * for php-jwt. If an operator pasted a raw PEM instead, use it as-is.
     */
    private function signingPrivateKey(): string
    {
        $raw = trim((string) $this->cfg('signing_key_private_key'));

        if (str_contains($raw, 'BEGIN')) {
            return $raw;
        }

        $decoded = base64_decode($raw, true);

        return $decoded !== false ? $decoded : $raw;
    }
}
