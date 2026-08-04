<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DsrInboundChannel;
use App\Services\DsrIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penerimaan permohonan subjek data dari penyedia surel (inbound parse).
 *
 * Tanpa otentikasi — penyedia surel seperti SendGrid, Mailgun, atau Postmark
 * meneruskan pesan masuk ke URL tetap dan tidak dapat membawa token sesi.
 * Rahasianya ada pada URL itu sendiri.
 *
 * Berkas ini sengaja terpisah dari DsrChannelController supaya tidak ada satu
 * pun jalur tanpa otentikasi yang berbagi berkas dengan jalur yang
 * membutuhkannya — kekeliruan menempatkan route paling sering terjadi ketika
 * keduanya bersebelahan.
 */
class DsrInboundPublicController extends Controller
{
    public function __construct(private readonly DsrIntakeService $intake) {}

    public function receive(Request $request, string $token): JsonResponse
    {
        // Global scope org tidak berlaku di jalur tanpa otentikasi; token
        // itulah satu-satunya penentu tenant mana yang dituju.
        $channel = DsrInboundChannel::withoutGlobalScope('org')
            ->where('inbound_token', $token)
            ->where('type', 'webhook')
            ->where('is_active', true)
            ->first();

        if (! $channel) {
            return response()->json(['message' => 'Kanal tidak ditemukan atau tidak aktif.'], 404);
        }

        // Penyedia surel memakai nama field yang berbeda-beda. Menerima
        // beberapa ragam sekaligus jauh lebih murah daripada meminta klien
        // memasang lapisan penerjemah di antara penyedianya dan kami.
        $message = [
            'from' => $request->input('from')
                ?? $request->input('sender')
                ?? $request->input('From'),
            'from_name' => $request->input('from_name') ?? $request->input('FromName'),
            'subject' => $request->input('subject') ?? $request->input('Subject'),
            'body' => $request->input('text')
                ?? $request->input('body')
                ?? $request->input('TextBody')
                ?? $request->input('stripped-text'),
        ];

        // Alamat sering datang dalam bentuk "Nama <alamat@contoh.id>".
        if (is_string($message['from']) && preg_match('/<([^>]+)>/', $message['from'], $m)) {
            if (empty($message['from_name'])) {
                $message['from_name'] = trim(str_replace($m[0], '', $message['from']), " \t\"");
            }
            $message['from'] = trim($m[1]);
        }

        $result = $this->intake->fromEmail($channel, $message);

        // Selalu 200 untuk pesan yang ditolak karena isinya, bukan 4xx:
        // penyedia surel akan mengirim ulang berkali-kali pada respons galat,
        // dan pesan yang memang tidak sah tidak akan pernah menjadi sah.
        return response()->json([
            'received' => $result['created'],
            'request_id' => $result['request_id'] ?? null,
            'reason' => $result['reason'] ?? null,
        ], 200);
    }
}
