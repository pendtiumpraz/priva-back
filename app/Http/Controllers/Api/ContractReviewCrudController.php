<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContractReviewCrudController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        $data = DB::table('contract_reviews')
            ->where('org_id', $orgId)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function trashed(Request $request)
    {
        $orgId = $request->user()->org_id;
        $data = DB::table('contract_reviews')
            ->where('org_id', $orgId)
            ->whereNotNull('deleted_at')
            ->orderBy('deleted_at', 'desc')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, string $id)
    {
        $item = DB::table('contract_reviews')
            ->where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->first();

        if (!$item) return response()->json(['message' => 'Not found'], 404);

        // Parse JSON fields
        $item->review_result = is_string($item->review_result) ? json_decode($item->review_result, true) : $item->review_result;

        return response()->json(['data' => $item]);
    }

    public function destroy(Request $request, string $id)
    {
        $affected = DB::table('contract_reviews')
            ->where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        if (!$affected) return response()->json(['message' => 'Not found'], 404);
        return response()->json(['message' => 'Moved to trash']);
    }

    public function restore(Request $request, string $id)
    {
        $affected = DB::table('contract_reviews')
            ->where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->whereNotNull('deleted_at')
            ->update(['deleted_at' => null]);

        if (!$affected) return response()->json(['message' => 'Not found'], 404);
        return response()->json(['message' => 'Restored']);
    }

    public function forceDelete(Request $request, string $id)
    {
        $affected = DB::table('contract_reviews')
            ->where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->delete();

        if (!$affected) return response()->json(['message' => 'Not found'], 404);
        return response()->json(['message' => 'Permanently deleted']);
    }
}
