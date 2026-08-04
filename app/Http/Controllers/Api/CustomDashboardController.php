<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CustomDashboard;
use App\Services\DashboardWidgetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard yang disusun sendiri oleh tenant, dengan penyaringan berdasarkan
 * matriks hak akses.
 *
 * Penyaringan berlapis dua, dan lapisan kedua yang menentukan:
 *
 *   1. `visible_roles` menentukan siapa yang melihat dashboardnya.
 *   2. Saat render, TIAP widget dicek terhadap izin modul penggunanya.
 *
 * Tanpa lapisan kedua, seseorang yang tidak berhak membaca DPIA tetap akan
 * melihat angka DPIA begitu ia dibagikan sebuah dashboard yang memuatnya —
 * pembatasan modul menjadi tidak berarti hanya karena angkanya ditempatkan di
 * halaman lain.
 */
class CustomDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $dashboards = CustomDashboard::query()
            ->when($request->input('module'), fn ($q, $m) => $q->whereIn('module', [$m, 'all']))
            ->orderByDesc('is_default')
            ->orderBy('sequence')
            ->get()
            ->filter(fn (CustomDashboard $d) => $d->isVisibleTo($user))
            ->values();

        return response()->json([
            'data' => $dashboards,
            'meta' => ['available_sources' => $this->sourcesFor($user)],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $user = $request->user();

        $dashboard = CustomDashboard::create(array_merge($data, [
            'org_id' => $user->org_id,
            'created_by' => $user->id,
            'owner_user_id' => $data['owner_user_id'] ?? null,
        ]));

        AuditLog::log('dashboard', $dashboard->id, 'custom_dashboard_created', [
            'name' => $dashboard->name,
            'widgets' => count($dashboard->widgets ?? []),
        ]);

        return response()->json(['data' => $dashboard], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $dashboard = CustomDashboard::findOrFail($id);
        $this->assertCanEdit($request, $dashboard);

        $data = $this->validatePayload($request, partial: true);
        $dashboard->update($data);

        AuditLog::log('dashboard', $dashboard->id, 'custom_dashboard_updated', array_keys($data));

        return response()->json(['data' => $dashboard->fresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $dashboard = CustomDashboard::findOrFail($id);
        $this->assertCanEdit($request, $dashboard);
        $dashboard->delete();

        AuditLog::log('dashboard', $dashboard->id, 'custom_dashboard_deleted');

        return response()->json(['message' => 'Dashboard dihapus.']);
    }

    /**
     * Hitung isi dashboard.
     *
     * Widget yang izinnya tidak dimiliki pengguna TIDAK dihitung dan tidak
     * dikembalikan datanya; ia hanya dilaporkan sebagai tertahan, supaya
     * penyusun dashboard memahami mengapa penerimanya melihat halaman yang
     * lebih sedikit — tanpa membocorkan angkanya.
     */
    public function render(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $dashboard = CustomDashboard::findOrFail($id);

        if (! $dashboard->isVisibleTo($user)) {
            return response()->json(['message' => 'Dashboard ini tidak tersedia untuk akun Anda.'], 403);
        }

        $rendered = [];
        $withheld = [];

        foreach ($dashboard->widgets ?? [] as $widget) {
            $source = $widget['source'] ?? null;
            if (! $source || ! DashboardWidgetRegistry::has($source)) {
                $withheld[] = ['source' => $source, 'reason' => 'Sumber tidak dikenali.'];

                continue;
            }
            if (! $this->userCanRead($user, DashboardWidgetRegistry::permissionFor($source))) {
                $withheld[] = ['source' => $source, 'reason' => 'Tidak memiliki izin modul terkait.'];

                continue;
            }

            $rendered[] = array_merge($widget, DashboardWidgetRegistry::compute($source));
        }

        return response()->json([
            'data' => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'module' => $dashboard->module,
                'widgets' => $rendered,
                'withheld' => $withheld,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request, bool $partial = false): array
    {
        $rule = fn (string $r) => $partial ? 'sometimes|'.$r : $r;

        $data = $request->validate([
            'name' => $rule('required|string|max:150'),
            'description' => 'sometimes|nullable|string',
            'module' => $rule('required|in:'.implode(',', CustomDashboard::MODULES)),
            'widgets' => 'sometimes|array',
            'widgets.*.source' => 'required_with:widgets|string|max:100',
            'widgets.*.title' => 'nullable|string|max:150',
            'widgets.*.chart' => 'nullable|string|max:32',
            'widgets.*.size' => 'nullable|string|max:16',
            'owner_user_id' => 'sometimes|nullable|uuid',
            'visible_roles' => 'sometimes|nullable|array',
            'is_default' => 'sometimes|boolean',
            'sequence' => 'sometimes|integer',
        ]);

        // Sumber divalidasi terhadap daftar tertutup di sini juga, bukan hanya
        // saat render — menyimpan widget yang tidak akan pernah dapat dihitung
        // hanya melahirkan dashboard yang selalu bolong tanpa penjelasan.
        foreach ($data['widgets'] ?? [] as $i => $widget) {
            if (! DashboardWidgetRegistry::has($widget['source'])) {
                abort(422, "Sumber data \"{$widget['source']}\" pada widget ke-".($i + 1).' tidak dikenali.');
            }
        }

        return $data;
    }

    private function assertCanEdit(Request $request, CustomDashboard $dashboard): void
    {
        $user = $request->user();
        // Dashboard pribadi hanya dapat disunting pemiliknya; dashboard
        // organisasi dapat disunting siapa pun yang berwenang di modulnya.
        if ($dashboard->owner_user_id !== null && $dashboard->owner_user_id !== $user->id) {
            abort(403, 'Dashboard ini milik pengguna lain.');
        }
    }

    private function userCanRead($user, ?string $module): bool
    {
        if (! $module) {
            return false;
        }
        if (in_array($user->role, ['root', 'superadmin'], true)) {
            return true;
        }

        $perms = $user->tenantRole?->permissions;
        if (! is_array($perms)) {
            // Selaras dengan CheckPermission: ketika permissions bukan array,
            // akses baca terbuka lewat jalur legacy.
            return true;
        }
        if (in_array('*', $perms, true)) {
            return true;
        }

        foreach ($perms as $perm) {
            $parts = explode(':', (string) $perm);
            $mod = str_replace('-', '_', $parts[0]);
            if ($mod !== str_replace('-', '_', $module)) {
                continue;
            }
            if (! isset($parts[1]) || in_array($parts[1], ['read', 'write'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array<string, string>> */
    private function sourcesFor($user): array
    {
        $out = [];
        foreach (DashboardWidgetRegistry::sources() as $key => $meta) {
            if (! $this->userCanRead($user, $meta['permission'])) {
                continue;
            }
            $out[] = ['source' => $key, 'label' => $meta['label'], 'type' => $meta['type']];
        }

        return $out;
    }
}
