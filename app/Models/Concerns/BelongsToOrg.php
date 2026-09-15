<?php

namespace App\Models\Concerns;

use App\Services\CurrentOrgContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Auto-scope every query on the model to the current request's org_id, and
 * auto-fill `org_id` on creating events so callers don't have to remember.
 *
 * Apply ONLY to tenant-scoped models (Ropa, Dpia, DsrRequest, BreachIncident,
 * ConsentLog, etc). Do NOT apply to:
 *   - Landlord-only models (User, Organization, License, AppSetting, MenuItem)
 *   - Read-mostly reference data shared across tenants
 *   - Models that intentionally span tenants (e.g. system-wide audit aggregates)
 *
 * The scope is a no-op when CurrentOrgContext is unset — that's the explicit
 * "no tenant in context" signal coming from artisan / queue / super-admin
 * tools, so they keep seeing all rows. To opt out for a specific query in a
 * route that has context (e.g. an explicit cross-tenant admin report), use:
 *
 *     Ropa::withoutGlobalScope('org')->where(...)->get();
 */
trait BelongsToOrg
{
    protected static function bootBelongsToOrg(): void
    {
        static::addGlobalScope('org', function (Builder $builder) {
            $orgId = app(CurrentOrgContext::class)->get();
            if ($orgId !== null) {
                $builder->where($builder->getModel()->getTable().'.org_id', $orgId);
            }
        });

        static::creating(function (Model $model) {
            // Don't override an org_id the caller already set explicitly —
            // some flows (e.g. admin tools cloning data across orgs) need to
            // pass an explicit org_id, and we should respect it.
            //
            // Diakses lewat getAttribute/setAttribute, BUKAN `$model->org_id`.
            // Closure ini bertipe Model — kelas dasar yang tidak punya kolom
            // apa pun — sehingga akses properti langsung ditandai analisis
            // statis sebagai properti tak terdefinisi, SEKALI UNTUK SETIAP
            // model yang memakai trait ini. Dulu ada 40-an galat seperti itu,
            // dan tiap model baru menambah satu lagi. Keduanya setara persis
            // secara perilaku; bedanya yang satu bisa diverifikasi.
            if (empty($model->getAttribute('org_id'))) {
                $orgId = app(CurrentOrgContext::class)->get();
                if ($orgId !== null) {
                    $model->setAttribute('org_id', $orgId);
                }
            }
        });
    }
}
