# LMS Migration into priva-back

This file documents the integration of the LMS subsystem (originally in
`/Users/pupunn/Development/lms-privasimu/compliance-saas`) into priva-back.

## Origin
- Source repo: `lms-privasimu/compliance-saas` — archived read-only after
  this Foundation phase lands. See its final commit for historical context.

## Foundation phase deliverables
- 17 `lms_*` tables (videos, courses, modules, lessons, quizzes, quiz_questions,
  badges, xp_rules, user_module_progress, user_lesson_progress, quiz_attempts,
  user_badges, user_bookmarks, user_notes, certificates, xp_log, org_leaderboard).
- `App\Lms\*` namespace (Models + Services + Http helpers).
- `EnsureLmsEntitled` middleware (alias `lms.entitled`). Adapted to priva-back's
  3-layer visibility model: gate looks up `MenuItem(menu_key='lms')` then
  checks `tenant_module_entitlements(org_id, menu_id, is_entitled=true)`.
- `lms.*` permission keys (learner, content_admin, user_admin, certificate_admin)
  seeded onto system tenant_roles via `LmsPermissionsSeeder`.
- `MenuItem(menu_key='lms', label='Learn', href='/learn', section='Menu Utama')`
  seeded via `LmsMenuSeeder` — required precondition for the entitlement gate.
- Default XP rules seeded via `LmsXpRulesSeeder` (lesson_completed=10,
  quiz_passed=50, course_completed=200, quiz_perfect=25).
- All `/api/lms/*` endpoints registered (49+ routes), returning HTTP 501 stubs.
  Subsequent module specs replace them.
- Public `GET /verify/{certificateNumber}` route (also 501 stub).
- `CertificateSigningService` (HMAC-SHA256 stub — see follow-up).
- 9 phpunit feature tests covering middleware + signing + me/* stubs + public verify.

## Adaptations from the original spec
- The plan assumed `tenant_module_entitlements` had `module_key` + `enabled`
  columns. Reality: the table has `menu_id` (FK→`menu_items`) + `is_entitled`.
  The middleware was adapted to look up via `MenuItem.menu_key='lms'`.
  Strict-gate semantics preserved.
- `Organization` model gained `HasFactory` trait + a new
  `database/factories/OrganizationFactory.php` (needed for tests; pre-existing
  oversight).
- Certificate signing format: `{json}.<base64url(hmac)>` with raw JSON body
  rather than the originally-planned `b64u(json).b64u(hmac)`. HMAC integrity
  preserved; the change keeps payload human-readable. Interface
  (`sign`/`verify`/`decode`) is stable.

## Follow-ups (out of Foundation)
1. Swap `CertificateSigningService` HMAC stub to the asymmetric signer used
   by `licenses.signed_payload` once that signer is extracted into a shared
   `App\Services\Crypto\PayloadSigner`.
2. Seed system `TenantRole` rows with names `tenant_admin`, `superadmin`,
   `user` if those don't already exist — `LmsPermissionsSeeder` is idempotent
   and will populate `lms.*` keys when those rows appear.
3. `learn.privasimu.com` 301 redirect at proxy/CDN layer.
4. Bulk content seeding from old fe-compliance-saas mock data.
5. Add child `MenuItem` rows under the `lms` menu for DPO Academy, Progress,
   Bookmarks, Notes, Certificates, Admin — when those modules ship.

## What Foundation does NOT include
No course content, no functioning quiz logic, no leaderboard recompute, no
admin authoring — those land in subsequent module specs (DPO Academy,
Gamification, Learner-personal, Admin, feature-doc inline).
