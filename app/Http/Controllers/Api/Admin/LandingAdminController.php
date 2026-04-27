<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Landing\LandingFeature;
use App\Models\Landing\LandingLead;
use App\Models\Landing\LandingLogo;
use App\Models\Landing\LandingProduct;
use App\Models\Landing\LandingSetting;
use App\Models\Landing\LandingStat;
use App\Models\Landing\LandingTeamMember;
use App\Models\Landing\LandingTestimonial;
use App\Services\LandingAssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Single controller untuk semua resource landing admin.
 * Gated by middleware `role.root` (root + superadmin only).
 *
 * Endpoint pattern:
 *   GET    /api/admin/landing/{resource}            → list (with trashed if applicable)
 *   POST   /api/admin/landing/{resource}            → create
 *   GET    /api/admin/landing/{resource}/{id}       → show
 *   PUT    /api/admin/landing/{resource}/{id}       → update
 *   DELETE /api/admin/landing/{resource}/{id}       → delete
 *   POST   /api/admin/landing/{resource}/reorder    → batch update order_index
 *   POST   /api/admin/landing/{resource}/{id}/upload → file upload (returns path)
 */
class LandingAdminController extends Controller
{
    public function __construct(private LandingAssetService $assets) {}

    // ============ SETTINGS (singleton) ============

    public function getSettings()
    {
        return response()->json(['data' => LandingSetting::current()]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'hero_eyebrow' => 'nullable|string|max:160',
            'hero_eyebrow_en' => 'nullable|string|max:160',
            'hero_headline' => 'nullable|string|max:1000',
            'hero_headline_en' => 'nullable|string|max:1000',
            'hero_subheadline' => 'nullable|string|max:2000',
            'hero_subheadline_en' => 'nullable|string|max:2000',
            'hero_image_path' => 'nullable|string|max:500',
            'hero_video_url' => 'nullable|url|max:500',
            'hero_cta_primary_label' => 'nullable|string|max:80',
            'hero_cta_primary_label_en' => 'nullable|string|max:80',
            'hero_cta_primary_url' => 'nullable|string|max:500',
            'hero_cta_secondary_label' => 'nullable|string|max:80',
            'hero_cta_secondary_label_en' => 'nullable|string|max:80',
            'hero_cta_secondary_url' => 'nullable|string|max:500',
            'brand_logo_path' => 'nullable|string|max:500',
            'brand_favicon_path' => 'nullable|string|max:500',
            'brand_primary_color' => 'nullable|string|max:24',
            'brand_accent_color' => 'nullable|string|max:24',
            'seo_title' => 'nullable|string|max:255',
            'seo_title_en' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:1000',
            'seo_description_en' => 'nullable|string|max:1000',
            'seo_og_image_path' => 'nullable|string|max:500',
            'contact_email' => 'nullable|email|max:200',
            'contact_phone' => 'nullable|string|max:60',
            'contact_address' => 'nullable|string|max:500',
            'contact_address_en' => 'nullable|string|max:500',
            'social_linkedin' => 'nullable|url|max:500',
            'social_twitter' => 'nullable|url|max:500',
            'social_youtube' => 'nullable|url|max:500',
            'social_instagram' => 'nullable|url|max:500',
            'footer_about' => 'nullable|string|max:1000',
            'footer_about_en' => 'nullable|string|max:1000',
            'footer_copyright' => 'nullable|string|max:255',
            'footer_copyright_en' => 'nullable|string|max:255',
        ]);

        $setting = LandingSetting::current();
        $setting->update($data);
        $this->bustCache();

