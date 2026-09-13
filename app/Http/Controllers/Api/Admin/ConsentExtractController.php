<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\PushExtractToCrmJob;
use App\Models\ConsentItem;
use App\Models\ConsentLog;
use App\Models\ExtractRun;
use App\Services\Consent\ConsentBulkGate;
use App\Services\Consent\ConsentBulkVerdict;
use App\Services\Consent\ExtractQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CRM Extractor — pull identifiable consent_logs filtered by purpose,
 * date, source_form, country. Output: CSV download or async push to
 * HubSpot/Salesforce/Mailchimp/webhook (latter three are stubs in this
 * phase; CSV is the only fully-implemented target).
 */
class ConsentExtractController extends Controller
{
    public function __construct(
        private ExtractQuery $extractQuery,
        private ConsentBulkGate $bulkGate,
    ) {}

    /**
     * Preview count + sample without committing — used by the wizard
     * "matching records: 1,234" indicator.
     */
    public function preview(Request $request)
    {
        $orgId = $request->user()->org_id;
        if (! $orgId) {
            return response()->json(['error' => 'No org context'], 403);
        }

        $filters = $this->validateFilters($request);
        $q = $this->buildQuery($orgId, $filters);

        $count = (clone $q)->count();

        // Pratinjau menimbang dengan gerbang yang SAMA dengan jalan
        // sesungguhnya. Kalau tidak, angka "1.234 cocok" akan berbohong tepat
        // pada jalan yang aturannya benar-benar memotong sebagian.
        $verdict = $this->bulkGate->timbang($orgId, $q, $filters['segment'] ?? null);
        $ringkasan = $verdict->adaPenjagaan() ? $verdict->ringkasan() : null;

        $sample = (clone $q)->orderByDesc('created_at')->limit(5)->get([
            'id', 'email', 'name', 'phone', 'source_form', 'collection_id', 'purpose_keys', 'created_at',
        ]);

        $sample->each(fn ($r) => $r->setAttribute(
            'withheld_by_rules',
            ! $verdict->izinkan($r->collection_id, $r->email)
        ));

        // Attach resolved purpose titles (purpose_keys holds item UUIDs) so the
        // wizard sample shows item names, not IDs.
        $titleById = ConsentItem::titleMap($sample->pluck('collection_id')->unique()->all());
        $sample->each(function ($r) use ($titleById) {
            $r->setAttribute('purpose_titles', array_map(
                fn ($k) => $titleById[$k] ?? $k,
                $r->purpose_keys ?? []
            ));
        });

        return response()->json([
            'data' => [
                'count' => $count,
                'sample' => $sample,
                'filters_applied' => $filters,
                'gate' => $ringkasan,
            ],
        ]);
    }

    /**
     * Execute extract. Returns CSV stream OR creates an ExtractRun record
     * and returns its id for async CRM push.
     */
    public function run(Request $request)
    {
        $orgId = $request->user()->org_id;
        if (! $orgId) {
            return response()->json(['error' => 'No org context'], 403);
        }

        $filters = $this->validateFilters($request);
        $target = $request->input('output_target', 'csv');
        if (! in_array($target, ExtractRun::TARGETS, true)) {
            return response()->json(['error' => 'Invalid output_target'], 422);
        }

        $q = $this->buildQuery($orgId, $filters);
        $count = (clone $q)->count();

        // Ditimbang SEBELUM apa pun dikirim. Pada jalur CSV, galat evaluasi di
        // tengah aliran unduhan tidak bisa ditarik kembali — berkasnya sudah
        // separuh sampai di peramban.
        $verdict = $this->bulkGate->timbang($orgId, $q, $filters['segment'] ?? null);

        $run = ExtractRun::create([
            'org_id' => $orgId,
            'initiated_by_user_id' => Auth::id(),
            'source' => 'consent_logs',
            'filters' => $filters,
            'output_target' => $target,
            'output_target_ref' => $request->input('output_target_ref'),
            // `record_count` tetap berarti "cocok dengan penapis", seperti
            // sebelumnya. Berapa yang benar-benar terkirim ada di gate_summary,
            // supaya arti kolom lama tidak berubah diam-diam bagi pembacanya.
            'record_count' => $count,
            'gate_summary' => $verdict->adaPenjagaan() ? $verdict->ringkasan() : null,
            'status' => $target === 'csv' ? ExtractRun::STATUS_DONE : ExtractRun::STATUS_PENDING,
            'started_at' => now(),
            'finished_at' => $target === 'csv' ? now() : null,
        ]);

        $this->bulkGate->catatYangDitahan($orgId, $verdict, $run->id);

        if ($target === 'csv') {
            return $this->streamCsv($q, $run, $verdict);
        }

        // Async target — dispatch CRM push job. The job re-loads filters from
        // the run row, resolves credentials per org+provider, calls the
        // connector, and writes back success/failure counts + status.
        PushExtractToCrmJob::dispatch($run->id)->afterCommit();

        return response()->json([
            'data' => [
                'run_id' => $run->id,
                'status' => $run->status,
                'count' => $count,
                'note' => 'Push job dispatched. Poll /api/consent-extract/runs for status.',
            ],
        ], 202);
    }

