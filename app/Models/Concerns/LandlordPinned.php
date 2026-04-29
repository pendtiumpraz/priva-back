<?php

namespace App\Models\Concerns;

/**
 * Pin a model to the landlord (platform-level) DB connection.
 *
 * In production: returns 'landlord' so the model's queries always hit the
 * platform DB regardless of whether tenant.db middleware has switched the
 * default connection to a per-tenant database. AppServiceProvider boots
 * the 'landlord' connection alias from the original default at boot time.
 *
 * In testing: returns null (= default connection). The phpunit setup uses
 * SQLite :memory: which is per-connection — a separately-named 'landlord'
 * connection would resolve to a fresh, empty :memory: DB and fail because
 * migrations only ran on the default. Falling back to default keeps the
 * test fixture intact while still preserving the production behavior.
 */
trait LandlordPinned
{
    public function getConnectionName(): ?string
    {
        if (app()->environment('testing')) {
            return null;
        }
        return 'landlord';
    }
}
