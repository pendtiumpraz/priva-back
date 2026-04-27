<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Landing\LandingFeature;
use App\Models\Landing\LandingLead;
use App\Models\Landing\LandingLogo;
use App\Models\Landing\LandingProduct;
use App\Models\Landing\LandingSetting;
use App\Models\Landing\LandingStat;
use App\Models\Landing\LandingTeamMember;
use App\Models\Landing\LandingTestimonial;
use App\Services\LandingLocaleResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public READ endpoints untuk landing page — no auth, di-cache 5 menit
 * supaya marketing site tidak bottleneck DB tiap visitor.
 *
 * Cache di-bust otomatis lewat LandingCacheService::flush() yang dipanggil
 * controller admin setelah save.
 */
class PublicLandingController extends Controller
{
    private const CACHE_TTL = 300; // 5 min

    public function settings(Request $request)
    {
        $row = Cache::remember('landing:settings', self::CACHE_TTL, fn () => LandingSetting::current()?->toArray());
        $resolver = LandingLocaleResolver::fromRequest($request);
        $data = $resolver->single($row, [
            'hero_eyebrow', 'hero_headline', 'hero_subheadline',
            'hero_cta_primary_label', 'hero_cta_secondary_label',
            'seo_title', 'seo_description', 'contact_address',
            'footer_about', 'footer_copyright',
        ]);

        return response()->json(['data' => $data, 'locale' => $resolver->locale()])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=600')
            ->header('Vary', 'Accept-Language');
    }

    public function features(Request $request)
    {
        $section = $request->input('section'); // optional filter
        $key = 'landing:features:'.($section ?: 'all');

        $rows = Cache::remember($key, self::CACHE_TTL, function () use ($section) {
            $q = LandingFeature::query()->where('is_published', true);
            if ($section) {
                $q->where('section', $section);
            }

            return $q->orderBy('order_index')->orderBy('created_at')->get();
        });

        $resolver = LandingLocaleResolver::fromRequest($request);
        $data = $resolver->collection($rows, ['title', 'subtitle', 'description', 'category', 'cta_label']);

        return response()->json(['data' => $data, 'locale' => $resolver->locale()])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300')
            ->header('Vary', 'Accept-Language');
    }

    public function team(Request $request)
    {
        $perPage = min((int) $request->input('per_page', 12), 50);
        $list = Cache::remember('landing:team:all', self::CACHE_TTL, fn () => LandingTeamMember::where('is_published', true)
            ->orderBy('order_index')->orderBy('created_at')->get());

        $page = max(1, (int) $request->input('page', 1));
        $items = $list->forPage($page, $perPage)->values();

        $resolver = LandingLocaleResolver::fromRequest($request);
        $data = $resolver->collection($items, ['role', 'bio']);

        return response()->json([
            'data' => $data,
            'locale' => $resolver->locale(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $list->count(),
                'last_page' => max(1, (int) ceil($list->count() / $perPage)),
            ],
        ])->header('Cache-Control', 'public, max-age=60, s-maxage=300')->header('Vary', 'Accept-Language');
    }

    public function testimonials(Request $request)
    {
        $rows = Cache::remember('landing:testimonials', self::CACHE_TTL, fn () => LandingTestimonial::where('is_published', true)
            ->orderBy('is_featured', 'desc')
            ->orderBy('order_index')->get());

        $resolver = LandingLocaleResolver::fromRequest($request);
        $data = $resolver->collection($rows, ['quote', 'author_role']);

        return response()->json(['data' => $data, 'locale' => $resolver->locale()])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300')
            ->header('Vary', 'Accept-Language');
    }

    public function logos(Request $request)
    {
        $category = $request->input('category'); // customer | partner | integration
        $key = 'landing:logos:'.($category ?: 'all');

        $data = Cache::remember($key, self::CACHE_TTL, function () use ($category) {
            $q = LandingLogo::query()->where('is_published', true);
            if ($category) {
                $q->where('category', $category);
            }

            return $q->orderBy('order_index')->orderBy('name')->get();
        });

        return response()->json(['data' => $data])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }

    /**
     * Submit Contact Us / Request Demo form. Tidak ada pricing publik —
     * semua jalur konversi end di sini (Privasimu serve banyak gov apps,
     * harga tidak dipublish).
     *
     * Rate limit per IP: 5 submission / hour, untuk anti-spam.
     * Captcha verification optional (cek ENV LANDING_CAPTCHA_REQUIRED=true).
     */
    public function submitLead(Request $request)
    {
        $ip = (string) $request->ip();

        // Hard rate-limit (defense-in-depth against bots)
        $rateKey = 'landing-lead:'.$ip;
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $secs = RateLimiter::availableIn($rateKey);

            return response()->json([
                'error' => "Terlalu banyak submission. Coba lagi dalam {$secs} detik.",
            ], 429);
        }

