<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeatureRequest;
use Illuminate\Http\Request;

class FeatureRequestController extends Controller
{
    /**
     * Public list — no auth required, read-only
     */
    public function publicIndex()
    {
        // TANPA autentikasi. Sebelumnya mengembalikan seluruh baris berikut
        // relasi user (id, name, email) — artinya nama dan surel pengguna dari
        // SEMUA tenant terbuka ke internet anonim, begitu pula guest_email
        // pengirim publik.
        //
        // Yang keluar sekarang hanya kolom yang memang layak dipajang di papan
        // usulan publik. Identitas pengusul tidak termasuk.
        $requests = FeatureRequest::query()
            ->orderByDesc('votes')
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'description', 'category', 'status', 'priority', 'votes', 'created_at']);

        return response()->json(['data' => $requests]);
    }

    /**
     * Public submit — no auth, uses guest name/email
     */
    public function publicStore(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'required|in:module,ui,integration,security,performance,other',
            'guest_name' => 'required|string|max:100',
            'guest_email' => 'required|email|max:150',
        ]);

        $fr = FeatureRequest::create([
            'title' => $request->title,
            'description' => $request->description,
            'category' => $request->category,
            'priority' => 'medium',
            'status' => 'submitted',
            'guest_name' => $request->guest_name,
            'guest_email' => $request->guest_email,
        ]);

        return response()->json([
            'message' => 'Feature request submitted! Terima kasih atas masukannya.',
            'data' => $fr,
        ], 201);
    }

    /**
     * List feature requests (user sees own org, admin sees all)
     */
    public function index(Request $request)
    {
        $query = FeatureRequest::with('user:id,name,email,role');

        // Hanya STAF PLATFORM yang melihat lintas tenant. Sebelumnya syaratnya
        // `role !== 'admin'`, padahal admin TENANT juga ber-role 'admin' —
        // sehingga mereka melihat usulan seluruh tenant lengkap dengan nama dan
        // surel pengusulnya.
        if (! in_array($request->user()->role, ['root', 'superadmin'], true)) {
            $query->where('org_id', $request->user()->org_id);
        }

        if ($request->get('trash')) {
            $query->onlyTrashed();
        }

        if ($request->get('status')) {
            $query->where('status', $request->get('status'));
        }

        $requests = $query->orderBy('created_at', 'desc')->get();

        return response()->json(['data' => $requests]);
    }

    /**
     * Submit a feature request
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'required|in:module,ui,integration,security,performance,other',
            'priority' => 'sometimes|in:low,medium,high,critical',
        ]);

        $fr = FeatureRequest::create([
            'org_id' => $request->user()->org_id,
            'user_id' => $request->user()->id,
            'title' => $request->title,
            'description' => $request->description,
            'category' => $request->category,
            'priority' => $request->priority ?? 'medium',
            'status' => 'submitted',
        ]);

        return response()->json([
            'message' => 'Feature request submitted!',
            'data' => $fr->load('user:id,name,email,role'),
        ], 201);
    }

    /**
     * Show detail
     */
    public function show(Request $request, string $id)
    {
        $fr = $this->findForActor($request, $id);

        return response()->json(['data' => $fr->load('user:id,name,email,role')]);
    }

    /**
     * Cari satu usulan dengan batas tenant ditegakkan.
     *
     * Sebelumnya show/update/destroy/restore/forceDelete memanggil findOrFail()
     * telanjang — bahkan tanpa menerima Request, sehingga tidak mungkin memeriksa
     * apa pun. Akibatnya siapa pun yang terautentikasi bisa membaca, mengubah,
     * membuang, bahkan MENGHAPUS PERMANEN usulan milik tenant lain hanya dengan
     * menebak id-nya.
     */
    private function findForActor(Request $request, string $id, bool $trashed = false): FeatureRequest
    {
        $query = $trashed ? FeatureRequest::onlyTrashed() : FeatureRequest::query();

        if (! in_array($request->user()->role, ['root', 'superadmin'], true)) {
            $query->where('org_id', $request->user()->org_id);
        }

        return $query->findOrFail($id);
    }

    /** Perubahan status/catatan adalah keputusan PLATFORM, bukan tenant. */
    private function denyIfNotPlatformStaff(Request $request)
    {
        if (! in_array($request->user()->role, ['root', 'superadmin'], true)) {
            return response()->json(['message' => 'Akses ditolak — hanya staf platform.'], 403);
        }

        return null;
    }

    /**
     * Admin update status & notes
     */
    public function update(Request $request, string $id)
    {
        if ($denied = $this->denyIfNotPlatformStaff($request)) {
            return $denied;
        }

        $fr = FeatureRequest::findOrFail($id);

        $request->validate([
            'status' => 'sometimes|in:submitted,reviewing,planned,in_progress,completed,rejected',
            'admin_notes' => 'sometimes|nullable|string',
            'priority' => 'sometimes|in:low,medium,high,critical',
        ]);

        $fr->update($request->only(['status', 'admin_notes', 'priority']));

        return response()->json([
            'message' => 'Feature request updated',
            'data' => $fr->fresh()->load('user:id,name,email,role'),
        ]);
    }

    /**
     * Upvote
     */
    public function upvote(string $id)
    {
        $fr = FeatureRequest::findOrFail($id);
        $fr->increment('votes');

        return response()->json([
            'message' => 'Voted!',
            'data' => $fr->fresh(),
        ]);
    }

    /**
     * Soft delete
     */
    public function destroy(Request $request, string $id)
    {
        $this->findForActor($request, $id)->delete();

        return response()->json(['message' => 'Moved to trash']);
    }

    /**
     * Restore
     */
    public function restore(Request $request, string $id)
    {
        $fr = $this->findForActor($request, $id, trashed: true);
        $fr->restore();

        return response()->json(['message' => 'Restored', 'data' => $fr]);
    }

    /**
     * Force delete — penghapusan permanen, hanya staf platform.
     */
    public function forceDelete(Request $request, string $id)
    {
        if ($denied = $this->denyIfNotPlatformStaff($request)) {
            return $denied;
        }

        FeatureRequest::onlyTrashed()->findOrFail($id)->forceDelete();

        return response()->json(['message' => 'Permanently deleted']);
    }
}