    /**
     * List past extract runs (audit trail).
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        $perPage = min(50, max(10, (int) $request->input('per_page', 20)));

        $page = ExtractRun::query()
            ->where('org_id', $orgId)
            ->with('initiator:id,name,email')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($page);
    }

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'collection_id' => 'nullable|string|max:200',
            'purpose_keys' => 'nullable|array',
            'purpose_keys.*' => 'string|max:60',
            'source_form' => 'nullable|string|max:40',
            'country' => 'nullable|string|size:2',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            // Segmen yang dituju ekstrak ini. Tanpa ini, aturan yang berbentuk
            // "kecualikan dari segmen X" tidak punya X untuk dibandingkan, dan
            // yang berlaku hanya larangan penuh.
            'segment' => 'nullable|string|max:191',
        ]);
    }

    /**
     * Penyusunan kueri dipusatkan di ExtractQuery — dipakai bersama dengan
     * PushExtractToCrmJob, yang dulu menyalinnya. Penapis yang berbeda antara
     * pratinjau dan dorongan CRM berarti orang yang dilihat operator di layar
     * bukan orang yang benar-benar terkirim.
     *
     * @param  array<string,mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Builder<ConsentLog>
     */
    private function buildQuery(string $orgId, array $filters)
    {
        return $this->extractQuery->build($orgId, $filters);
    }

    private function streamCsv($query, ExtractRun $run, ConsentBulkVerdict $verdict): StreamedResponse
    {
        $filename = sprintf('consent-extract-%s-%s.csv', $run->id, now()->format('Ymd-His'));

        // Resolve item UUIDs → titles once (purpose_keys holds item UUIDs).
        $collectionIds = (clone $query)->distinct()->pluck('collection_id')->all();
        $titleById = ConsentItem::titleMap($collectionIds);

        return response()->streamDownload(function () use ($query, $titleById, $verdict) {
            $out = fopen('php://output', 'w');
            // BOM for Excel UTF-8
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'email', 'name', 'phone', 'source_form', 'purposes', 'country', 'captured_at']);
            $query->orderBy('created_at')->chunk(500, function ($rows) use ($out, $titleById, $verdict) {
                foreach ($rows as $r) {
                    // Baris yang ditahan aturan tidak pernah masuk berkas.
                    // Berkas CSV berpindah tangan dengan bebas; sekali ia
                    // memuat orang yang seharusnya dikecualikan, tidak ada lagi
                    // cara menariknya kembali.
                    if (! $verdict->izinkan($r->collection_id, $r->email)) {
                        continue;
                    }
                    $purposes = is_array($r->purpose_keys)
                        ? implode('|', array_map(fn ($k) => $titleById[$k] ?? $k, $r->purpose_keys))
                        : '';
                    fputcsv($out, [
                        $r->id,
                        $r->email,
                        $r->name,
                        $r->phone,
                        $r->source_form,
                        $purposes,
                        $r->ip_country,
                        $r->created_at?->toIso8601String(),
                    ]);
                }
            });
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
