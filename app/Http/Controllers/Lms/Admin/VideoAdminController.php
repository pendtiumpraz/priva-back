<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\Admin\StoreVideoRequest;
use App\Lms\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin endpoints for the Video catalog.
 *
 * Schema notes (vs spec §3.8):
 *  - lms_videos has no `title` column. Picker UI uses (source, external_id).
 *  - lms_videos has no `org_id` column — videos are a global catalog. Any
 *    content_admin can create/list. Mutation is gated by the permission
 *    middleware on the route group.
 */
class VideoAdminController extends Controller
{
    /**
     * GET /admin/videos
     * Lightweight list for the picker UX. Supports `?search=` against
     * `external_id` (no title column to search). Returns flat { data: [...] }
     * (no pagination meta — picker fetches the small page).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Video::query()->orderByDesc('id');

        if ($search = $request->query('search')) {
            $query->where('external_id', 'like', "%{$search}%");
        }

        $videos = $query->limit(50)->get();

        return response()->json([
            'data' => $videos->map(fn (Video $v) => $this->toResource($v))->all(),
        ]);
    }

    /**
     * POST /admin/videos
     */
    public function store(StoreVideoRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $video = Video::create([
            'source'           => $data['source'],
            'external_id'      => $data['external_id'],
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'uploaded_by'      => $user->id ?? null,
        ]);

        return response()->json([
            'data' => $this->toResource($video->fresh()),
        ], 201);
    }

    protected function toResource(Video $video): array
    {
        return [
            'id'               => $video->id,
            'source'           => $video->source,
            'external_id'      => $video->external_id,
            'duration_seconds' => $video->duration_seconds,
            'uploaded_by'      => $video->uploaded_by,
            'created_at'       => $video->created_at?->toIso8601String(),
        ];
    }
}
