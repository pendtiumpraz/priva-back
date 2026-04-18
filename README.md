# PRIVASIMU NEXUS — Backend

Laravel 12 / PHP 8.3 API backing the PRIVASIMU NEXUS multi-tenant PDP compliance platform. This is the source of truth for tenant data, role resolution, menu registry, licensing, AI tool execution, and audit logging.

## Architecture at a Glance

- **Multi-tenant via `org_id`** — every tenant-scoped table carries a UUID `org_id`. No query leaves `app/` without that filter. Soft-deletes (`deleted_at`) are the universal norm.
- **UUID primary keys everywhere.** Auto-increment IDs are not used for domain tables.
- **Role hierarchy**: `root` > `superadmin` > `admin` > `dpo` > `maker` > `viewer`.
  - `root` + `superadmin` have no `org_id` (platform-level).
  - `admin`..`viewer` are tenant-scoped.
- **3-layer menu visibility**: `entitlement × role_whitelist × tenant_override × license_package_gate × parent_visible`. Resolved in `App\Services\MenuRegistryService`.
- **Universal CRUD**: six modules (`ropa`, `dpia`, `dsr`, `consent`, `breach`, `data-discovery`) share one controller `Api\ModuleCrudController` mounted under `/api/m/{module}`.
- **AI Agent** routes all DB-mutating tool calls through `App\Services\AiAgentToolExecutor` which is org-scoped in its constructor.

## Stack

| Layer | Tech |
|---|---|
| Framework | Laravel 12 |
| Runtime | PHP 8.3 |
| Auth | Laravel Sanctum (personal access tokens) |
| DB (dev) | SQLite or PostgreSQL |
| DB (prod) | MySQL 8 / PostgreSQL 14+ |
| DB (tests) | SQLite `:memory:` |
| Queue | Laravel queue (DB driver by default; Redis-ready) |
| AI | OpenRouter / DeepSeek / Anthropic / OpenAI (abstracted in `AiService`) |
| Lint | Laravel Pint |
| Testing | PHPUnit 11 |

## Quick Start (Dev)

```bash
composer install
cp .env.example .env
php artisan key:generate

# Pick one DB: sqlite (easiest), mysql, postgres.
# For sqlite: touch database/database.sqlite, set DB_CONNECTION=sqlite.
php artisan migrate --seed

# All-in-one dev loop: server + queue worker + pail logs + vite
composer dev

# Or just the HTTP server
php artisan serve
```

Default API base URL: `http://127.0.0.1:8000/api`.

### Creating the first Root user

Root is the platform owner — a single-instance role that sits above superadmin and manages infra, tenant lifecycle, AI provider config, and branding. Only **one** root user is allowed per deployment.

Two ways to create it:

**1. Seeder (reads env vars):**

Add to `.env`:
```
ROOT_EMAIL=root@example.com
ROOT_PASSWORD=ChangeMeNow_2026!
ROOT_NAME=Platform Owner
```

Then:
```bash
php artisan db:seed --class=RootUserSeeder
```

**2. Artisan command (prompts interactively / passes args):**
```bash
php artisan root:create root@example.com 'ChangeMeNow_2026!' --name='Platform Owner'
```

Both paths reject creation if a root user already exists; remove the existing row or transfer ownership first.

### Docker

```bash
cp .env.docker.example .env
docker compose up -d --build
docker compose exec backend php artisan migrate --seed --force
```

## Testing

```bash
php artisan test                  # full suite
php artisan test --filter=SomeTest
vendor/bin/phpunit --testsuite Feature
vendor/bin/pint                   # lint
```

Tests use SQLite in-memory. Every migration therefore must be portable across sqlite / mysql / postgres — avoid MySQL-only `->after()`, use `Schema::hasColumn()` guards, and wrap `Schema::create` in try/catch that tolerates `42S01` / `42P07` / MySQL 1050 "already exists" for idempotent re-runs.

## Domain Architecture

### Tenant isolation invariant

Every repository / controller / service that touches tenant data **must** filter by `org_id`. Patterns to rely on:

- `App\Services\TenantContextService` resolves the caller's `org_id`.
- `AiAgentToolExecutor::__construct(string $orgId)` locks every tool call to one tenant.
- `ModuleCrudController::scopedQuery()` auto-applies `where('org_id', $user->org_id)`.

Violating this leaks data across customers. There is no retroactive fix — do not merge code that queries a tenant table without an `org_id` clause.

### Role & Permission Resolution

Middleware `CheckPermission` (alias `permission:`) gates routes. Usage:

```php
Route::post('/m/{module}', ...)->middleware('permission:ropa,write');
```

Resolution order inside the middleware:

1. **Root / Superadmin bypass** — they pass every `permission:` gate.
2. **Tenant role permission JSON** — `User->tenantRole->permissions` is a JSON array of `module_id` or `module_id:read` / `module_id:write`. `*` is wildcard.
3. **Legacy fallback** — if `tenantRole->permissions` isn't an array, `admin` / `dpo` / `maker` get write access, `viewer` gets read-only.

`ModuleCrudController::checkPermission()` duplicates this logic for the universal CRUD paths. Keep the two in sync when you change permission semantics.

### 3-Layer Menu Visibility

