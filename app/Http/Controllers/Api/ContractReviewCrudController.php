<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AssessmentPdfService;
use App\Services\ContractReviewLinker;
use App\Support\ContractReviewScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContractReviewCrudController extends Controller
{
    /**
     * Query dasar `contract_reviews` untuk pemanggil request ini: batas tenant
     * DAN batas divisi sekaligus.
     *
     * SEMUA jalur di controller ini lewat sini — termasuk hapus, pulihkan, dan
     * hapus permanen. Telaah divisi lain karena itu tidak hanya tak terbaca,
     * tapi juga tak bisa disentuh; kalau tak ketemu, jatuhnya 404 yang sama
     * dengan milik org lain, jadi keberadaannya pun tidak bocor.
     */
    private function ruang(Request $request)
    {
        $orgId = $request->user()->org_id;

        $query = DB::table('contract_reviews')->where('org_id', $orgId);
        ContractReviewScope::terapkan($query, $request->user(), $orgId);

        return $query;
    }

    public function index(Request $request)
    {
        $data = $this->ruang($request)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function trashed(Request $request)
    {
        $data = $this->ruang($request)
            ->whereNotNull('deleted_at')
            ->orderBy('deleted_at', 'desc')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, string $id)
    {
        $item = $this->ruang($request)
            ->where('id', $id)
            ->first();

        if (! $item) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Parse JSON fields
        $item->review_result = is_string($item->review_result) ? json_decode($item->review_result, true) : $item->review_result;

        // Tautan balik ke Document Maker. Judul di-resolve dari
        // generated_documents dan tetap di-scope org — record lama yang
        // source_document_id-nya NULL cukup dapat null tanpa query.
        $item->source_link = $this->sourceLink($item, $request->user()->org_id);
        // Dipertahankan demi pemanggil lama; isinya kini ikut sumber yang benar.
        $item->source_document_title = $item->source_link['title'] ?? null;

        return response()->json(['data' => $item]);
    }

    /**
     * Ke mana telaah ini menunjuk balik, dan — bila berasal dari TPRM — milik
     * pihak ketiga yang mana.
     *
     * Sebelumnya sumbernya SELALU dicari di `generated_documents`, padahal
     * `source_module` bisa juga `vendor_contract`: kontrak pihak ketiga yang
     * dikirim ke telaah oleh ContractReviewLinker, baik lewat tombol "Kirim ke
     * Telaah" maupun unggahan pihak ketiga lewat tautan publik. Akibatnya telaah
     * dari TPRM tidak pernah menemukan judulnya, dan tombol "Dokumen Sumber" di
     * UI menavigasi ke Document Maker memakai id `vendor_contracts` — tautan
     * yang pasti rusak, dan tidak ada satu pun petunjuk kontrak itu milik siapa.
     *
     * @return array<string, mixed>|null
     */
    private function sourceLink(object $item, string $orgId): ?array
    {
        if (empty($item->source_document_id)) {
            return null;
        }

        if (($item->source_module ?? null) === ContractReviewLinker::SOURCE_MODULE) {
            $kontrak = DB::table('vendor_contracts')
                ->where('id', $item->source_document_id)
                ->where('org_id', $orgId)
                ->whereNull('deleted_at')
                ->first(['id', 'title', 'vendor_id']);

            if (! $kontrak) {
                return null;
            }

            // Pihak ketiganya dicari terpisah dan tetap di-scope org: kontrak
            // boleh saja menunjuk pihak yang sudah dihapus, dan telaahnya tetap
            // harus terbuka — hanya tanpa nama pemiliknya.
            $pihak = DB::table('vendors')
                ->where('id', $kontrak->vendor_id)
                ->where('org_id', $orgId)
                ->whereNull('deleted_at')
                ->first(['id', 'name']);

            return [
                'module' => ContractReviewLinker::SOURCE_MODULE,
                'title' => $kontrak->title,
                'href' => $pihak ? '/vendor-risk?open='.$pihak->id : '/vendor-risk/kontrak',
                'pihak_ketiga' => $pihak ? ['id' => $pihak->id, 'name' => $pihak->name] : null,
            ];
        }

        $doc = $this->sourceDocument($item, $orgId);

        return $doc ? [
            'module' => 'document_maker',
            'title' => $doc->title,
            'href' => '/document-maker/'.$item->source_document_id,
            'pihak_ketiga' => null,
        ] : null;
    }

    /**
     * GET /contract-reviews/{id}/export.pdf
     *
     * Ekspor hasil review sebagai PDF ber-branding tenant (DocumentTemplate)
     * lewat AssessmentPdfService — infrastruktur yang sama dengan LIA/TIA/
     * Maturity. Org scoping sama persis dengan show(): row milik org lain
     * tidak pernah ditemukan sehingga jatuh ke 404.
     */
    public function exportPdf(Request $request, AssessmentPdfService $pdf, string $id)
    {
        $orgId = $request->user()->org_id;

        $item = $this->ruang($request)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (! $item) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $source = $this->sourceDocument($item, $orgId);

        $slug = Str::slug((string) ($item->title ?? 'contract-review')) ?: 'contract-review';
        $filename = 'Review_Kontrak_'.$slug.'.pdf';

        AuditLog::log('contract_review', (string) $item->id, 'pdf_exported', [
            'filename' => $filename,
        ], 'manual');

        return $pdf->contractReview($item, $request->user(), $source)->download($filename);
    }

    /**
     * Dokumen Document Maker asal review ini (kalau ada), ter-scope org.
     */
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

    public function destroy(Request $request, string $id)
    {
        $affected = $this->ruang($request)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        if (! $affected) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['message' => 'Moved to trash']);
    }

    public function restore(Request $request, string $id)
    {
        $affected = $this->ruang($request)
            ->where('id', $id)
            ->whereNotNull('deleted_at')
            ->update(['deleted_at' => null]);

        if (! $affected) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['message' => 'Restored']);
    }

    public function forceDelete(Request $request, string $id)
    {
        $affected = $this->ruang($request)
            ->where('id', $id)
            ->delete();

        if (! $affected) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['message' => 'Permanently deleted']);
    }
}