        $data = $request->validate([
            'name' => 'required|string|max:160',
            'email' => 'required|email|max:200',
            'phone' => 'nullable|string|max:60',
            'company' => 'nullable|string|max:200',
            'job_title' => 'nullable|string|max:160',
            'industry' => 'nullable|string|max:80',
            'employee_count' => 'nullable|integer|min:1|max:1000000',
            'intent' => 'required|in:'.implode(',', LandingLead::INTENTS),
            'message' => 'nullable|string|max:5000',
            'utm_source' => 'nullable|string|max:120',
            'utm_medium' => 'nullable|string|max:120',
            'utm_campaign' => 'nullable|string|max:120',
        ]);

        $email = strtolower(trim((string) $data['email']));

        // 1 email + 1 IP rule: refuse if either has previously submitted (any intent).
        // Soft-deleted rows don't count as duplicates.
        $existingByEmail = LandingLead::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existingByEmail) {
            return response()->json([
                'error' => 'Email ini sudah mengirim pesan sebelumnya. Tim kami akan menghubungi Anda. Untuk pertanyaan lain, silakan email langsung ke hello@privasimu.com.',
                'already_submitted' => true,
                'lead_id' => $existingByEmail->id,
            ], 409);
        }

        $existingByIp = LandingLead::query()->where('ip_address', $ip)->first();
        if ($existingByIp) {
            return response()->json([
                'error' => 'Permintaan dari koneksi internet ini sudah pernah diterima. Jika Anda baru, silakan email kami di hello@privasimu.com.',
                'already_submitted' => true,
                'lead_id' => $existingByIp->id,
            ], 409);
        }

        RateLimiter::hit($rateKey, 3600);

        $lead = LandingLead::create(array_merge($data, [
            'email' => $email,
            'source' => 'landing',
            'ip_address' => $ip,
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'status' => 'new',
        ]));

        return response()->json([
            'message' => $data['intent'] === 'demo'
                ? 'Terima kasih! Tim kami akan menghubungi Anda dalam 1×24 jam untuk jadwal demo.'
                : 'Terima kasih! Pesan Anda terkirim. Tim kami akan membalas segera.',
            'lead_id' => $lead->id,
        ], 201);
    }

    public function products(Request $request)
    {
        $rows = Cache::remember('landing:products', self::CACHE_TTL, fn () => LandingProduct::where('is_published', true)
            ->orderBy('order_index')->get());

        $resolver = LandingLocaleResolver::fromRequest($request);
        $data = $resolver->collection($rows, ['name', 'tagline', 'description']);

        return response()->json(['data' => $data, 'locale' => $resolver->locale()])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300')
            ->header('Vary', 'Accept-Language');
    }

    public function productDetail(Request $request, string $slug)
    {
        $row = Cache::remember("landing:product:{$slug}", self::CACHE_TTL, fn () => LandingProduct::where('slug', $slug)->where('is_published', true)->first());
        if (! $row) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        $resolver = LandingLocaleResolver::fromRequest($request);
        $data = $resolver->single($row, ['name', 'tagline', 'description']);

        return response()->json(['data' => $data, 'locale' => $resolver->locale()])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300')
            ->header('Vary', 'Accept-Language');
    }

    public function stats(Request $request)
    {
        $rows = Cache::remember('landing:stats', self::CACHE_TTL, fn () => LandingStat::where('is_published', true)
            ->orderBy('order_index')->get());

        $resolver = LandingLocaleResolver::fromRequest($request);
        $data = $resolver->collection($rows, ['label']);

        return response()->json(['data' => $data, 'locale' => $resolver->locale()])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300')
            ->header('Vary', 'Accept-Language');
    }

    /**
     * One-shot bundle endpoint — saved request count untuk landing initial render.
     * Frontend home/marketing/page.tsx bisa panggil sekali aja.
     * Honor ?locale=en untuk full bilingual response.
     */
    public function bundle(Request $request)
    {
        $resolver = LandingLocaleResolver::fromRequest($request);
        $settings = LandingSetting::current();
        $features = LandingFeature::where('is_published', true)->orderBy('order_index')->get();
        $testimonials = LandingTestimonial::where('is_published', true)->orderBy('is_featured', 'desc')->orderBy('order_index')->get();
        $logos = LandingLogo::where('is_published', true)->orderBy('order_index')->get();
        $stats = LandingStat::where('is_published', true)->orderBy('order_index')->get();
        $products = LandingProduct::where('is_published', true)->orderBy('order_index')->get(['id', 'slug', 'name', 'name_en', 'tagline', 'tagline_en', 'icon_name', 'category']);

        return response()->json([
            'locale' => $resolver->locale(),
            'settings' => $resolver->single($settings, [
                'hero_eyebrow', 'hero_headline', 'hero_subheadline',
                'hero_cta_primary_label', 'hero_cta_secondary_label',
                'seo_title', 'seo_description', 'contact_address',
                'footer_about', 'footer_copyright',
            ]),
            'features' => $resolver->collection($features, ['title', 'subtitle', 'description', 'category', 'cta_label']),
            'testimonials' => $resolver->collection($testimonials, ['quote', 'author_role']),
            'logos' => $logos,
            'stats' => $resolver->collection($stats, ['label']),
            'products' => $resolver->collection($products, ['name', 'tagline']),
        ])->header('Cache-Control', 'public, max-age=60, s-maxage=300')
            ->header('Vary', 'Accept-Language');
    }
}
