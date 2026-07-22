<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AssessmentPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PolicyReviewCrudController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        $data = DB::table('policy_reviews')
            ->where('org_id', $orgId)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function trashed(Request $request)
    {
        $orgId = $request->user()->org_id;
        $data = DB::table('policy_reviews')
            ->where('org_id', $orgId)
            ->whereNotNull('deleted_at')
            ->orderBy('deleted_at', 'desc')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, string $id)
    {
        $item = DB::table('policy_reviews')
            ->where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->first();

        if (! $item) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $item->review_result = is_string($item->review_result) ? json_decode($item->review_result, true) : $item->review_result;

        // Tautan balik ke Document Maker (nullable — record lama tetap null).
        $item->source_document_title = $this->sourceDocumentTitle($item, $request->user()->org_id);

        return response()->json(['data' => $item]);
    }

    /**
     * GET /policy-reviews/{id}/export.pdf
     *
     * PDF ber-branding tenant via AssessmentPdfService (pola sama dengan
     * LIA/TIA/Maturity). Org scoping identik dengan show().
     */
    public function exportPdf(Request $request, AssessmentPdfService $pdf, string $id)
    {
        $orgId = $request->user()->org_id;

        $item = DB::table('policy_reviews')
            ->where('id', $id)
            ->where('org_id', $orgId)
            ->whereNull('deleted_at')
            ->first();

        if (! $item) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $source = $this->sourceDocument($item, $orgId);

        $slug = Str::slug((string) ($item->title ?? 'policy-review')) ?: 'policy-review';
        $filename = 'Review_Kebijakan_'.$slug.'.pdf';

        AuditLog::log('policy_review', (string) $item->id, 'pdf_exported', [
            'filename' => $filename,
        ], 'manual');

        return $pdf->policyReview($item, $request->user(), $source)->download($filename);
    }

    private function sourceDocument(object $item, string $orgId): ?object
    {
        if (empty($item->source_document_id)) {
            return null;
        }

        return DB::table('generated_documents')
            ->where('id', $item->source_document_id)
            ->where('org_id', $orgId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function sourceDocumentTitle(object $item, string $orgId): ?string
    {
        $doc = $this->sourceDocument($item, $orgId);

        return $doc->title ?? null;
    }

    public function destroy(Request $request, string $id)
    {
        $affected = DB::table('policy_reviews')
            ->where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        if (! $affected) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['message' => 'Moved to trash']);
    }

    public function restore(Request $request, string $id)
    {
        $affected = DB::table('policy_reviews')
            ->where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->whereNotNull('deleted_at')
            ->update(['deleted_at' => null]);

        if (! $affected) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['message' => 'Restored']);
    }

    public function forceDelete(Request $request, string $id)
    {
        $affected = DB::table('policy_reviews')
            ->where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->delete();

        if (! $affected) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['message' => 'Permanently deleted']);
    }
}
