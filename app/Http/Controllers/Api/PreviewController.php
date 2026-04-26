<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsentCollectionPoint;
use App\Models\DsrApp;
use Illuminate\Http\Request;

/**
 * Live preview pages — sandbox HTML showing real widgets in action.
 * Used by Connection Wizard "Live Preview" iframes + dashboard "Test Live" button.
 *
 * Routes (web.php — non-API):
 *   GET /preview/consent-banner?collection_id=X
 *   GET /preview/dsr-widget?embed_token=X
 *
 * No auth — preview pages are themselves public (load same widget visitors will see).
 * To prevent abuse: only resolve known tokens; gracefully 404 unknown.
 */
class PreviewController extends Controller
{
    public function consentBanner(Request $request)
    {
        $cid = $request->input('collection_id');
        if (!$cid) abort(400, 'collection_id required');

        // Verify collection exists (don't leak which one) — accept embed_token or legacy collection_id
        $cp = ConsentCollectionPoint::where('embed_token', $cid)
            ->orWhere('collection_id', $cid)
            ->orWhere('id', $cid)
            ->first();
        if (!$cp) abort(404, 'Collection not found');

        $base = rtrim(config('app.url') ?: url('/'), '/');
        return response()->view('preview.consent_banner', [
            'collection_id' => $cp->embed_token ?: $cp->collection_id,
            'widget_url' => $base . '/consent-banner.js',
            'api_base' => $base . '/api',
        ]);
    }

    public function dsrWidget(Request $request)
    {
        $token = $request->input('embed_token');
        if (!$token) abort(400, 'embed_token required');

        $app = DsrApp::where('embed_token', $token)->first();
        if (!$app) abort(404, 'App not found');

        $base = rtrim(config('app.url') ?: url('/'), '/');
        return response()->view('preview.dsr_widget', [
            'embed_token' => $app->embed_token,
            'widget_url' => $base . '/dsr-widget.js',
            'api_base' => $base . '/api',
        ]);
    }
}
