# LMS Migration into priva-back

This file documents the integration of the LMS subsystem (originally in
`/Users/pupunn/Development/lms-privasimu/compliance-saas`) into priva-back.

## Origin
- Source repo: `lms-privasimu/compliance-saas` — archived read-only after
  this Foundation phase lands. See its final commit for historical context.

## Foundation phase deliverables
- 16 `lms_*` tables (videos, courses, modules, lessons, quizzes, quiz_questions,
  badges, xp_rules, user_module_progress, user_lesson_progress, quiz_attempts,
  user_badges, user_bookmarks, user_notes, xp_log, org_leaderboard).
- `App\Lms\*` namespace (Models + Http helpers).
- `EnsureLmsEntitled` middleware (alias `lms.entitled`). Adapted to priva-back's
  3-layer visibility model: gate looks up `MenuItem(menu_key='lms')` then
  checks `tenant_module_entitlements(org_id, menu_id, is_entitled=true)` and
  enforces `valid_until` (null OR `>= today`).
- `lms.*` permission keys (learner, content_admin, user_admin, certificate_admin)
  seeded onto system tenant_roles via `LmsPermissionsSeeder`. NOTE: the
  `certificate_admin` key remains seeded for forward-compatibility but the
  cert feature itself is out of Foundation scope (see below).
- `MenuItem(menu_key='lms', label='Learn', href='/learn', section='Menu Utama')`
  seeded via `LmsMenuSeeder` — required precondition for the entitlement gate.
- Default XP rules seeded via `LmsXpRulesSeeder` (lesson_completed=10,
  quiz_passed=50, course_completed=200, quiz_perfect=25).
- All `/api/lms/*` endpoints registered (~46 routes), returning HTTP 501 stubs.
  Subsequent module specs replace them.
- 8 phpunit feature tests covering middleware (5: 503 disabled, 403 no-entitlement,
  200 entitled, 403 explicit `is_entitled=false`, 403 expired `valid_until`)
  and `me/*` stubs (3).

## Adaptations from the original spec
- The plan assumed `tenant_module_entitlements` had `module_key` + `enabled`
  columns. Reality: the table has `menu_id` (FK→`menu_items`) + `is_entitled`
  + `valid_until`. The middleware was adapted to look up via
  `MenuItem.menu_key='lms'` and to enforce `valid_until`. Strict-gate
  semantics preserved.
- `Organization` model gained `HasFactory` trait + a new
  `database/factories/OrganizationFactory.php` (needed for tests; pre-existing
  oversight).

## Scope reduction during Foundation execution
**Certificate feature was removed from Foundation scope** (decided 2026-05-08
during execution). The following artifacts that were originally planned have
been dropped:
- `lms_certificates` table (migration deleted, table dropped).
- `App\Lms\Models\Certificate`.
- `App\Lms\Services\CertificateSigningService` (with its HMAC stub).
- `App\Http\Controllers\Lms\PublicVerificationController`.
- `App\Http\Controllers\Lms\Admin\CertificateAdminController`.
- `MeController::certificates()` and `CourseController::issueCertificate()`.
- Public route `GET /verify/{certificateNumber}`.
- `routes/lms.php` cert routes (`me/certificates`, `courses/{slug}/certificate`,
  `admin/certificates/{id}/revoke`).
- `config/lms.php` `certificate` block.

The `lms.certificate_admin` permission key is RETAINED (still seeded) so that
when certificates re-enter scope in a later module, no migration of role
permissions is needed.

## Follow-ups (out of Foundation)
1. Seed system `TenantRole` rows with names `tenant_admin`, `superadmin`,
   `user` if those don't already exist — `LmsPermissionsSeeder` is idempotent
   and will populate `lms.*` keys when those rows appear.
2. `learn.privasimu.com` 301 redirect at proxy/CDN layer.
3. Bulk content seeding from old fe-compliance-saas mock data.
4. Add child `MenuItem` rows under the `lms` menu for DPO Academy, Progress,
   Bookmarks, Notes, Admin — when those modules ship.

## What Foundation does NOT include
No course content, no functioning quiz logic, no leaderboard recompute, no
admin authoring, no certificate issuance — those land in subsequent module
specs (DPO Academy, Gamification, Learner-personal, Admin, feature-doc inline).