        return response()->json(['data' => $setting->fresh(), 'message' => 'Settings tersimpan']);
    }

    // ============ FEATURES ============

    public function indexFeatures()
    {
        return response()->json(['data' => LandingFeature::orderBy('section')->orderBy('order_index')->get()]);
    }

    public function storeFeature(Request $request)
    {
        $data = $request->validate($this->featureRules(false));
        $row = LandingFeature::create($data);
        $this->bustCache();

        return response()->json(['data' => $row, 'message' => 'Feature ditambahkan'], 201);
    }

    public function updateFeature(Request $request, string $id)
    {
        $row = LandingFeature::findOrFail($id);
        $row->update($request->validate($this->featureRules(true)));
        $this->bustCache();

        return response()->json(['data' => $row->fresh(), 'message' => 'Feature diperbarui']);
    }

    public function destroyFeature(string $id)
    {
        $row = LandingFeature::findOrFail($id);
        $this->assets->delete($row->screenshot_path);
        $this->assets->delete($row->icon_image_path);
        $row->delete();
        $this->bustCache();

        return response()->json(['message' => 'Feature dihapus']);
    }

    private function featureRules(bool $update): array
    {
        $req = $update ? 'sometimes' : 'required';
        $opt = $update ? 'sometimes|nullable' : 'nullable';

        return [
            'section' => "{$opt}|in:hero,products,capabilities,why_us,integration",
            'icon_name' => "{$opt}|string|max:80",
            'icon_image_path' => "{$opt}|string|max:500",
            'title' => "{$req}|string|max:220",
            'title_en' => "{$opt}|string|max:220",
            'subtitle' => "{$opt}|string|max:255",
            'subtitle_en' => "{$opt}|string|max:255",
            'description' => "{$opt}|string|max:2000",
            'description_en' => "{$opt}|string|max:2000",
            'screenshot_path' => "{$opt}|string|max:500",
            'category' => "{$opt}|string|max:80",
            'category_en' => "{$opt}|string|max:80",
            'cta_label' => "{$opt}|string|max:80",
            'cta_label_en' => "{$opt}|string|max:80",
            'cta_url' => "{$opt}|string|max:500",
            'order_index' => "{$opt}|integer|min:0",
            'is_published' => "{$opt}|boolean",
        ];
    }

    // ============ TEAM ============

    public function indexTeam()
    {
        return response()->json(['data' => LandingTeamMember::orderBy('order_index')->get()]);
    }

    public function storeTeam(Request $request)
    {
        $data = $request->validate($this->teamRules(false));
        $row = LandingTeamMember::create($data);
        $this->bustCache();

        return response()->json(['data' => $row, 'message' => 'Team member ditambahkan'], 201);
    }

    public function updateTeam(Request $request, string $id)
    {
        $row = LandingTeamMember::findOrFail($id);
        $row->update($request->validate($this->teamRules(true)));
        $this->bustCache();

        return response()->json(['data' => $row->fresh(), 'message' => 'Team member diperbarui']);
    }

    public function destroyTeam(string $id)
    {
        $row = LandingTeamMember::findOrFail($id);
        $this->assets->delete($row->photo_path);
        $row->delete();
        $this->bustCache();

        return response()->json(['message' => 'Team member dihapus']);
    }

    private function teamRules(bool $update): array
    {
        $req = $update ? 'sometimes' : 'required';
        $opt = $update ? 'sometimes|nullable' : 'nullable';

        return [
            'name' => "{$req}|string|max:160",
            'role' => "{$req}|string|max:160",
            'role_en' => "{$opt}|string|max:160",
            'bio' => "{$opt}|string|max:5000",
            'bio_en' => "{$opt}|string|max:5000",
            'photo_path' => "{$opt}|string|max:500",
            'linkedin_url' => "{$opt}|url|max:500",
            'twitter_url' => "{$opt}|url|max:500",
            'email' => "{$opt}|email|max:200",
            'order_index' => "{$opt}|integer|min:0",
            'is_published' => "{$opt}|boolean",
        ];
    }

    // ============ TESTIMONIALS ============

    public function indexTestimonials()
    {
        return response()->json(['data' => LandingTestimonial::orderBy('order_index')->get()]);
    }

    public function storeTestimonial(Request $request)
    {
        $row = LandingTestimonial::create($request->validate($this->testimonialRules(false)));
        $this->bustCache();

        return response()->json(['data' => $row, 'message' => 'Testimonial ditambahkan'], 201);
    }

    public function updateTestimonial(Request $request, string $id)
    {
        $row = LandingTestimonial::findOrFail($id);
        $row->update($request->validate($this->testimonialRules(true)));
        $this->bustCache();

        return response()->json(['data' => $row->fresh(), 'message' => 'Testimonial diperbarui']);
    }

    public function destroyTestimonial(string $id)
    {
        $row = LandingTestimonial::findOrFail($id);
        $this->assets->delete($row->author_photo_path);
        $this->assets->delete($row->company_logo_path);
        $row->delete();
        $this->bustCache();

        return response()->json(['message' => 'Testimonial dihapus']);
    }

    private function testimonialRules(bool $update): array
    {
        $req = $update ? 'sometimes' : 'required';
        $opt = $update ? 'sometimes|nullable' : 'nullable';

        return [
            'quote' => "{$req}|string|max:2000",
            'quote_en' => "{$opt}|string|max:2000",
            'author_name' => "{$req}|string|max:160",
            'author_role' => "{$opt}|string|max:220",
            'author_role_en' => "{$opt}|string|max:220",
            'author_company' => "{$opt}|string|max:160",
            'author_photo_path' => "{$opt}|string|max:500",
            'company_logo_path' => "{$opt}|string|max:500",
            'rating' => "{$opt}|integer|min:1|max:5",
            'order_index' => "{$opt}|integer|min:0",
            'is_featured' => "{$opt}|boolean",
            'is_published' => "{$opt}|boolean",
        ];
    }

    // ============ LOGOS ============

    public function indexLogos()
    {
        return response()->json(['data' => LandingLogo::orderBy('category')->orderBy('order_index')->orderBy('name')->get()]);
    }

    public function storeLogo(Request $request)
    {
        $row = LandingLogo::create($request->validate($this->logoRules(false)));
        $this->bustCache();

        return response()->json(['data' => $row, 'message' => 'Logo ditambahkan'], 201);
    }

    public function updateLogo(Request $request, string $id)
    {
        $row = LandingLogo::findOrFail($id);
        $row->update($request->validate($this->logoRules(true)));
        $this->bustCache();

        return response()->json(['data' => $row->fresh(), 'message' => 'Logo diperbarui']);
    }

    public function destroyLogo(string $id)
    {
        $row = LandingLogo::findOrFail($id);
        $this->assets->delete($row->logo_path);
        $row->delete();
        $this->bustCache();

        return response()->json(['message' => 'Logo dihapus']);
    }

    private function logoRules(bool $update): array
    {
        $req = $update ? 'sometimes' : 'required';
        $opt = $update ? 'sometimes|nullable' : 'nullable';

        return [
            'name' => "{$req}|string|max:160",
            'logo_path' => "{$req}|string|max:500",
            'category' => "{$opt}|in:customer,partner,integration",
            'link_url' => "{$opt}|url|max:500",
            'order_index' => "{$opt}|integer|min:0",
            'is_published' => "{$opt}|boolean",
        ];
    }

    // ============ LEADS (read-only inbox + status update) ============

    public function indexLeads(Request $request)
    {
        $q = LandingLead::query();
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('intent')) {
            $q->where('intent', $request->input('intent'));
        }
        if ($request->filled('search')) {
            $s = $request->input('search');
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")
                    ->orWhere('company', 'like', "%{$s}%");
            });
        }

        return response()->json($q->orderBy('created_at', 'desc')->paginate(20));
    }

    public function showLead(string $id)
    {
        return response()->json(['data' => LandingLead::findOrFail($id)]);
    }

    public function updateLead(Request $request, string $id)
    {
        $data = $request->validate([
            'status' => 'sometimes|in:'.implode(',', LandingLead::STATUSES),
            'admin_notes' => 'sometimes|nullable|string|max:5000',
        ]);
        $row = LandingLead::findOrFail($id);
        if (isset($data['status']) && $data['status'] !== $row->status) {
            $data['handled_by_user_id'] = $request->user()->id;
            $data['handled_at'] = now();
        }
        $row->update($data);

        return response()->json(['data' => $row->fresh(), 'message' => 'Lead diperbarui']);
    }

    public function destroyLead(string $id)
    {
        LandingLead::findOrFail($id)->delete();

        return response()->json(['message' => 'Lead dihapus']);
    }

    // ============ PRODUCTS ============

    public function indexProducts()
    {
        return response()->json(['data' => LandingProduct::orderBy('order_index')->get()]);
    }

    public function storeProduct(Request $request)
    {
        $row = LandingProduct::create($request->validate($this->productRules(false)));
        $this->bustCache();

        return response()->json(['data' => $row, 'message' => 'Product ditambahkan'], 201);
    }

    public function updateProduct(Request $request, string $id)
    {
        $row = LandingProduct::findOrFail($id);
        $row->update($request->validate($this->productRules(true, $id)));
        $this->bustCache();

        return response()->json(['data' => $row->fresh(), 'message' => 'Product diperbarui']);
    }

    public function destroyProduct(string $id)
    {
        $row = LandingProduct::findOrFail($id);
        $this->assets->delete($row->hero_image_path);
        $row->delete();
        $this->bustCache();

        return response()->json(['message' => 'Product dihapus']);
    }

    private function productRules(bool $update, ?string $ignoreId = null): array
    {
        $req = $update ? 'sometimes' : 'required';
        $opt = $update ? 'sometimes|nullable' : 'nullable';

        return [
            'slug' => [$req, 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', Rule::unique('landing_products', 'slug')->ignore($ignoreId)],
            'name' => "{$req}|string|max:160",
            'name_en' => "{$opt}|string|max:160",
            'tagline' => "{$opt}|string|max:255",
            'tagline_en' => "{$opt}|string|max:255",
            'description' => "{$opt}|string|max:5000",
            'description_en' => "{$opt}|string|max:5000",
            'hero_image_path' => "{$opt}|string|max:500",
            'icon_name' => "{$opt}|string|max:80",
            'features' => "{$opt}|array",
            'faqs' => "{$opt}|array",
            'category' => "{$opt}|in:privacy,security,ai_governance,vendor_risk",
            'order_index' => "{$opt}|integer|min:0",
            'is_published' => "{$opt}|boolean",
        ];
    }

    // ============ STATS ============

    public function indexStats()
    {
        return response()->json(['data' => LandingStat::orderBy('order_index')->get()]);
    }

    public function storeStat(Request $request)
    {
        $row = LandingStat::create($request->validate($this->statRules(false)));
        $this->bustCache();

        return response()->json(['data' => $row, 'message' => 'Stat ditambahkan'], 201);
    }

    public function updateStat(Request $request, string $id)
    {
        $row = LandingStat::findOrFail($id);
        $row->update($request->validate($this->statRules(true)));
        $this->bustCache();

        return response()->json(['data' => $row->fresh(), 'message' => 'Stat diperbarui']);
    }

    public function destroyStat(string $id)
    {
        LandingStat::findOrFail($id)->delete();
        $this->bustCache();

        return response()->json(['message' => 'Stat dihapus']);
    }

    private function statRules(bool $update): array
    {
        $req = $update ? 'sometimes' : 'required';
        $opt = $update ? 'sometimes|nullable' : 'nullable';

        return [
            'label' => "{$req}|string|max:160",
            'label_en' => "{$opt}|string|max:160",
            'value' => "{$req}|string|max:80",
            'icon_name' => "{$opt}|string|max:80",
            'order_index' => "{$opt}|integer|min:0",
            'is_published' => "{$opt}|boolean",
        ];
    }

    // ============ SHARED: REORDER + UPLOAD ============

    /**
     * Batch reorder — body: {ids: ["uuid1","uuid2",...]} dalam urutan baru.
     */
    public function reorder(Request $request, string $resource)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'uuid',
        ]);

        $modelClass = match ($resource) {
            'features' => LandingFeature::class,
            'team' => LandingTeamMember::class,
            'testimonials' => LandingTestimonial::class,
            'logos' => LandingLogo::class,
            'products' => LandingProduct::class,
            'stats' => LandingStat::class,
            default => abort(400, "Unknown resource: {$resource}"),
        };

        foreach ($request->ids as $i => $id) {
            $modelClass::where('id', $id)->update(['order_index' => $i]);
        }
        $this->bustCache();

        return response()->json(['message' => 'Order tersimpan']);
    }

    /**
     * Generic upload — return relative path. Frontend lalu PATCH resource dengan path itu.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:5120', // 5MB hard cap
            'type' => 'required|in:hero,features,team,testimonials,logos,products,misc',
        ]);

        try {
            $path = $this->assets->store($request->file('file'), $request->input('type'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'path' => $path,
            'url' => $this->assets->url($path),
        ]);
    }

    /**
     * Bust semua cache landing — dipanggil setelah create/update/delete apapun.
     */
    private function bustCache(): void
    {
        foreach (['settings', 'features:all', 'team:all', 'testimonials', 'logos:all', 'products', 'stats'] as $key) {
            Cache::forget('landing:'.$key);
        }
        // Section-filtered features juga
        foreach (['hero', 'products', 'capabilities', 'why_us', 'integration'] as $s) {
            Cache::forget('landing:features:'.$s);
        }
        // Logos per category
        foreach (['customer', 'partner', 'integration'] as $c) {
            Cache::forget('landing:logos:'.$c);
        }
    }
}