Tables:
- `menu_items` — catalog of every sidebar entry (menu_key, label, href, icon, section, parent_menu_id, required_packages, hideable).
- `role_menu_whitelist` — Layer 1. `(menu_id, role) → is_allowed`. Global rule, no tenant.
- `tenant_module_entitlements` — Layer 0. `(org_id, menu_id) → entitled + valid_until`. Per-tenant license gate.
- `tenant_menu_override` — Layer 2. `(org_id, menu_id, role) → is_visible`. Admin-hidden within the allowed set.

`MenuRegistryService::forUser(User)` returns the effective menu list. For `role=root` it skips tenant layers but still respects Layer 1 (so root can hide menus from their own sidebar via `/menu-preferences`).

Editing entry points:
- `/menu-control` (root-only) — whitelist matrix + per-tenant entitlement + bulk/copy + audit log.
- `/menu-preferences` (root + superadmin + admin) — Layer 2 tenant overrides, column set depends on role (root sees 6 roles, superadmin 5, admin 4). Platform-role toggles (root / superadmin columns) write to `role_menu_whitelist` because those roles aren't org-scoped.

### Cross-module Auto-triggers

Documented in `PLATFORM_ARCHITECTURE.md` §3. The ones that affect day-to-day changes:

- ROPA with `risk=HIGH` auto-creates a draft DPIA.
- Sensitive categories in ROPA wizard §4 auto-set `risk=HIGH`.
- New Breach initializes `containment_checklist` + `timeline_log`.
- New DSR sets `deadline_at = now + 72h`.
- Auto-generated codes on create: `ROPA-YYYY-NNN`, `DPIA-YYYY-NNN`, `DSR-YYYY-NNN`, `BRC-YYYY-NNN`, `CNT-YYYY-NNN` (`ModuleCrudController::nextCode`).

### Wizard persistence

ROPA and DPIA keep step-by-step wizard state in a `wizard_data` JSON column alongside normalized fields. Prefer extending that blob over new columns unless the field needs to be queryable/joined.

### AI Agent tool execution

Add a new AI-callable action by extending the `match` in `AiAgentToolExecutor::execute($tool, $args)`. Do NOT let the AI call controllers or services directly — everything flows through the executor so org-scoping, credit deduction, and audit logging are guaranteed.

Related services:
- `AiService` — provider abstraction (OpenRouter / DeepSeek / Anthropic / OpenAI).
- `AiFieldMappingService` — schema-aware auto-fill for forms.
- `CreditService` — per-org AI credit ledger written to `ai_credit_logs`.

### Audit logging

`App\Models\AuditLog` captures both human and AI actors. `ModuleCrudController` and `AiAgentToolExecutor` write audit entries automatically. Follow the same pattern in new controllers — soft-delete (`deleted_at`) + audit log is the paired standard on every domain model.

### Tenant lifecycle

`Organization.lifecycle_status` enum: `active | frozen | transferred | archived`. Controlled via `TenantOffboardController`:

- `POST /api/tenant-offboard/{id}/freeze` — login for all users of the tenant blocked. Requires root password re-auth.
- `POST /api/tenant-offboard/{id}/unfreeze` — restore. No password needed.
- `POST /api/tenant-offboard/{id}/transfer` — owner change, audit-logged.
- `POST /api/tenant-offboard/{id}/archive` — soft-delete, sets `hard_delete_at = now + retention`. Cron `CleanupArchivedTenants` (daily 03:00) hard-deletes past the retention window.
- `POST /api/tenant-offboard/{id}/export` — JSON bundle of all tenant-scoped data for compliance handover before archive.

### Scheduled jobs

Registered in `app/Console/Kernel.php`:

- `CleanupExpiredEntitlements` — daily 02:00. Auto-revokes `tenant_module_entitlements` past `valid_until`.
- `CleanupArchivedTenants` — daily 03:00. Hard-deletes tenants past their retention.

Enable cron: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`.

### Per-tenant branding

`tenant_themes` holds palette JSON, logo URL, favicon URL, layout preset, font family, `is_active`. Isolation: tenant A's query never sees tenant B's rows — `TenantThemeController::scope()` narrows by `org_id` or `NULL` (platform-owned themes for root/superadmin). Asset uploads write under `storage/app/public/themes/{org_id|platform}/` — run `php artisan storage:link` once on deploy.

## Routing

Single file: `routes/api.php`.

- **Public** (no auth, throttled): register, login, public feature-requests, public consent capture, SSO callback, threat-intel webhook.
- **Authenticated** (`auth:sanctum`): everything else, including menu registry, themes, module CRUD, AI agent, license, user management.
- **Partner v1** (`Api\V1` namespace, `AuthenticatePartnerApi` middleware): external-facing partner endpoints (`POST /api/v1/breaches` today). Keep future public-integration endpoints here.

## Things NOT to do

- Don't create per-module CRUD controllers for the six universal modules — use `ModuleCrudController`.
- Don't bypass `AiAgentToolExecutor` for AI tool calls.
- Don't query a tenant table without `org_id`.
- Don't use MySQL-only migration helpers (`->after()`, `ALTER COLUMN` without `DOCTRINE_REGISTER_TYPES`).
- Don't hardcode palette colors on components targeting white-labeled tenants — use CSS vars driven by `/themes/active`.
- Don't trust Next.js/React memory for the frontend — the Next 16 + React 19 pair has breaking changes vs. typical training data.

## License

Private. Internal deployment only under PRIVASIMU B2B agreements.
