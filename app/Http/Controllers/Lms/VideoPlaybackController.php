<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Lms\Models\Video;
use App\Lms\Services\MuxService;
use Illuminate\Http\JsonResponse;

/**
 * Mints short-lived signed playback JWTs for Mux SIGNED videos (M1).
 *
 * The video catalog (lms_videos) is global and the player only ever sees a
 * playback id, never the signing key. The frontend calls this once before
 * playback (and again if the token expires) and hands the JWT to Mux Player.
 *
 * Gated by the LMS route group (auth:sanctum + lms.entitled). Public-policy
 * Mux videos and YouTube don't need a token, so they 422 here.
 */
class VideoPlaybackController extends Controller
{
    public function __construct(private MuxService $mux) {}

    public function token(Video $video): JsonResponse
    {
        if ($video->source !== 'mux' || $video->playback_policy !== 'signed') {
            return response()->json([
                'error' => 'This video does not use signed playback.',
                'code' => 'LMS_VIDEO_NOT_SIGNED',
            ], 422);
        }

        if (! $this->mux->signingConfigured()) {
            return response()->json([
                'error' => 'Mux signed playback is not configured on this server.',
                'code' => 'LMS_MUX_NOT_CONFIGURED',
            ], 503);
        }

        $signed = $this->mux->signPlaybackToken($video->external_id, 'v');

        return response()->json([
            'playback_id' => $video->external_id,
            'token' => $signed['token'],
            'expires_at' => $signed['expires_at'],
        ]);
    }
}
