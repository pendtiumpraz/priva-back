<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DsrInboundChannel;
use App\Models\DsrOutboundTarget;
use App\Models\DsrRequest;
use App\Services\DsrDeliveryService;
use App\Services\DsrIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kanal keluar dan masuk permohonan subjek data.
 *
 * Endpoint publik `inbound` sengaja tidak berada di controller ini melainkan
 * di DsrInboundPublicController, agar tidak ada satu pun jalur tanpa
 * otentikasi yang berbagi berkas dengan jalur yang membutuhkannya.
 */
class DsrChannelController extends Controller
{
    public function __construct(
        private readonly DsrDeliveryService $delivery,
        private readonly DsrIntakeService $intake,
    ) {}

    // ================= Tujuan keluar =================

    public function indexTargets(Request $request): JsonResponse
    {
        return response()->json([
            'data' => DsrOutboundTarget::orderBy('name')->get(),
            'meta' => [
                'formats' => DsrOutboundTarget::FORMATS,
                'events' => DsrOutboundTarget::EVENTS,
            ],
        ]);
    }

    public function storeTarget(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'url' => 'required|url|max:500',
            'payload_format' => 'required|in:'.implode(',', DsrOutboundTarget::FORMATS),
            'auth_header' => 'nullable|string|max:1000',
            'events' => 'nullable|array',
            'events.*' => 'in:'.implode(',', DsrOutboundTarget::EVENTS),
            'timeout_seconds' => 'nullable|integer|min:1|max:60',
            'retry_count' => 'nullable|integer|min:0|max:5',
            'is_active' => 'nullable|boolean',
        ]);

        $target = DsrOutboundTarget::create(array_merge($data, [
            'org_id' => $request->user()->org_id,
            'created_by' => $request->user()->id,
            'is_active' => $data['is_active'] ?? true,
        ]));

        AuditLog::log('dsr', $target->id, 'outbound_target_created', [
            'name' => $target->name,
            'format' => $target->payload_format,
        ], 'delivery');

        return response()->json(['data' => $target], 201);
    }

    public function updateTarget(Request $request, string $id): JsonResponse
    {
        $target = DsrOutboundTarget::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:150',
            'url' => 'sometimes|url|max:500',
            'payload_format' => 'sometimes|in:'.implode(',', DsrOutboundTarget::FORMATS),
            'auth_header' => 'sometimes|nullable|string|max:1000',
            'events' => 'sometimes|nullable|array',
            'events.*' => 'in:'.implode(',', DsrOutboundTarget::EVENTS),
            'timeout_seconds' => 'sometimes|integer|min:1|max:60',
            'retry_count' => 'sometimes|integer|min:0|max:5',
            'is_active' => 'sometimes|boolean',
        ]);
        $target->update($data);

        return response()->json(['data' => $target->fresh()]);
    }

    public function destroyTarget(Request $request, string $id): JsonResponse
    {
        $target = DsrOutboundTarget::findOrFail($id);
        $target->delete();

        return response()->json(['message' => 'Tujuan pengiriman dihapus.']);
    }

    // ================= Pengiriman =================

    /** Kirim laporan hasil permohonan ke pemohon lewat surel. */
    public function sendReport(Request $request, string $id): JsonResponse
    {
        $dsr = DsrRequest::findOrFail($id);
        $data = $request->validate([
            'body' => 'nullable|string|max:5000',
            'attach_pdf' => 'nullable|boolean',
        ]);

        $result = $this->delivery->emailReport(
            $dsr,
            $data['body'] ?? null,
            $data['attach_pdf'] ?? true,
        );

        return response()->json([
            'message' => $result['sent']
                ? 'Laporan dikirim ke '.$result['to'].'.'
                : 'Pengiriman gagal: '.$result['error'],
            'data' => $result,
        ], $result['sent'] ? 200 : 422);
    }

    /** Kirim permohonan ke sistem luar lewat API. */
    public function push(Request $request, string $id): JsonResponse
    {
        $dsr = DsrRequest::findOrFail($id);
        $data = $request->validate([
            'event' => 'nullable|in:'.implode(',', DsrOutboundTarget::EVENTS),
            'target_id' => 'nullable|uuid',
        ]);

        $results = $this->delivery->pushToTargets(
            $dsr,
            $data['event'] ?? 'dsr.completed',
            $data['target_id'] ?? null,
        );

        if (empty($results)) {
            return response()->json([
                'message' => 'Tidak ada tujuan pengiriman aktif untuk peristiwa ini.',
                'data' => [],
            ], 422);
        }

        $ok = count(array_filter($results, fn ($r) => $r['ok']));

        return response()->json([
            'message' => "{$ok} dari ".count($results).' tujuan berhasil menerima.',
            'data' => $results,
        ]);
    }

    /** Pratinjau muatan tanpa mengirimkannya. */
    public function previewPayload(Request $request, string $id): JsonResponse
    {
        $dsr = DsrRequest::findOrFail($id);
        $format = $request->input('format', 'generic');
        if (! in_array($format, DsrOutboundTarget::FORMATS, true)) {
            return response()->json(['message' => 'Format tidak dikenali.'], 422);
        }

        return response()->json([
            'data' => $this->delivery->buildPayload($dsr, $request->input('event', 'dsr.completed'), $format),
        ]);
    }

    // ================= Kanal masuk =================

    public function indexChannels(Request $request): JsonResponse
    {
        $channels = DsrInboundChannel::orderBy('name')->get()->map(function (DsrInboundChannel $c) {
            $arr = $c->toArray();
            // Token dikembalikan HANYA di sini, bukan pada daftar publik mana
            // pun, karena ia berfungsi sebagai kredensial endpoint tanpa
            // otentikasi.
            $arr['inbound_url'] = $c->type === 'webhook' && $c->inbound_token
                ? url('/api/public/dsr/inbound/'.$c->inbound_token)
                : null;

            return $arr;
        });

        return response()->json(['data' => $channels, 'meta' => ['types' => DsrInboundChannel::TYPES]]);
    }

    public function storeChannel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'type' => 'required|in:'.implode(',', DsrInboundChannel::TYPES),
            'app_id' => 'nullable|uuid',
            'config' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $channel = DsrInboundChannel::create([
            'org_id' => $request->user()->org_id,
            'app_id' => $data['app_id'] ?? null,
            'name' => $data['name'],
            'type' => $data['type'],
            'config' => isset($data['config']) ? json_encode($data['config']) : null,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        AuditLog::log('dsr', $channel->id, 'inbound_channel_created', [
            'name' => $channel->name,
            'type' => $channel->type,
        ], 'intake');

        return response()->json([
            'data' => array_merge($channel->toArray(), [
                'inbound_url' => $channel->type === 'webhook'
                    ? url('/api/public/dsr/inbound/'.$channel->inbound_token)
                    : null,
            ]),
        ], 201);
    }

    public function destroyChannel(Request $request, string $id): JsonResponse
    {
        $channel = DsrInboundChannel::findOrFail($id);
        $channel->delete();

        return response()->json(['message' => 'Kanal dihapus.']);
    }

    /**
     * Uji kanal masuk dengan pesan contoh, tanpa perlu mengirim surel nyata.
     *
     * Disediakan karena menguji kanal masuk secara sungguhan mensyaratkan
     * penyedia surel sudah terpasang — dan tim yang sedang memasangnya perlu
     * cara memastikan sisi kami bekerja lebih dulu.
     */
    public function testChannel(Request $request, string $id): JsonResponse
    {
        $channel = DsrInboundChannel::findOrFail($id);
        $data = $request->validate([
            'from' => 'required|email',
            'from_name' => 'nullable|string|max:150',
            'subject' => 'nullable|string|max:500',
            'body' => 'nullable|string|max:5000',
        ]);

        $result = $this->intake->fromEmail($channel, $data);

        return response()->json([
            'message' => $result['created']
                ? 'Permohonan '.$result['request_id'].' dibuat dari pesan uji.'
                : 'Pesan ditolak: '.$result['reason'],
            'data' => $result,
        ], $result['created'] ? 201 : 422);
    }
}
