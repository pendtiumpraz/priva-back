# UAT — User Acceptance Testing
## Privasimu Platform — Full Coverage

**Versi dokumen:** 1.0
**Tanggal disusun:** 2026-05-18
**Cakupan:** Seluruh modul Privasimu (50+ modul, 912+ endpoint API)
**Total skenario:** 260+ test cases (positive + negative + edge cases)

---

## Tujuan Dokumen

Dokumen ini adalah **acceptance test plan komprehensif** untuk validasi sebelum production release atau audit eksternal. Setiap skenario menjelaskan:

- **Modul** yang diuji
- **Role pengguna** yang dibutuhkan
- **Prasyarat** sebelum testing
- **Langkah-langkah** eksekusi
- **Hasil yang diharapkan**
- **Acceptance Criteria** (checklist)
- **Code audit** (link ke file:line di codebase)
- **Pass/Fail** field untuk dicentang tester

---

## Cara Menggunakan

1. **Setup environment:** Pastikan migration + seeder semuanya dijalankan (lihat [Test Environment Setup](#test-environment-setup) di bawah).
2. **Set role tester:** Per skenario sudah disebut role yang dibutuhkan (admin, dpo, maker, reviewer, approver, viewer, root, superadmin). Login dengan akun yang sesuai.
3. **Eksekusi sesuai langkah:** Ikuti urutan numbered steps. Jangan skip.
4. **Cek acceptance criteria:** Centang checkbox per item. Kalau ada yang gagal, isi Notes.
5. **Tandai Pass/Fail di akhir skenario.**
6. **Laporkan hasil:** Aggregate hasil per group ke `Sign-Off Matrix` di akhir dokumen.

---

## Test Environment Setup

**Backend:**
```bash
cd /home/sainsker/app-privasimu.esteh.id
git pull
php artisan migrate --force
php artisan db:seed --class=QuestionLibraryBackfillSeeder
php artisan db:seed --class=MenuRegistrySeeder
php artisan config:clear
php artisan cache:clear

# Queue worker untuk async AI features
php artisan queue:work --queue=default --tries=1 --timeout=200
```

**Frontend:** Vercel auto-deploy dari `main` branch.

**Test data:**
- Minimal 3 user accounts: 1 admin, 1 reviewer, 1 approver
- Minimal 1 organization dengan license aktif
- AI credits ≥ 100 untuk modul AI features
- Test database (PostgreSQL/MySQL) dengan tabel yang dijangkau Phase 1-4 TPRM + Discovery
- Optional: queue driver Redis untuk testing async screening

**Browser:** Chrome 120+, Firefox 119+, Edge 120+

---

## Table of Contents

1. [Group 1: Authentication + Dashboard + Holding Dashboard](#group-1)
2. [Group 2: RoPA (Record of Processing Activities)](#group-2)
3. [Group 3: DPIA + LIA + TIA + Maturity Assessment](#group-3)
4. [Group 4: GAP Assessment + Policy Review + Contract Review](#group-4)
5. [Group 5: DSR + Consent Management](#group-5)
6. [Group 6: TPRM Full Lifecycle (Library, Workflow, Screening, Monitoring, Incidents)](#group-6)
7. [Group 7: Data Discovery + Cross-Border + Document Import](#group-7)
8. [Group 8: Breach Management + Fire Drill + Security Posture (DSPM)](#group-8)
9. [Group 9: AI Agent + AI Credits + AI Features Cross-Module](#group-9)
10. [Group 10: Platform Admin + Tenant Settings + User Management](#group-10)
11. [Summary Matrix + Sign-Off](#sign-off)

---

<a id="group-1"></a>
# Group 1: Authentication + Dashboard + Holding Dashboard

**Cakupan:** Login/Logout/2FA/Register, Dashboard KPI + chart, Holding Dashboard org tree + compliance matrix.

**Estimasi waktu uji:** 1 jam (17 skenario × 3-5 menit each)

### S1.1: User Registration

**Modul:** Authentication
**Role:** Public (no auth)
**Prasyarat:**
- Email belum terdaftar
- Setting registration enabled

**Langkah:**
1. Buka halaman register
2. Isi nama, email, password (≥8 char, mixed case + angka), org name
3. Klik "Daftar"
4. Terima email verification link (jika setting required)

**Hasil yang Diharapkan:**
- Akun dibuat dengan role admin + tenant
- Email verification notification dikirim
- Response 201 dengan user data + token

**Acceptance Criteria:**
- [ ] Validasi password policy: min 8 char, mixed case + digit, tidak common password
- [ ] Email duplicate ditolak ("unique:users,email")
- [ ] Organization slug auto-generate dari org name + uniqid()
- [ ] Org level set ke 'holding' (line 50)
- [ ] Tenant role (Admin) assigned dari tenant_roles table
- [ ] Email verification notification dikirim
- [ ] Superadmin menerima notification "tenant.signup"
- [ ] Audit log entry created (kind=register)

**Code Audit:**
- Endpoint: `POST /api/auth/register` → `AuthController@register` (line 28)
- Frontend: `frontend/src/app/login/page.tsx` (Register form)
- DB: `users.email` unique, `users.password` hashed, `organizations.org_level='holding'`

**Pass/Fail:** [ ]
**Notes:** _________

---

### S1.2: Login Email + Password (No 2FA)

**Modul:** Authentication
**Role:** User authenticated
**Prasyarat:** Akun terdaftar + email verified, 2FA tidak enabled, is_active=true

**Langkah:**
1. Buka `/login`
2. Masukkan email + password
3. Klik "Login"
4. Redirect sesuai role (admin → /dashboard)

**Hasil yang Diharapkan:**
- Response 200 dengan user + token
- Token tersimpan di localStorage
- Redirect ke `/dashboard`
- `last_login_at` + login IP dicatat

**Acceptance Criteria:**
- [ ] Password validated via `Hash::check()` (line 166)
- [ ] Generic error: "Kredensial yang diberikan tidak cocok" (anti enum)
- [ ] Failed login attempt: `recordFailure()` (line 167)
- [ ] Counter reset on success: `recordSuccess()` (line 257)
- [ ] Email verification gate checked (line 211-217)
- [ ] Password rotation gate (line 221-227)
- [ ] `is_active=true` enforced (line 191-195)
- [ ] Session limit enforced (line 259, line 273-287)
- [ ] Token created `auth-token` (line 260)

**Code Audit:**
- Endpoint: `POST /api/auth/login` → `AuthController@login` (line 136)
- FE: `frontend/src/app/login/page.tsx:80-126`
- Services: `LoginAttemptService`, `PasswordPolicyService`

**Pass/Fail:** [ ]
**Notes:** _________

---

### S1.3: Login Dengan 2FA Enabled

**Modul:** Authentication
**Role:** User 2FA-confirmed
**Prasyarat:** Akun terdaftar + 2FA enabled + authenticator app setup

**Langkah:**
1. `/login` → input email + password
2. Backend issue challenge UUID (response 200, requires_2fa=true)
3. FE render 2FA form
4. User buka authenticator app, input 6-digit code
5. POST `/auth/2fa/verify` dengan challenge + code
6. Token issued → redirect `/dashboard`

**Hasil yang Diharapkan:**
- Step 3: response 200, requires_2fa=true, challenge UUID
- Step 6: response 200 dengan user + token
- Login counter reset SETELAH 2FA verify sukses

**Acceptance Criteria:**
- [ ] 2FA challenge issued (line 235)
- [ ] Challenge UUID time-limited
- [ ] TOTP code verified against `user.two_factor_secret` (line 299)
- [ ] Code validation: 6 digit numeric (line 341)
- [ ] Invalid code: error 422 (line 302, 347)
- [ ] Session limit enforced after verify (line 308)
- [ ] `recordSuccess()` AFTER 2FA, not after password (line 306)
- [ ] Setup token revoked if present (line 354-356)

**Code Audit:**
- Endpoints: `POST /api/auth/login` (line 232-255), `POST /api/auth/2fa/verify` (line 292-314)
- Services: `TwoFactorAuthService`

**Pass/Fail:** [ ]
**Notes:** _________

---

### S1.4: Login Gagal (Wrong Password) — Rate Limit + Lockout

**Prasyarat:** Akun exist + active, security.max_attempts=5, lockout_duration=900s

**Langkah:**
1. POST `/auth/login` dengan password SALAH 5×
2. Attempt 1-4: response 422
3. Attempt 5: response 423, locked=true, retry_after_seconds=900
4. FE persist lockSeconds di sessionStorage, countdown ditampilkan

**Acceptance Criteria:**
- [ ] `recordFailure()` increment counter (line 167)
- [ ] Lockout check SEBELUM password check (line 154-164) — anti timing
- [ ] Locked response include: locked=true, retry_after_seconds, locked_until
- [ ] Failed attempt logged dengan IP
- [ ] FE state in sessionStorage: `login_locked_until`
- [ ] Button disabled while lockSeconds > 0 (line 452)

**Code Audit:**
- Endpoint: `POST /api/auth/login` (line 136-266)
- Services: `LoginAttemptService::recordFailure(), lockedRetryAfter()`
- FE: `login/page.tsx:24-78`

**Pass/Fail:** [ ]
**Notes:** _________

---

### S1.5: Forgot Password + Reset

**Prasyarat:** Email registered

**Langkah:**
1. Buka halaman forgot password
2. Input email
3. POST `/auth/password-reset/request` (TODO verify endpoint)
4. Cek email → klik link reset
5. Input password baru
6. Submit → redirect `/login`

**Acceptance Criteria:**
- [ ] Generic response saat request (anti enum) — line 450-456
- [ ] Reset token + expiry 60 menit
- [ ] Password confirmation required
- [ ] Password policy enforced (PasswordPolicyService)
- [ ] `password_changed_at` updated
- [ ] Old tokens revoked
- [ ] Audit log: password_reset_success

**Pass/Fail:** [ ]
**Notes:** _________

---

### S1.6: Email Verification Flow

**Prasyarat:** Akun pending verification

**Langkah:**
1. Register → response requires_email_verification=true
2. Klik link di email: `/auth/email/verify/{id}/{hash}?signature=...`
3. Backend verify signature + hash
4. Response 200 atau redirect frontend dengan ?verified=1

**Acceptance Criteria:**
- [ ] Email verification notification sent (line 74)
- [ ] Hash validation: sha1(user.email) (line 415)
- [ ] Signature validation: hasValidSignature() (line 420)
- [ ] Link expired: response 403 (line 421)
- [ ] User already verified: idempotent (line 424-426)
- [ ] Email verified gate enforced on login (line 211-217)

**Code Audit:**
- Endpoint: `GET /api/auth/email/verify/{id}/{hash}` → `AuthController@verifyEmail` (line 410)
- Endpoint: `POST /api/auth/email/resend` (line 445)

**Pass/Fail:** [ ]
**Notes:** _________

---

### S1.7: Logout (Revoke Token)

**Langkah:**
1. POST `/auth/logout` dengan Bearer token
2. Backend revoke current token
3. FE clear localStorage + redirect `/login`

**Acceptance Criteria:**
- [ ] Token revocation: `currentAccessToken()->delete()` (line 578)
- [ ] Only current token revoked, not all sessions
- [ ] Subsequent request dengan token lama → 401
- [ ] FE clear `localStorage.auth_token`

**Code Audit:**
- Endpoint: `POST /api/auth/logout` (line 576)

**Pass/Fail:** [ ]
**Notes:** _________

---

### S1.8: Dashboard Load — Admin Role

**Langkah:**
1. Login → redirect `/dashboard`
2. Fetch GET `/api/dashboard/stats` + `/charts` + `/risk-analytics`
3. KPI cards render dengan correct values

**Acceptance Criteria:**
- [ ] Stats loaded < 2 detik
- [ ] Org scope enforced: `where('org_id', $request->user()->org_id)` (line 18)
- [ ] Latest GAP score from gap_assessments (line 21-25)
- [ ] DSR overdue: `deadline_at < now()` (line 50)
- [ ] Risk analytics: ROPA sorted by risk level (line 174-175)
- [ ] DPIA heatmap matrix (line 200-210)
- [ ] Breach timeline non-simulation + non-closed (line 263-269)

**Code Audit:**
- Endpoints: GET `/api/dashboard/stats` (line 16), `/charts` (line 84), `/risk-analytics` (line 161)
- Controller: `DashboardController`

**Pass/Fail:** [ ]
**Notes:** _________

---

### S1.9: Dashboard Load — Viewer Role

**Acceptance Criteria:**
- [ ] GET endpoints allow viewer role
- [ ] POST/PUT/DELETE endpoints reject viewer (403)
- [ ] No sensitive data leaked in JSON
- [ ] FE read-only mode (no edit buttons)

**Pass/Fail:** [ ]
**Notes:** _________

---

### S1.10: KPI Card Click-Through ke Module

**Langkah:**
1. Dashboard rendered
2. Klik KPI card "Total RoPA"
3. Navigate ke `/ropa` module
4. Verify list matches KPI count

**Acceptance Criteria:**
- [ ] KPI card clickable + navigates correctly
- [ ] Module list API filter by `org_id`
- [ ] Count consistent

**Pass/Fail:** [ ]
**Notes:** _________

---

### S1.11: Charts Time-Series Rendering

**Acceptance Criteria:**
- [ ] Monthly data last 7 months (line 90-114)
- [ ] Chart library render without error
- [ ] Responsive (mobile/tablet/desktop)
- [ ] Tooltip + legend clear

**Pass/Fail:** [ ]
**Notes:** _________

---

### S1.12: Holding Dashboard — Multi-Org Tree

**Langkah:**
1. `/holding-dashboard` → Tree tab
2. Tree shows holding at root + sub-holdings + subsidiaries
3. Expand/collapse via chevron

**Acceptance Criteria:**
- [ ] GET `/api/holding/org-tree` (line 16)
- [ ] Superadmin sees all (line 21-26)
- [ ] Holding admin only own branch (line 28-32)
- [ ] Descendants eager-loaded + user count (line 24)
- [ ] Tree depth-based indentation (line 744)
- [ ] Color coding levels (line 162, 737)

**Code Audit:**
- Endpoint: `GET /api/holding/org-tree` → `HoldingDashboardController@orgTree` (line 16)
- FE: `holding-dashboard/page.tsx:50, 701, 733-774`

**Pass/Fail:** [ ]
**Notes:** _________

---

### S1.13: Holding Compliance Matrix

**Acceptance Criteria:**
- [ ] GET `/api/holding/compliance-matrix` (line 72)
- [ ] `resolveOrgIds()` scope (line 44, 119-133)
- [ ] Sort gap_score asc (line 111)
- [ ] Filter org_level (line 139)
- [ ] Search name/industry (line 140)
- [ ] Pagination (line 59-60)
- [ ] GAP progress color: green ≥70%, orange ≥40%, red <40% (line 307)

**Pass/Fail:** [ ]
**Notes:** _________

---

### S1.14: Risk Analytics — DPIA Heatmap

**Acceptance Criteria:**
- [ ] Endpoint: GET `/api/dashboard/risk-analytics` (line 161)
- [ ] DPIA heatmap: likelihood × impact matrix (line 195-225)
- [ ] Unmitigated: riskScore ≥12 && no mitigation (line 215)
- [ ] Sort unmitigated by riskScore desc (line 229)
- [ ] Limit top-10 (line 230)

**Pass/Fail:** [ ]
**Notes:** _________

---

### E1.15: Edge Case — Session Expired

**Acceptance Criteria:**
- [ ] Token expiration enforced (Sanctum)
- [ ] 401 response on expired
- [ ] FE middleware catches 401 → logout + redirect
- [ ] No sensitive data on 401

**Pass/Fail:** [ ]
**Notes:** _________

---

### E1.16: Edge Case — No Access ke Holding Dashboard

**Langkah:** Subsidiary admin manually navigates ke `/holding-dashboard`

**Acceptance Criteria:**
- [ ] All requests return 403
- [ ] Org tree returns 403 if not holding (line 21, 28-31)
- [ ] Dashboard: `resolveOrgIds()` returns null (line 44-48)
- [ ] FE hide "Holding Dashboard" menu

**Pass/Fail:** [ ]
**Notes:** _________

---

### E1.17: Edge Case — Token Reuse Attack

**Acceptance Criteria:**
- [ ] IP allowlist enforcement (root/superadmin) (line 200-207)
- [ ] Per-token IP binding (optional enhancement)
- [ ] Rate limiting via `throttle:api` (line 126)
- [ ] Audit log unauthorized_access

**Pass/Fail:** [ ]
**Notes:** _________

---

**Group 1 Total:** 14 main + 3 edge cases = **17 scenarios**

---

<a id="group-2"></a>
# Group 2: RoPA (Record of Processing Activities)

**Cakupan:** 7-step wizard, auto-risk detection, section-level + full approval workflow, templates, export, link to systems.

**Estimasi waktu uji:** 2 jam (25 skenario)

### S2.1: Wizard Step 1 — Detail Pemrosesan

**Role:** maker, admin, dpo. **Prasyarat:** Permission `ropa:write`, belum ada draft dengan registration_number sama.

**Langkah:**
1. `/ropa` → "Tambah RoPA Baru" → wizard Step 1 form
2. Isi nama aktivitas, entitas, divisi, unit kerja, deskripsi
3. Klik "Lanjut ke Step 2"

**Hasil yang Diharapkan:**
- Code auto: `ROPA-YYYY-NNN` (atau `ROPA-KATEGORI-NNN` kalau category_id dipilih)
- `wizard_data.detail_pemrosesan` terisi, status=draft, progress=14.3%

**Acceptance Criteria:**
- [ ] Code format valid
- [ ] `wizard_data` JSON terstruktur per `WIZARD_SECTIONS[1]`
- [ ] `risk_level` auto-calculated via `RopaRiskCalculator` (default LOW)
- [ ] AuditLog entry created
- [ ] Progress = float rounded

**Code Audit:**
- Endpoint: `POST /api/m/ropa` → `ModuleCrudController@store` (line 407)
- Code gen: `nextCode()` (line 112)
- Risk: `applyRopaAutoRisk()` (line 180)
- Model: `Ropa::WIZARD_SECTIONS` (line 93-101)
- FE: `frontend/src/app/(dashboard)/ropa/page.tsx:92-100`

**Pass/Fail:** [ ]
**Notes:** _________

---

### S2.2: Wizard Step 2 — Data Protection Team/Officer

**Acceptance Criteria:**
- [ ] `wizard_data.dpo_team.dpo_list[]` + `pic_list[]` terisi
- [ ] `kategori_pemrosesan` field tersimpan
- [ ] Getter accessor fallback ke legacy single fields (line 166, 196)

**Code Audit:** WIZARD_SECTIONS[2] line 95

**Pass/Fail:** [ ]

---

### S2.3: Wizard Step 3 — Informasi Pemrosesan

**Acceptance Criteria:**
- [ ] purpose, jenis_pemrosesan, legal_basis tersimpan
- [ ] sistem_terkait array dengan {system_id, name, lokasi}
- [ ] `information_system_ropa` pivot terbuat (many-to-many)
- [ ] Legal basis saved untuk LIA auto-trigger

**Code Audit:** `syncRopaInformationSystems()` line 274; relation line 352

**Pass/Fail:** [ ]

---

### S2.4: Wizard Step 4 — Pengumpulan Data + Sensitive Detection

**Acceptance Criteria:**
- [ ] Sensitive keywords detected: kesehatan, biometrik, genetik, anak, keuangan, ras, etnis, agama, dll
- [ ] `wizard_data.risk_triggers.level='HIGH'` jika match
- [ ] Reason logged: "Pemrosesan data sensitif — kategori khusus per Pasal 4 UU PDP"

**Code Audit:** `RopaRiskCalculator::calculate()` line 66; SENSITIVE_KEYWORDS line 55

**Pass/Fail:** [ ]

---

### S2.5: Wizard Step 5 — Penggunaan & Penyimpanan

**Acceptance Criteria:**
- [ ] `cara_pemrosesan`, `lokasi_penyimpanan` tersimpan
- [ ] Lokasi cloud non-Indonesia → trigger MEDIUM risk

**Pass/Fail:** [ ]

---

### S2.6: Wizard Step 6 — Pengiriman Data (Cross-Border)

**Acceptance Criteria:**
- [ ] `transfer_domestik`, `transfer_internasional` boolean
- [ ] `negara_tujuan` stored
- [ ] `safeguards[]` array
- [ ] Cross-border flag → risk MEDIUM

**Pass/Fail:** [ ]

---

### S2.7: Wizard Step 7 — Retensi & Keamanan

**Acceptance Criteria:**
- [ ] `retensi_list[]` dengan {policy_id, scope_data_type, catatan}
- [ ] `retention_due_date` via `computeRetentionDueDate()` (line 32)
- [ ] `prosedur_pemusnahan`, `langkah_keamanan` terisi
- [ ] `getRetensiRowsAttribute()` resolver join dengan retention_policies (line 264)

**Pass/Fail:** [ ]

---

### S2.8: Auto Risk Detection — Section 4 Sensitive Data

**Langkah:** Isi jenis_data = "Rekam medis kesehatan pasien" → save.

**Acceptance Criteria:**
- [ ] `risk_level='HIGH'` otomatis
- [ ] `wizard_data.risk_triggers.triggers[]` includes `sensitive_data`
- [ ] Reason: "Pemrosesan data sensitif (kesehatan) — kategori khusus per Pasal 4 UU PDP"

**Pass/Fail:** [ ]

---

### S2.9: Save Draft Mid-Wizard

**Acceptance Criteria:**
- [ ] Partial wizard_data save allowed (no required-field block)
- [ ] `getSectionStatus()` return partial status per section
- [ ] Progress calculation handles partial fills
- [ ] AuditLog action='draft_saved'

**Pass/Fail:** [ ]

---

### S2.10: Edit RoPA Existing — Full Update

**Acceptance Criteria:**
- [ ] PATCH endpoint works
- [ ] `applyRopaAutoRisk()` called on PATCH
- [ ] wizard_data merged (not replace)
- [ ] AuditLog.changes tracks deltas
- [ ] Validation: status=approved → PATCH denied

**Pass/Fail:** [ ]

---

### S2.11: Auto-Create Draft DPIA — RoPA High-Risk

**Langkah:** Create RoPA dengan risk=HIGH (data sensitif). DPIA otomatis muncul.

**Acceptance Criteria:**
- [ ] Auto-DPIA triggered if `risk_level='high'` (line 575)
- [ ] dpia_ropa pivot row with ropa_id
- [ ] DPIA `wizard_data.informasi_dpia` pre-filled (description, dpo_name, dpo_email)
- [ ] `koneksi_ropa.connected_ropas[]` = [RoPA.id]
- [ ] Notification ke role:dpo via `NotificationService`
- [ ] Only 1 auto-DPIA per RoPA

**Code Audit:**
- Auto trigger: `ModuleCrudController@store` line 572-633
- DPIA creation: line 597-610
- Notification: line 614-628

**Pass/Fail:** [ ]

---

### S2.12: Submit RoPA for Approval

**Acceptance Criteria:**
- [ ] `RopaApprovalController::submit()` (line 25)
- [ ] Status: draft/revision → waiting (line 30)
- [ ] `submitted_at`, `submitted_by` set (line 34-37)
- [ ] `review_notes` cleared (line 38)
- [ ] AuditLog (line 41)
- [ ] Notification to each DPO in dpo_list

**Pass/Fail:** [ ]

---

### S2.13: DPO Approve Section

**Langkah:** DPO buka RoPA pending, expand Section 3, klik "Approve Section".

**Acceptance Criteria:**
- [ ] `isDPO()` check (line 385)
- [ ] RoPA status: waiting/revision/approved (line 181)
- [ ] Section key match `WIZARD_SECTIONS` (line 179)
- [ ] `upsertSectionApproval()` merges (line 327)
- [ ] All 7 sections approved → RoPA auto-promoted (line 196)
- [ ] AuditLog logged with section key

**Code Audit:** `RopaApprovalController::approveSection()` line 171

**Pass/Fail:** [ ]

---

### S2.14: DPO Reject Section with Notes

**Acceptance Criteria:**
- [ ] Notes required min:5 max:2000 (line 218)
- [ ] `upsertSectionApproval()` set status='revision' (line 228)
- [ ] RoPA.status → revision, approved_at/approved_by cleared (line 236-241)
- [ ] Notification ke maker (line 246)
- [ ] AuditLog action='section_rejected' (line 244)

**Code Audit:** `RopaApprovalController::rejectSection()` line 216

**Pass/Fail:** [ ]

---

### S2.15: Submit for Full Approval (All Sections)

**Acceptance Criteria:** Sama dengan S2.12 (full submit endpoint).

**Pass/Fail:** [ ]

---

### S2.16: Apply RoPA Template

**Acceptance Criteria:**
- [ ] `RopaTemplateController::show()` (line 58) loads + increment usage_count
- [ ] Template.wizard_data structure match RoPA schema
- [ ] is_system=true visible to all orgs (line 61)
- [ ] org-specific only to that org (line 64)
- [ ] usage_count incremented (line 70)
- [ ] Search + filter industry (line 27-29, 41)

**Pass/Fail:** [ ]

---

### S2.17: Filter + Search RoPA List

**Acceptance Criteria:**
- [ ] `ModuleCrudController::index()` (line 327)
- [ ] Search columns: registration_number, processing_activity, division, description (line 345)
- [ ] Filter status, risk_level
- [ ] Cursor pagination `cursorPaginate()` (line 396)
- [ ] per_page param respected (line 394)

**Pass/Fail:** [ ]

---

### S2.18: Soft-Delete + Restore

**Acceptance Criteria:**
- [ ] `SoftDeletes` trait (line 13)
- [ ] Index respects trash filter (line 334-336)
- [ ] DELETE soft-deletes
- [ ] Restore endpoint
- [ ] AuditLog for delete + restore

**Pass/Fail:** [ ]

---

### S2.19: Force-Delete (Admin Only)

**Acceptance Criteria:**
- [ ] Endpoint `?force=true` atau dedicated
- [ ] Permission: superadmin OR admin with ropa:force_delete
- [ ] Cascading deletes (information_system_ropa, dpia_ropa)
- [ ] AuditLog 'force_deleted'

**Pass/Fail:** [ ]

---

### S2.20: View RoPA History + Audit Log

**Acceptance Criteria:**
- [ ] `Ropa::auditLogs()` relation desc (line 370)
- [ ] AuditLog.changes JSON stores deltas
- [ ] AuditLog.section field populated
- [ ] FE HistoryPanel renders timeline

**Pass/Fail:** [ ]

---

### S2.21: Link RoPA ke Information System

**Acceptance Criteria:**
- [ ] `LazySearchSelect` queries InformationSystem
- [ ] `syncRopaInformationSystems()` (line 274) handles array
- [ ] `information_system_ropa` pivot created
- [ ] `Ropa::informationSystems()` relation (line 352)
- [ ] `getSistemListAttribute()` accessor (line 230)

**Pass/Fail:** [ ]

---

### S2.22: Export RoPA to PDF/Excel

**Acceptance Criteria:**
- [ ] DocumentDownloadModal component (FE)
- [ ] TemplateExportController (BE)
- [ ] `buildSectionsFor()` (line 29 FE)
- [ ] Approval history rendered if status ≠ draft

**Pass/Fail:** [ ]

---

### S2.NEG-1: Missing Permission

**Acceptance Criteria:** `checkPermission()` line 73-78 returns 403 if not in permissions.

**Pass/Fail:** [ ]

---

### S2.NEG-2: Invalid Retention Date

**Acceptance Criteria:** `computeRetentionDueDate()` (line 32-64) handle invalid durations gracefully, return null.

**Pass/Fail:** [ ]

---

### S2.NEG-3: Concurrent Edit Conflict

**Acceptance Criteria:**
- [ ] `updated_at` timestamp on model (optimistic lock candidate)
- [ ] Validate updated_at on PATCH (TODO verify)
- [ ] AuditLog logs both user actions

**Pass/Fail:** [ ]

---

**Group 2 Total:** 22 main + 3 negative = **25 scenarios**

---

<a id="group-3"></a>
# Group 3: DPIA + LIA + TIA + Maturity Assessment

**Cakupan:** DPIA framework + 5×5 risk matrix + RTP, LIA RACI workflow, TIA from cross-border/vendor, Maturity Assessment 33 indikator.

**Estimasi waktu uji:** 2.5 jam (27 skenario)

### S3.1: Auto-trigger DPIA from High-Risk ROPA

**Acceptance Criteria:**
- [ ] DPIA tercipta otomatis (no user action)
- [ ] `source_ropa_id` field terisi
- [ ] Code format `DPIA-YYYY-NNN`
- [ ] AuditLog `type: dpia.auto_created`

**Code Audit:** Trigger `ModuleCrudController::store` line 572-620

**Pass/Fail:** [ ]

---

### S3.2: Manual Create DPIA + Wizard

**Acceptance Criteria:**
- [ ] DPIA code format auto-generated
- [ ] Wizard 3 sections (informasi_dpia, koneksi_ropa, potensi_risiko) accessible
- [ ] Risk matrix renders 5×5 grid
- [ ] Risk events from templates selectable atau manual
- [ ] Control mitigation dropdown 1-3 scale

**Code Audit:** Routes `/api/m/dpia`; Model `Dpia::WIZARD_SECTIONS` line 42-46

**Pass/Fail:** [ ]

---

### S3.3: Risk Matrix 5×5 Input

**Acceptance Criteria:**
- [ ] `dampak` 1-5, `probabilitas` 1-5, `kontrol` 1-3 validated
- [ ] Risk score = (dampak × probabilitas) / kontrol, rounded
- [ ] Color coding: 1-5=green, 6-13=yellow, 14-25=red
- [ ] Frontend MaturityRuler component

**Pass/Fail:** [ ]

---

### S3.4: Assign Mitigation per Risk

**Acceptance Criteria:**
- [ ] penanganan ∈ {mitigate, accept, transfer, terminate}
- [ ] Owner user_id stored
- [ ] Due_date nullable ISO 8601
- [ ] Risk treatment inherited into RTP

**Code Audit:** `DpiaRtpController` valid treatments line 37-39

**Pass/Fail:** [ ]

---

### S3.5: RTP (Risk Treatment Plan) per DPIA

**Acceptance Criteria:**
- [ ] RTP items created from risk_events status=planned
- [ ] Status transitions per TRANSITIONS matrix (line 27-35)
- [ ] planned→in_progress→implemented→verified (no jump)
- [ ] Overdue status auto-set kalau due_date < now()
- [ ] AuditLog `dpia_rtp.status_changed`

**Code Audit:** Routes `/api/dpia/{id}/rtp/*`; auto-overdue line 50-56

**Pass/Fail:** [ ]

---

### S3.6: Submit DPIA for DPO Approval

**Acceptance Criteria:**
- [ ] Status: draft → submitted
- [ ] `is_locked = true`
- [ ] approver_id assigned
- [ ] AuditLog created
- [ ] DPO notification

**Pass/Fail:** [ ]

---

### S3.7: DPO Approve/Reject DPIA

**Acceptance Criteria:**
- [ ] Only dpo role or approver_id=user.id can approve
- [ ] Approve: status=approved, approver_id set, approved_at timestamp
- [ ] Reject: status=rejected, rejection_reason filled, is_locked → false
- [ ] Audit trail both paths

**Pass/Fail:** [ ]

---

### S3.8: Edit Assessment Framework (Categories + Risks)

**Acceptance Criteria:**
- [ ] Only dpo can edit (permission:dpia,write)
- [ ] CRUD operations validate required fields
- [ ] `is_active` flag for soft-disable
- [ ] Sequence numbering controls sort
- [ ] Framework per org_id

**Code Audit:** `DpiaAssessmentFrameworkController`; routes `/api/dpia/framework/categories/*` (line 381-389)

**Pass/Fail:** [ ]

---

### S3.9: Risk Event Templates Apply

**Acceptance Criteria:**
- [ ] GET `/api/dpia/risk-event-templates` paginated
- [ ] Template fields: `{id, risk_event, description, is_active, template_type}`
- [ ] User can override values
- [ ] `template_id` persisted in `wizard_data.potensi_risiko[cat].risk_events[i].template_id`

**Code Audit:** Route line 375-376; `DpiaRiskEventTemplateController`

**Pass/Fail:** [ ]

---

### S3.10: Export DPIA to PDF

**Acceptance Criteria:**
- [ ] PDF endpoint 200 + `application/pdf`
- [ ] All wizard_data sections included
- [ ] Risk matrix visualized heatmap
- [ ] RTP items shown
- [ ] Branding applied (org logo)
- [ ] Watermark "DRAFT" if status ≠ approved

**Pass/Fail:** [ ]

---

### S3.11: Create LIA from Scratch

**Acceptance Criteria:**
- [ ] LIA code: `LIA-[unit]-[activity]-NN` auto-generated unique per org
- [ ] status=draft, is_locked=false
- [ ] maker_id from current_user
- [ ] Wizard data includes purpose_test, necessity_test, balancing_test
- [ ] Can save draft mid-wizard

**Code Audit:** `LiaController::store()` line 83-95

**Pass/Fail:** [ ]

---

### S3.12: Create LIA from Existing RoPA

**Acceptance Criteria:**
- [ ] Snapshot taken at LIA create time (not dynamic)
- [ ] 13 fields from `RoPA_AUTOFILL_FIELDS` (line 40-54)
- [ ] linked_ropa_id ≠ null
- [ ] `ropa_snapshot.snapshot_taken_at = now()`
- [ ] Multiple LIA can link same RoPA

**Code Audit:** `LiaController::fromRopa()` line 101+; snapshot logic line 72-94

**Pass/Fail:** [ ]

---

### S3.13: RACI Workflow (Maker → Checker → Approver)

**Acceptance Criteria:**
- [ ] Only maker can submit() (status=draft + is_locked=false)
- [ ] Only checker (checker_id=user.id) can call check()
- [ ] Only approver (approver_id=user.id) can call approve()
- [ ] State transitions strict: draft→submitted→checked→approved
- [ ] Rejection reverses lock (is_locked → false)
- [ ] Re-submit after rejection resets submitted_at, checked_at, approved_at
- [ ] Audit entries for each verdict

**Code Audit:** Routes line 1243-1246; state constants `LiaAssessment::STATUS_*`

**Pass/Fail:** [ ]

---

### S3.14: Export LIA PDF

**Acceptance Criteria:**
- [ ] PDF renders all wizard_data sections
- [ ] Signatures only if approved
- [ ] File name `LIA-{lia_code}-{status}.pdf`
- [ ] Searchable text (not image)

**Pass/Fail:** [ ]

---

### S3.15: Unlock LIA (Root/Admin)

**Acceptance Criteria:**
- [ ] Only root/admin can unlock
- [ ] is_locked → false
- [ ] status remains unchanged (approved)
- [ ] unlocked_by + unlocked_at recorded
- [ ] Audit `type: emergency_unlock`

**Code Audit:** Route POST /api/lia/{id}/unlock line 1247

**Pass/Fail:** [ ]

---

### S3.16: Create TIA from Cross-Border

**Acceptance Criteria:**
- [ ] TIA code `TIA-[country-code]-[activity]-NN` unique
- [ ] linked_cross_border_id populated
- [ ] Snapshot: destination_country, activity_name, legal_basis, recipients
- [ ] Wizard pre-fills destination country info
- [ ] Risk metrics 6+2 input section ready

**Code Audit:** Route POST `/api/tia/from-cross-border/{cbtId}` line 1259

**Pass/Fail:** [ ]

---

### S3.17: Create TIA from Vendor (TPRM)

**Acceptance Criteria:**
- [ ] Auto-trigger only if vendor.risk_level ∈ {high, critical} OR is_data_processor=true
- [ ] TIA code includes vendor reference
- [ ] Snapshot: vendor_name, country, risk_level, contractual_terms
- [ ] Risk metrics pre-populate dengan vendor.risk_score

**Code Audit:** Route POST `/api/tia/from-vendor/{vendorId}` line 1260

**Pass/Fail:** [ ]

---

### S3.18: 6 Risk Metrics + 2 Security Metrics Input

**Acceptance Criteria:**
- [ ] All 6 risk metrics 1-10 validated
- [ ] Both security metrics 1-10 validated
- [ ] Residual risk formula (rumus produksi, lihat note di bawah):
  - `raw_risk = avg(risk_metrics)` — rentang 1-10
  - `mitigation = avg(security_metrics) / 10` — rentang 0.1-1.0
  - `overall_risk_score = raw_risk * (1 - mitigation * 0.5)` rounded 2 decimals
- [ ] `overall_risk_level = scoreToLevel(overall_risk_score)`
- [ ] Persist to `tia.risk_*`, `tia.security_*` fields

**Note rumus:** Versi awal spec memakai `(avg(risk) - avg(security)) / 2` yang
bisa menghasilkan nilai negatif. Implementasi produksi memakai residual risk
classic (raw_risk × mitigation_factor) yang non-negative, bounded `[0.5, 10]`,
dan mengikuti pola ISO 31000 / NIST RMF. Mitigation factor 0.5 berarti
security control yang sempurna (avg=10) mengurangi 50% dari raw risk,
sesuai konvensi konservatif untuk TIA UU PDP Pasal 56.

**Code Audit:** `TiaAssessment::RISK_METRIC_KEYS` line 65-72; SECURITY_METRIC_KEYS line 75-80; formula line 225-236

**Pass/Fail:** [ ]

---

### S3.19: TIA RACI Workflow

**Acceptance Criteria:** Same as S3.13 (state transitions identical untuk TIA).

**Code Audit:** Routes line 1268-1271

**Pass/Fail:** [ ]

---

### S3.20: Export TIA PDF

**Acceptance Criteria:**
- [ ] PDF includes all wizard_data sections
- [ ] Risk/security metrics as table
- [ ] Overall risk score + level visualized
- [ ] Approver info if approved
- [ ] Watermark "DRAFT" if status < approved

**Pass/Fail:** [ ]

---

### S3.21: Maturity Self-Evaluation 33 Indikator

**Acceptance Criteria:**
- [ ] 33 questions seeded
- [ ] Domains: governance, data_subject_rights, security, dll
- [ ] Score range 1-10 validated
- [ ] Notes nullable max 2000
- [ ] `overall_score` recomputed on each upsert
- [ ] Progress indicator

**Code Audit:** Route `POST /api/maturity/{id}/responses` line 1292; `MaturityController::upsertResponse()` line 92+

**Pass/Fail:** [ ]

---

### S3.22: Auto-Derive from ROPA/DPIA

**Acceptance Criteria:**
- [ ] Auto-derive queries ROPA, DPIA, Vendor, Consent, Breach tables
- [ ] Scoring algorithm presence/completeness → maturity level
- [ ] All 33 responses created source='auto_derive'
- [ ] auto_derived_at = now()
- [ ] auto_derive_metadata logged
- [ ] Responses remain editable

**Code Audit:** `MaturityAutoDeriveService::derive()`; route POST `/api/maturity/{id}/auto-derive` line 1294

**Pass/Fail:** [ ]

---

### S3.23: Submit + Publish Maturity

**Acceptance Criteria:**
- [ ] Submit validates all 33 responses exist
- [ ] Transitions strict: draft→submitted→published (no reverse)
- [ ] submitted_at, published_at timestamped
- [ ] Recommendations array populated (low-scoring areas)
- [ ] AuditLog `maturity.submitted` + `maturity.published`

**Code Audit:** Routes line 1295-1296; level mapping `scoreToLevel()` line 67-73

**Pass/Fail:** [ ]

---

### S3.24: Export Maturity PDF

**Acceptance Criteria:**
- [ ] PDF includes all 33 Q&A
- [ ] Overall level clearly displayed
- [ ] Domain scores shown
- [ ] Recommendations section included
- [ ] File name `Maturity-{assessment_id}-{date}.pdf`

**Pass/Fail:** [ ]

---

### S3.25 (NEG): Submit DPIA Without Complete Wizard

**Acceptance Criteria:** 422 if any section incomplete; status remains draft; user sees validation error.

**Pass/Fail:** [ ]

---

### S3.26 (NEG): Approve/Reject Without DPO Permission

**Acceptance Criteria:** 403 if not approver_id or no dpo role; state unchanged.

**Pass/Fail:** [ ]

---

### S3.27 (NEG): Edit LIA After Locked

**Acceptance Criteria:** Cannot PUT/PATCH locked LIA; 403 or 422 returned; unlock endpoint required.

**Pass/Fail:** [ ]

---

**Group 3 Total:** 24 main + 3 negative = **27 scenarios**

---

<a id="group-4"></a>
# Group 4: GAP Assessment + Policy Review + Contract Review

**Cakupan:** 33 indikator UU PDP + AI evidence analyzer (1 credit/analysis, cached by hash), Policy Review + AI analyze, Contract Review + clause-by-clause AI.

**Estimasi waktu uji:** 2 jam (30 skenario)

### S4.1: Create GAP Assessment Baru

**Role:** admin, dpo, maker. **Prasyarat:** Permission `gap_assessment:write`, no unfinished assessment.

**Acceptance Criteria:**
- [ ] 33 indikator UU PDP pre-populated
- [ ] Version format `GAP_v3.0_UUPDP_#N`
- [ ] Cooldown warning kalau last < 90 days
- [ ] 409 conflict kalau unfinished exists
- [ ] AuditLog create

**Code Audit:** `GapAssessmentController.php:134-199`; route `POST /api/gap` line 311

**Pass/Fail:** [ ]

---

### S4.2: Jawab 33 Indikator (Y/T/Partial)

**Acceptance Criteria:**
- [ ] All 33 questions fetched
- [ ] Custom org questions merged
- [ ] Y=100%, Partial=50%, T=0%
- [ ] Compliance level: high (≥80%), medium (50-79%), low (<50%)
- [ ] Progress = answered_count / 33 * 100
- [ ] Category breakdown calculated
- [ ] Recommendations populated

**Code Audit:** `questions()` line 99-129; `submitAnswers()` line 226-260

**Pass/Fail:** [ ]

---

### S4.3: Upload Evidence per Indicator

**Acceptance Criteria:**
- [ ] File >10MB rejected (422)
- [ ] Format not in [pdf, docx, xlsx] rejected
- [ ] Empty file (0 bytes) rejected
- [ ] MIME type mismatch detected
- [ ] Multiple uploads per question allowed
- [ ] File path stored + accessible

**Code Audit:** `uploadEvidence()` line 314-373; FileUploadValidator PRESET_MATURITY_EVIDENCE; route line 317

**Pass/Fail:** [ ]

---

### S4.4: AI Evidence Analyzer (1 Credit, Cached)

**Acceptance Criteria:**
- [ ] Cache hit returns instantly, 0 credit
- [ ] Cache miss calls AI (temperature 0.2, JSON-only)
- [ ] 1 credit deducted on cache miss only
- [ ] 7-day cache TTL (`CACHE_TTL_SECONDS`)
- [ ] Max doc chars 8000 (TPM guard)
- [ ] Compliance_status: comply/partial_comply/non_comply/unsure

**Code Audit:** `analyzeEvidence()` line 390-482; `AiDocumentAnalyzer.php:58-78`; route line 319

**Pass/Fail:** [ ]

---

### S4.5: Image Evidence → AI Skip (No Credit)

**Acceptance Criteria:**
- [ ] jpg/png/gif/webp/bmp/tiff rejected (422 at upload)
- [ ] Error message Bahasa Indonesia
- [ ] No credit charged

**Code Audit:** `AiDocumentAnalyzer::IMAGE_EXTENSIONS` line 43; check line 93

**Pass/Fail:** [ ]

---

### S4.6: Custom Question CRUD per Assessment

**Acceptance Criteria:**
- [ ] Create: regulation_code, category, question required
- [ ] Weight optional default 1.0
- [ ] sort_order auto-incremented
- [ ] Custom merged dengan platform questions
- [ ] Org-scoped (org_id filter)
- [ ] Update preserves created_at
- [ ] Delete cascades or soft-deletes

**Code Audit:** `GapAssessmentController:512-576`; routes line 306-309

**Pass/Fail:** [ ]

---

### S4.7: Submit Assessment

**Acceptance Criteria:**
- [ ] Progress calculated correctly
- [ ] Score breakdown per category
- [ ] Compliance level assigned
- [ ] Recommendations populated
- [ ] Approval workflow initiated
- [ ] created_by/submitted_by tracked

**Code Audit:** `submitAnswers()` line 226-260; route line 313

**Pass/Fail:** [ ]

---

### S4.8: Approve Assessment (DPO)

**Acceptance Criteria:**
- [ ] DPO cannot approve own assessments
- [ ] Approval notes stored
- [ ] Status locked after approval
- [ ] Audit trail complete
- [ ] Creator notified

**Pass/Fail:** [ ]

---

### S4.9: Comparison/Benchmarking 2 Assessment

**Acceptance Criteria:**
- [ ] `compare()` returns comparison matrix
- [ ] Category breakdown per assessment
- [ ] Improvement delta calculated
- [ ] Only same regulation comparisons
- [ ] Handle custom questions in comparison

**Code Audit:** `GapAssessmentController:61-94`; route `GET /api/gap/compare` line 300

**Pass/Fail:** [ ]

---

### S4.10: Export GAP Report PDF

**Acceptance Criteria:**
- [ ] PDF generated successfully
- [ ] Filename `GAP_Assessment_{org}_{date}.pdf`
- [ ] Charts render correctly
- [ ] Sanitized confidential data
- [ ] In-browser download

**Code Audit:** TemplateExportController routes line 1466-1467

**Pass/Fail:** [ ]

---

### S4.11: Create Policy Review — Upload PDF/DOCX

**Acceptance Criteria:**
- [ ] File validation (mimes:pdf,docx,doc, max:10240)
- [ ] PDF/DOCX text extraction
- [ ] Policy type auto-detected
- [ ] AI analysis initiated (1 credit)
- [ ] Result cached in DB
- [ ] Document accessible via storage

**Code Audit:** `AiFeatureController:1793-1870` `policyReview()`; route line 1187

**Pass/Fail:** [ ]

---

### S4.12: Paste Text Manual Policy

**Acceptance Criteria:**
- [ ] Text validation min 50 chars
- [ ] Title optional auto-generate
- [ ] doc_type dropdown provided
- [ ] Same AI flow as file upload
- [ ] Storage: text in DB, no file system

**Pass/Fail:** [ ]

---

### S4.13: AI Analyze Policy (Compliance Score)

**Acceptance Criteria:**
- [ ] AI response parsed successfully (JSON-only)
- [ ] Compliance score 0-100
- [ ] Findings array with severity levels
- [ ] Articles matched to UU PDP (Pasal 35, 36, dst)
- [ ] Credit deduction atomic on success

**Code Audit:** `policyAnalyze()` line 905-980; credit gate line 915+

**Pass/Fail:** [ ]

---

### S4.14: List + Filter Policy Reviews

**Acceptance Criteria:**
- [ ] Only non-deleted returned
- [ ] Org-scoped
- [ ] Sort created_at DESC
- [ ] Compliance score visible
- [ ] Pagination if >100

**Code Audit:** `PolicyReviewCrudController:12-22` `index()`; route line 547

**Pass/Fail:** [ ]

---

### S4.15: Soft-Delete + Trashed View

**Acceptance Criteria:**
- [ ] deleted_at column exists
- [ ] Soft delete doesn't break FKs
- [ ] Trashed view separate endpoint
- [ ] Restore reverses
- [ ] Force-delete purges
- [ ] Permissions on delete/restore/force

**Code Audit:** `PolicyReviewCrudController:50-83`; routes line 545-551

**Pass/Fail:** [ ]

---

### S4.16: Create Contract Review — Upload

**Acceptance Criteria:**
- [ ] File validation
- [ ] Text extraction successful
- [ ] Stored path returned
- [ ] Tenant storage isolation
- [ ] Contract type optional default "vendor"

**Code Audit:** `AiFeatureController:1654-1750` `contractUpload()`; route line 1184

**Pass/Fail:** [ ]

---

### S4.17: Paste Text Manual Contract

**Acceptance Criteria:**
- [ ] Text validation min 50 chars
- [ ] contract_type dropdown
- [ ] Clause relevance filtered by type
- [ ] Same AI flow as file upload

**Pass/Fail:** [ ]

---

### S4.18: AI Analyze Contract (Clause-by-Clause)

**Acceptance Criteria:**
- [ ] Clause analysis per UU PDP (8 clauses, type-aware)
- [ ] Compliance status per clause
- [ ] Risk scoring based on severity
- [ ] Recommendations actionable
- [ ] JSON parsing defensive

**Code Audit:** `contractAnalyze()` line 833-904; `UuPdpClauseRelevanceService`

**Pass/Fail:** [ ]

---

### S4.19: Overall Rating + Risk Score

**Acceptance Criteria:**
- [ ] Overall rating: compliant/partial/non_compliant
- [ ] Risk score 0-100 dengan severity color
- [ ] Per-clause breakdown visible
- [ ] Visual feedback clear

**Pass/Fail:** [ ]

---

### S4.20: Stream Analysis Real-Time Progress

**Acceptance Criteria:**
- [ ] SSE atau chunked transfer
- [ ] Progress events semantic
- [ ] Final event contains complete result
- [ ] Backward compatible non-streaming
- [ ] Note: may not be implemented (advanced feature)

**Pass/Fail:** [ ]

---

### N4.1 (NEG): Upload >10MB → 422

**Code Audit:** Validation `file|max:10240` lines 318, 844, 1665, 1812

**Pass/Fail:** [ ]

---

### N4.2 (NEG): Unsupported Format (.exe, .zip) → 422

**Code Audit:** PRESET_MATURITY_EVIDENCE allows only [pdf, docx, xlsx]

**Pass/Fail:** [ ]

---

### N4.3 (NEG): AI Analyze Without Credit → 402

**Acceptance Criteria:** 402 Payment Required, message Bahasa Indonesia, `credits_exhausted: true` flag.

**Code Audit:** `GapAssessmentController:455-463`

**Pass/Fail:** [ ]

---

### N4.4 (NEG): Attachment Path Tampering

**Acceptance Criteria:** 404 if attachment path mismatch with assessment.

**Code Audit:** Line 427-440 ownership verification

**Pass/Fail:** [ ]

---

### N4.5 (NEG): Duplicate Custom Question Names — Allowed

**Acceptance Criteria:** No unique constraint on text, by design.

**Pass/Fail:** [ ]

---

### N4.6 (NEG): Image Upload to Policy/Contract

**Acceptance Criteria:** 422, mimes:pdf,docx,doc.

**Pass/Fail:** [ ]

---

### N4.7 (NEG): Assessment Unfinished Guard

**Acceptance Criteria:** 409 Conflict, message includes unfinished progress %.

**Code Audit:** `GapAssessmentController:138-158`

**Pass/Fail:** [ ]

---

### N4.8 (NEG): Non-Existent Assessment → 404

**Pass/Fail:** [ ]

---

### N4.9 (NEG): Cross-Org Data Access

**Acceptance Criteria:** All queries filtered by org_id; 404 on cross-org access.

**Pass/Fail:** [ ]

---

### N4.10 (NEG): Missing Required Fields

**Acceptance Criteria:** 422 with field-level error messages.

**Pass/Fail:** [ ]

---

**Group 4 Total:** 20 main + 10 negative = **30 scenarios**

---

<a id="group-5"></a>
# Group 5: DSR + Consent Management

**Cakupan:** DSR public widget submission + workflow + SQL pack + certificate, Consent collection + cookie banner Phase B + CRM extract Phase F.

**Estimasi waktu uji:** 2.5 jam (25 skenario)

### S5.1: Public DSR Submission via Widget

**Role:** Public. **Prasyarat:** DSR App active dengan embed_token.

**Acceptance Criteria:**
- [ ] DSR code DSR-YYYY-NNN auto-generated
- [ ] deadline_at = now + 72 jam
- [ ] verification_token (random 64 char)
- [ ] verification_expires_at = now + 24 jam
- [ ] Email dispatched (queued)
- [ ] Anti-duplicate: 1 active DSR per email per app

**Code Audit:** `DsrPublicController@submit` line 75-189; CAPTCHA `CaptchaVerifier@verifyForApp()` line 107; scope seed `seedScopesFromApp()` line 195-221

**Pass/Fail:** [ ]

---

### S5.2: Email Verification via Subject Link

**Acceptance Criteria:**
- [ ] Verification idempotent
- [ ] 24h token expiry enforced
- [ ] Invalid/expired token → 404/410
- [ ] HTML + JSON response both supported
- [ ] Event broadcast `EVENT_VERIFIED`

**Code Audit:** `verify()` line 249-287; response variants line 512-546

**Pass/Fail:** [ ]

---

### S5.3: Admin DSR CRUD (Internal Dashboard)

**Acceptance Criteria:**
- [ ] List filterable by org_id + status + app_id
- [ ] Detail hydrates relations
- [ ] requester_email decrypted (EncryptedString cast)
- [ ] Status audit trail in AuditLog

**Code Audit:** Routes `/api/m/dsr` line 359-361; encryption line 36-39

**Pass/Fail:** [ ]

---

### S5.4: Scope Picker — Assign Information Systems

**Acceptance Criteria:**
- [ ] GET `/api/dsr/{id}/scopes` returns current scopes
- [ ] GET `/available-systems` returns IS list + flags
- [ ] POST `/scopes` bulk-assign (upsert)
- [ ] PUT `/scopes/{scopeId}` update shards
- [ ] DELETE `/scopes/{scopeId}` remove

**Code Audit:** `DsrRequestScopeController@index()` line 30-74; `availableSystems()` line 81-100+

**Pass/Fail:** [ ]

---

### S5.5: Affected RoPAs View

**Acceptance Criteria:** RoPA filter by IS dalam scope; many-to-many resolution.

**Code Audit:** Route `GET /api/dsr/{id}/affected-ropas` line 949

**Pass/Fail:** [ ]

---

### S5.6: SQL Pack Generation

**Acceptance Criteria:**
- [ ] Pre-check verification_status='verified' + scopes>0
- [ ] Response: file_count, total_size_bytes, manifest, download_url
- [ ] Storage via TenantStorageService
- [ ] Status `sql_pack_status = 'generated'`
- [ ] Event `EVENT_SQL_PACK_READY` emitted

**Code Audit:** `DsrSqlPackController@generate()` line 34-86; pre-checks line 40-49

**Pass/Fail:** [ ]

---

### S5.7: Download SQL Pack ZIP

**Acceptance Criteria:**
- [ ] Stream via StreamedResponse
- [ ] 404 if pack not generated
- [ ] Audit trail timestamp

**Code Audit:** `download()` line 92-100+; route name `dsr.sql_pack.download` line 931

**Pass/Fail:** [ ]

---

### S5.8: Per-Shard Execution Tracking

**Acceptance Criteria:**
- [ ] Status in: pending, executed, failed, skipped
- [ ] Update sets executed_at automatically
- [ ] rows_affected optional integer
- [ ] AuditLog: shard, request_type, from/to status
- [ ] Auto-complete DSR if `all_complete()` returns true

**Code Audit:** `DsrExecutionController@index()` line 37-70; `update()` line 72-127; `maybeCompleteDsr()` line 120

**Pass/Fail:** [ ]

---

### S5.9: Evidence Upload per Execution

**Acceptance Criteria:**
- [ ] File types: pdf, png, jpg, txt, csv, log
- [ ] Max 10 MB
- [ ] Per-tenant storage
- [ ] AuditLog `dsr.upload_evidence`

**Code Audit:** `uploadEvidence()` line 129-150+

**Pass/Fail:** [ ]

---

### S5.10: Generate Certificate of Completion

**Acceptance Criteria:**
- [ ] Auto-generated on completion
- [ ] Regenerate idempotent
- [ ] Kind: subject|internal
- [ ] PDF includes org logo, timestamps, digital stamp

**Code Audit:** `regenerateCertificates()` line 944; `DsrCertificateService` line 33

**Pass/Fail:** [ ]

---

### S5.11: Resend Verification Email

**Acceptance Criteria:**
- [ ] 422 if already verified
- [ ] 422 if DSR final status
- [ ] AuditLog `dsr.resend_verification`
- [ ] _dev_verify_url for debugging

**Code Audit:** `DsrVerificationController@resend()` line 32-82

**Pass/Fail:** [ ]

---

### S5.12: Manual Verify (DPO Override)

**Acceptance Criteria:**
- [ ] reason required min 10 chars
- [ ] verified_via stored
- [ ] Cannot manual-verify if already verified
- [ ] Tracks verified_by_user_id, verified_by_email, ip, reason

**Code Audit:** `manualVerify()` line 94-135

**Pass/Fail:** [ ]

---

### S5.13: DSR Apps CRUD (Multi-app)

**Acceptance Criteria:**
- [ ] app_code auto-derived atau manual, unique per org
- [ ] embed_token auto-generated
- [ ] allowed_domains array of patterns
- [ ] default_assignee_user_id from same org
- [ ] AuditLog per operation

**Code Audit:** `DsrAppController` lines 44-192; routes line 955+

**Pass/Fail:** [ ]

---

### S5.14: Embed Token Regeneration

**Acceptance Criteria:**
- [ ] Old token invalidated
- [ ] New snippet ready
- [ ] Cache busted
- [ ] AuditLog warning logged

**Code Audit:** `regenerateToken()` line 198-215

**Pass/Fail:** [ ]

---

### S5.15: Deadline Alert (72h Reminder)

**Acceptance Criteria:**
- [ ] Scheduled command `dsr:deadline-alerts`
- [ ] Only alert once per DSR
- [ ] Respects email throttling

**Pass/Fail:** [ ]

---

### S5.16: Create Consent Collection Point

**Acceptance Criteria:**
- [ ] collection_id auto-generated atau manual
- [ ] embed_token unique
- [ ] kind in: cookie_banner, app_consent
- [ ] locale: id, en
- [ ] display_mode, display_frequency stored
- [ ] webhook_url optional validated
- [ ] AuditLog

**Code Audit:** Routes `/api/m/consent` line 359-361; Model line 12-80+

**Pass/Fail:** [ ]

---

### S5.17: Add Consent Items

**Acceptance Criteria:**
- [ ] category: essential, analytics, marketing, functional, custom
- [ ] cookie_keys array
- [ ] legal_basis stored
- [ ] is_required (essential always true)
- [ ] Cache invalidation on save
- [ ] AuditLog

**Code Audit:** `ConsentItemController` line 24; routes line 999-1001

**Pass/Fail:** [ ]

---

### S5.18: Public Consent Capture via Widget

**Acceptance Criteria:**
- [ ] collection_id resolved by token/id/UUID
- [ ] purpose_keys denormalized from consented_items
- [ ] Rate limit 30/min IP, 60/min collection
- [ ] CAPTCHA optional
- [ ] Cache lookup `consent:collection:{id}` (10 min)
- [ ] AuditLog `consent.capture`

**Code Audit:** `capture()` line 186-297; webhook + CRM jobs line 270-291

**Pass/Fail:** [ ]

---

### S5.19: Cookie Banner Phase B (Anonymous Tracking)

**Acceptance Criteria:**
- [ ] kind validation cookie_banner
- [ ] visitor_id 8-80 chars
- [ ] choices.necessary ALWAYS true
- [ ] Rate limit per visitor + per IP
- [ ] expires_at = now + 90 days (configurable)
- [ ] state + withdraw endpoints

**Code Audit:** `CookieCaptureController@capture()` line 27-98

**Pass/Fail:** [ ]

---

### S5.20: Consent Logs Audit Trail

**Acceptance Criteria:**
- [ ] Filterable: collection_id, user_identifier, email, source_form, purpose_keys, country, date
- [ ] purpose_keys: WHERE LIKE %"key"%
- [ ] Org-scoped
- [ ] Limit 1000 rows

**Code Audit:** `ConsentLogController@index()` line 27-75

**Pass/Fail:** [ ]

---

### S5.21: Webhook on Consent Update

**Acceptance Criteria:**
- [ ] webhook_url optional validated
- [ ] Async dispatch (`FireConsentWebhookJob`)
- [ ] Retry 3 attempts (60s, 120s, 300s)
- [ ] 2xx response within 30s
- [ ] Non-2xx → retry

**Pass/Fail:** [ ]

---

### S5.22: CRM Credentials CRUD (Encryption Check)

**Acceptance Criteria:**
- [ ] api_key/api_secret NEVER returned plaintext (masked only)
- [ ] EncryptedString cast
- [ ] probe() integration test
- [ ] Empty strings don't overwrite (line 56-60)
- [ ] AuditLog

**Code Audit:** `CrmCredentialController` line 1-107

**Pass/Fail:** [ ]

---

### S5.23: CRM Extract — Sync Consent from External

**Acceptance Criteria:**
- [ ] Preview counts matching records
- [ ] Sample 5 random rows
- [ ] CSV target: returns 201 stream
- [ ] CRM target: creates ExtractRun + dispatches job
- [ ] org_id enforced
- [ ] AuditLog

**Code Audit:** `ConsentExtractController` preview line 28-50; run line 56-100+

**Pass/Fail:** [ ]

---

### S5.24: Multi-Language Consent Widget

**Acceptance Criteria:**
- [ ] locale field (id|en) stored
- [ ] ConsentItem title/description multi-language (JSONB)
- [ ] Endpoint returns locale + translated strings
- [ ] Widget auto-switches by Accept-Language

**Pass/Fail:** [ ]

---

### S5.25: Consent Preference Center

**Acceptance Criteria:**
- [ ] Route accessible no auth
- [ ] Require email verification (OTP) before changes
- [ ] Show current consent state
- [ ] Allow preference updates
- [ ] New ConsentLog on submit (audit trail)
- [ ] Webhook fired

**Pass/Fail:** [ ]

---

### N5.1 (NEG): Public Submission without Valid Embed Token → 404

**Code Audit:** `DsrPublicController@submit` line 87-89

**Pass/Fail:** [ ]

---

### N5.2 (NEG): Consent Capture Rate Limit → 429

**Code Audit:** Line 197-201, key `consent-capture:{ip}`

**Pass/Fail:** [ ]

---

### N5.3 (NEG): CRM Credentials Encrypted (No Plaintext Leak)

**Code Audit:** `CrmCredential` EncryptedString casts

**Pass/Fail:** [ ]

---

**Group 5 Total:** 25 main + 3 negative = **28 scenarios**

---

<a id="group-6"></a>
# Group 6: TPRM Full Lifecycle

**Cakupan:** Library bank pertanyaan, 2-step wizard, public assessment, workflow Maker→Reviewer→Approver (3-stage), evidence + adjustment audit, AI Screening (sync/async/bulk + context preset), Monitoring berkala + checklist + decision, Incident report + apply-to-risk, Assessment history.

**Estimasi waktu uji:** 4 jam (49 skenario — modul paling besar)

### S6.1: Clone Library Template PDP

**Acceptance Criteria:**
- [ ] Library baru org-scoped, source=cloned + cloned_from_library_id
- [ ] All segments + 56 questions copied
- [ ] is_locked=false (editable)
- [ ] AuditLog `tprm.library_clone`

**Code Audit:** `POST /api/tprm/libraries/{id}/clone` → `TprmLibraryController@clone` (route line 671)

**Pass/Fail:** [ ]

---

### S6.2: Create Blank Library

**Acceptance Criteria:**
- [ ] source=custom, segments_count=0, questions_count=0
- [ ] is_locked=false
- [ ] No default segments

**Code Audit:** `POST /api/tprm/libraries` → `store()` route line 664

**Pass/Fail:** [ ]

---

### S6.3: Add Custom Segment

**Acceptance Criteria:**
- [ ] QuestionLibrarySegment created
- [ ] library.segments_count auto-incremented
- [ ] Visible in segments list by order_index

**Code Audit:** `POST /api/tprm/libraries/{id}/segments` route line 668

**Pass/Fail:** [ ]

---

### S6.4: Add Question — yes_no

**Acceptance Criteria:**
- [ ] VendorQuestionnaire created dengan answer_type=yes_no
- [ ] library_id + library_segment_id linked
- [ ] is_active=true
- [ ] requires_evidence_upload flag respected

**Code Audit:** `POST /api/tprm/questions` route line 648

**Pass/Fail:** [ ]

---

### S6.5: Add Question — multi_choice

**Acceptance Criteria:**
- [ ] options JSON serialized + stored
- [ ] Public form renders dropdown
- [ ] Vendor answer stored as matching value

**Pass/Fail:** [ ]

---

### S6.6: Bulk Import Questions (CSV)

**Acceptance Criteria:**
- [ ] CSV parsed validated
- [ ] Sort order preserved
- [ ] Malformed CSV rejected 422
- [ ] Duplicate question_code per segment rejected

**Code Audit:** `POST /api/tprm/libraries/{id}/import-questions` route line 676

**Pass/Fail:** [ ]

---

### S6.7: Reorder Questions Drag-Drop

**Acceptance Criteria:**
- [ ] sort_order updated correctly
- [ ] AuditLog `tprm.questions_reorder`
- [ ] Drag-drop disabled on locked

**Code Audit:** `POST /api/tprm/libraries/{id}/reorder` route line 678

**Pass/Fail:** [ ]

---

### S6.8: Delete Library + Cascade

**Acceptance Criteria:**
- [ ] Library.deleted_at set
- [ ] Segments + questions kept (audit)
- [ ] Hidden from index
- [ ] Template (is_locked) cannot be deleted by tenant

**Pass/Fail:** [ ]

---

### S6.9: Wizard 2-Step — Detail Pihak Ketiga

**Acceptance Criteria:**
- [ ] All fields display correctly
- [ ] name required + unique
- [ ] privacy_policy_url validated as URL
- [ ] File uploads optional
- [ ] "Next" disabled if required empty

**Code Audit:** `VendorQuestionnaireWizard.tsx`

**Pass/Fail:** [ ]

---

### S6.10: Upload Akta Notaris + Kontrak + CP

**Acceptance Criteria:**
- [ ] Max 5 MB per file, PDF/DOCX/XLSX
- [ ] Multiple files (drag-drop)
- [ ] Removal works
- [ ] Step 2 submit triggers POST `/vendor-risk/{id}/intake-documents`

**Pass/Fail:** [ ]

---

### S6.11: Select Library Dropdown in Wizard

**Acceptance Criteria:**
- [ ] Dropdown lists active org libraries + visible templates
- [ ] Default = PDP template (locked + visible)
- [ ] Locked template marked "(Template platform)"
- [ ] Question count matches library.questions_count

**Pass/Fail:** [ ]

---

### S6.12: Generate Public Link

**Acceptance Criteria:**
- [ ] public_token UUID
- [ ] public_link_expires_at = now + 30 days
- [ ] AuditLog `tprm.assessment_share`
- [ ] Expired token returns 410

**Pass/Fail:** [ ]

---

### S6.13: Vendor Fills 56+ Questions via Tabs

**Acceptance Criteria:**
- [ ] GET `/asesmen-publik/{token}/` returns 56 questions
- [ ] Grouped by segment (section field)
- [ ] POST `/jawaban` stores batch + saved count
- [ ] File upload validation
- [ ] Progress bar updates real-time
- [ ] Free tab navigation (no lock until submit)
- [ ] Persist on refresh

**Code Audit:** Routes line 178-180 `AsesmenPublikController`

**Pass/Fail:** [ ]

---

### S6.14: Vendor Submit → Auto-Calculate Risk Score

**Acceptance Criteria:**
- [ ] (yes_answers / total) × 100 = risk_score
- [ ] Risk levels: low (>80), medium (60-80), high (40-60), critical (<40)
- [ ] Token consumed (cannot reuse)
- [ ] Review inbox shows pending
- [ ] Assessment history logged

**Code Audit:** `submit()` route line 181; `ThirdPartyAssessmentScorer`

**Pass/Fail:** [ ]

---

### S6.15: Reviewer Claim Review

**Acceptance Criteria:**
- [ ] Status → review_in_progress
- [ ] assigned_reviewer_id = current_user_id
- [ ] Other reviewers see "Sedang di-review oleh {name}"
- [ ] AuditLog `tprm.review_started`

**Code Audit:** `POST /api/tprm/review/start-review/{id}` → `TprmReviewController@startReview` route line 714

**Pass/Fail:** [ ]

---

### S6.16: Reviewer Adjust Jawaban + Audit Log

**Acceptance Criteria:**
- [ ] vendor_assessment_adjustments table append-only
- [ ] Original answer preserved
- [ ] Assessment score recalculated instantly
- [ ] UI shows green "✓ adjusted to ..." badge
- [ ] Revision history visible

**Code Audit:** `POST /api/tprm/review/adjust/{id}` → `adjust()` route line 716; migration `2026_05_16_140003`

**Pass/Fail:** [ ]

---

### S6.17: Reviewer Submit-to-Approver

**Acceptance Criteria:**
- [ ] Status → pending_approval
- [ ] assigned_approver_id populated
- [ ] AuditLog `tprm.review_submitted`
- [ ] Approver inbox shows assessment

**Code Audit:** route line 718

**Pass/Fail:** [ ]

---

### S6.18: Reviewer Reject-to-Vendor

**Acceptance Criteria:**
- [ ] Status: review_in_progress → sent
- [ ] New public_token generated
- [ ] Previous answers preserved (vendor resume)
- [ ] AuditLog `tprm.review_rejected`
- [ ] Email notification dengan vendor name + reason + new link

**Code Audit:** route line 720

**Pass/Fail:** [ ]

---

### S6.19: Approver Setuju (Final + Sync Vendor Risk)

**Acceptance Criteria:**
- [ ] status=approved, workflow_locked=true
- [ ] Vendor.risk_score + risk_level updated
- [ ] AuditLog with approver_id + timestamp
- [ ] No further edits (PUT/PATCH returns 422)
- [ ] Monitoring inbox shows "Set Schedule" task

**Pass/Fail:** [ ]

---

### S6.20: Approver Tolak dengan rejection_reason

**Acceptance Criteria:**
- [ ] Status: pending_approval → rejected
- [ ] rejection_reason stored + displayed
- [ ] Reviewer notified

**Pass/Fail:** [ ]

---

### S6.21: Approver Return-to-Reviewer

**Acceptance Criteria:**
- [ ] Status: pending_approval → review_in_progress
- [ ] assigned_reviewer_id preserved (same reviewer)
- [ ] Notification includes approver note

**Code Audit:** route line 720

**Pass/Fail:** [ ]

---

### S6.22: Approver Reopen Final Assessment

**Acceptance Criteria:**
- [ ] workflow_locked = false
- [ ] Status reset review_in_progress
- [ ] Only super_admin/approver can reopen (403 reviewer)
- [ ] AuditLog reopen event

**Pass/Fail:** [ ]

---

### S6.23: Run Screening Sinkron (Legacy)

**Acceptance Criteria:**
- [ ] Sources: web_search, privacy_policy, documents, sanctions
- [ ] Request blocks until complete (5-30s)
- [ ] HTTP 200 + full result
- [ ] findings JSON structured
- [ ] risk_score 0-100
- [ ] AuditLog `tprm.screening_run`

**Code Audit:** `POST /api/vendor-risk/{id}/screen` → `VendorScreeningController::run` route line 623

**Pass/Fail:** [ ]

---

### S6.24: Run Screening Async (Queue + Polling)

**Acceptance Criteria:**
- [ ] HTTP 202 response with screening_id + status_url
- [ ] Polling endpoint returns current status
- [ ] Job completes + updates `VendorScreening.status=completed`
- [ ] UI shows "Screening selesai"
- [ ] Error handling: job failure → status=failed
- [ ] Polling timeout >5 min → retry option

**Pass/Fail:** [ ]

---

### S6.25: Pick Context Preset (Sektor)

**Acceptance Criteria:**
- [ ] Preset dropdown lists 5+ presets
- [ ] Selected preset passed in request
- [ ] AI findings emphasize sector-specific risks
- [ ] VendorScreening.ai_context logs preset used

**Code Audit:** `AiContextPresets::ALL_KEYS`

**Pass/Fail:** [ ]

---

### S6.26: Custom AI Context Paragraph

**Acceptance Criteria:**
- [ ] Custom context saved to system_settings
- [ ] On screening: prepended to AI prompt
- [ ] UI shows "Using custom AI context"
- [ ] Reset clears + saves

**Pass/Fail:** [ ]

---

### S6.27: Async Screening via Queue

**Acceptance Criteria:**
- [ ] ProcessVendorScreeningJob enqueued
- [ ] Status: pending → running → completed
- [ ] Multiple screenings queued simultaneously
- [ ] Queue worker concurrency configurable

**Pass/Fail:** [ ]

---

### S6.28: Notif Risk Increase at Re-screen

**Acceptance Criteria:**
- [ ] Threshold configurable (default 20)
- [ ] Notification only if delta > threshold
- [ ] Email includes old/new scores, change %, findings summary
- [ ] Admin adjusts threshold in settings
- [ ] Notification audit logged

**Pass/Fail:** [ ]

---

### S6.29: Bulk Re-screen 10+ Vendors

**Acceptance Criteria:**
- [ ] Endpoint accepts array of vendor_ids
- [ ] Each vendor: separate VendorScreening row + job
- [ ] Batch polling endpoint
- [ ] UI shows count completed/failed/pending

**Pass/Fail:** [ ]

---

### S6.30: Auto-Prompt Set Monitoring After Approve

**Acceptance Criteria:**
- [ ] VendorMonitoring row created (is_active=false initially)
- [ ] UI modal/toast prompting setup
- [ ] User sets frequency 3/6/12 months + assignee
- [ ] On save: is_active=true, next_due_at = now + frequency_months

**Pass/Fail:** [ ]

---

### S6.31: Set Schedule 3/6/12 Bulan

**Acceptance Criteria:**
- [ ] frequency_months stored (3/6/12)
- [ ] next_due_at = now().addMonths(frequency_months)
- [ ] Appears in monitoring inbox dengan correct status
- [ ] assigned_user_id populated

**Code Audit:** `POST /api/tprm/monitoring` route line 732

**Pass/Fail:** [ ]

---

### S6.32: Complete Periodic Review (Checklist + Decision)

**Acceptance Criteria:**
- [ ] VendorMonitoringReview created + visible in history
- [ ] next_due_at recalculated
- [ ] reviews_count incremented
- [ ] If decision=terminate: Vendor.status changed + monitoring deactivated
- [ ] AuditLog full review data

**Code Audit:** `POST /api/tprm/monitoring/{id}/complete` route line 736

**Pass/Fail:** [ ]

---

### S6.33: Decision Terminate → Auto Deactivate Schedule

**Acceptance Criteria:**
- [ ] VendorMonitoring.is_active = false
- [ ] Monitoring no longer in inbox
- [ ] Vendor status changed inactive/terminated

**Pass/Fail:** [ ]

---

### S6.34: Inbox Monitoring Filter Overdue/Due/Upcoming

**Acceptance Criteria:**
- [ ] GET `/api/tprm/monitoring/inbox?filter=...` filters correctly
- [ ] Count badges accurate
- [ ] Sort: overdue first
- [ ] Status badge color: red/yellow/gray

**Code Audit:** route line 728

**Pass/Fail:** [ ]

---

### S6.35: Laporkan Insiden Vendor

**Acceptance Criteria:**
- [ ] VendorIncident created with all fields
- [ ] status=open default
- [ ] applied_to_risk_score=false
- [ ] Notification to admin
- [ ] AuditLog `tprm.incident_reported`

**Code Audit:** `POST /api/tprm/incidents` → `TprmIncidentController@store` route line 743

**Pass/Fail:** [ ]

---

### S6.36: Update Incident Status + Resolution

**Acceptance Criteria:**
- [ ] Status transitions tracked (open → investigating → resolved)
- [ ] resolved_at timestamp set when status=resolved
- [ ] Resolution notes stored
- [ ] AuditLog status change history

**Pass/Fail:** [ ]

---

### S6.37: Apply impact_score_delta to Vendor

**Acceptance Criteria:**
- [ ] Vendor.risk_score = old + delta (capped at 100)
- [ ] Vendor.risk_level recalculated
- [ ] VendorIncident.applied_to_risk_score = true
- [ ] Can only apply once (422 on second)
- [ ] AuditLog delta + timestamp

**Code Audit:** `POST /api/tprm/incidents/{id}/apply-risk` route line 747

**Pass/Fail:** [ ]

---

### S6.38: View Assessment History Panel + Timeline

**Acceptance Criteria:**
- [ ] GET `/api/vendor-risk/{id}/assessments/history` chronological
- [ ] Each row: date, score, status, approver, adjustments count
- [ ] Click expands details
- [ ] Trend chart (if implemented)

**Pass/Fail:** [ ]

---

### S6.39: Tombol Re-asesmen > 12 Bulan

**Acceptance Criteria:**
- [ ] Banner muncul ketika last assessment >12 bulan
- [ ] "Mulai Asesmen Baru" trigger Wizard dengan existingVendor prop
- [ ] Wizard pre-fills vendor name + fields
- [ ] Click "Buat" creates new VendorAssessment

**Pass/Fail:** [ ]

---

### S6.40: TprmSubNav Pill Bar

**Acceptance Criteria:**
- [ ] Pills: Daftar, Bank Pertanyaan, Antrian Review, Antrian Approval, Monitoring, Insiden
- [ ] Active pill highlight
- [ ] Click navigates without reload
- [ ] Icons align with feature
- [ ] Mobile responsive

**Code Audit:** `frontend/src/components/tprm/TprmSubNav.tsx` line 1-80

**Pass/Fail:** [ ]

---

### N6.1 (NEG): Vendor Token Expired → 410

**Pass/Fail:** [ ]

---

### N6.2 (NEG): Library Locked (Template) Cannot Edit → 403

**Pass/Fail:** [ ]

---

### N6.3 (NEG): Workflow Transition Invalid → 422

**Pass/Fail:** [ ]

---

### N6.4 (NEG): Apply Risk Already Applied → 422

**Pass/Fail:** [ ]

---

**Group 6 Total:** 40 main + 4 negative = **49 scenarios**

---

<a id="group-7"></a>
# Group 7: Data Discovery + Cross-Border + Document Import

**Cakupan:** DB scan, AI deep scan, leak detection, decryptor profiles, AI patrol, person scan, OCR, protection assessment; Cross-border country adequacy + TIA trigger; Document import batch + AI field mapping.

**Estimasi waktu uji:** 2 jam (28 skenario)

### S7.1: Connect Database & Test Connection

**Acceptance Criteria:**
- [ ] Connection test return DB version
- [ ] Password encrypted di connection_config JSON
- [ ] Clear error: host unreachable, auth failed, port invalid
- [ ] Timeout 10s

**Code Audit:** `POST /api/data-discovery/{id}/test-connection` → `DataDiscoveryController::testConnection()` line 31; `DatabaseScanner::testMysql()` line 64-84

**Pass/Fail:** [ ]

---

### S7.2: Standard Scan (Regex PII Detection)

**Acceptance Criteria:**
- [ ] Min 1 table found + scanning_status='done'
- [ ] PII columns detected (email, phone, id_number regex)
- [ ] Diff alerts (new table/column/PII)
- [ ] Encryption scan optional (wrapped try/catch)

**Code Audit:** `triggerScan()` line 95; `ColumnAutoAssigner::mergePreserveUserEdits()` line 124, autoAssign line 129

**Pass/Fail:** [ ]

---

### S7.3: AI Deep Scan (LLM Classification)

**Acceptance Criteria:**
- [ ] AI only on PII columns from standard scan
- [ ] Fallback to standard scan kalau AI fails (no credit charge)
- [ ] DB::disconnect() sebelum AI call (line 705) — shared hosting fix
- [ ] Error detail kalau AI return invalid JSON (line 720-728)
- [ ] Payload error kalau standard scan belum dilakukan (line 665-666)

**Code Audit:** `scanAi()` line 660; compact schema line 676-694

**Pass/Fail:** [ ]

---

### S7.4: Image Evidence Skip (AI Skip JPG/PNG)

**Acceptance Criteria:**
- [ ] Image files (jpg, png) skipped dengan log warning
- [ ] PDF di-OCR via OcrScannerService
- [ ] Hasil OCR merged ke schema

**Code Audit:** `scanUnstructured()` line 1499

**Pass/Fail:** [ ]

---

### S7.5: Manual Classify Column (Override)

**Acceptance Criteria:**
- [ ] applied_status updated to manual decision
- [ ] applied_by=user_id, applied_note='manual_classify'
- [ ] Decision preserved saat scan ulang (line 76-77 mergePreserveUserEdits)
- [ ] Audit log classify action

**Code Audit:** `PUT /api/data-discovery/{id}/classify-column` line 257; apply line 338

**Pass/Fail:** [ ]

---

### S7.6: ColumnAutoAssigner Mark 'ai_scan' After Deep Scan

**Acceptance Criteria:**
- [ ] applied_note='ai_scan' set after AI review (line 756)
- [ ] Column dengan applied_note ≠ 'auto_scan' tidak di-reset saat scan berikutnya

**Pass/Fail:** [ ]

---

### S7.7: Counter "Otomatis (belum direview)" Turun

**Acceptance Criteria:**
- [ ] FE filter `applied_note = 'auto_scan' AND applied_by IS NULL`
- [ ] Counter auto-update setelah AI scan response

**Pass/Fail:** [ ]

---

### S7.8: AI Text-to-SQL Search

**Acceptance Criteria:**
- [ ] AI generates SQL dari schema metadata ONLY (no data)
- [ ] SQL parameterized (?, no string concat)
- [ ] Two-step: generate → execute
- [ ] Rate-limited 5 req/min ai-search/execute (line 842)

**Code Audit:** `specificSearchAi()` line 823; `specificSearchExecute()` line 882

**Pass/Fail:** [ ]

---

### S7.9: Execute Generated SQL + View Results

**Acceptance Criteria:**
- [ ] SQL executed parameterized (safe from injection)
- [ ] Result capped 100 rows
- [ ] Sensitive columns masked per permission
- [ ] Audit log: user, query, result_count

**Pass/Fail:** [ ]

---

### S7.10: Reveal Masked PII (Permission Gated)

**Acceptance Criteria:**
- [ ] Permission: data_discovery:reveal
- [ ] Audit log entry created
- [ ] 2FA optional (org-configurable)

**Code Audit:** `POST /api/data-discovery/scan-results/{id}/reveal` line 910

**Pass/Fail:** [ ]

---

### S7.11: Leak Detection — Input Leak Columns

**Acceptance Criteria:**
- [ ] AI matches leak columns to scanned schema
- [ ] Exact match if all in order
- [ ] Fuzzy match if subset
- [ ] Confidence score returned

**Code Audit:** `leakMatchSchema()` line 862; schema compact line 960-970

**Pass/Fail:** [ ]

---

### S7.12: Leak Detection — Exact vs Fuzzy Confidence

**Acceptance Criteria:**
- [ ] match_mode: exact (90-100), fuzzy (60-90)
- [ ] Each candidate ranked by confidence

**Pass/Fail:** [ ]

---

### S7.13: Decryptor Profile CRUD

**Acceptance Criteria:**
- [ ] Key wrapped before storage (encrypted_key field)
- [ ] API response NEVER expose raw key (`$hidden = ['encrypted_key']`)
- [ ] CRUD routes line 850-854
- [ ] Test endpoint POST /{id}/decryptor-profiles/{profileId}/test (line 854)

**Code Audit:** Model `DecryptorProfile` line 1-26

**Pass/Fail:** [ ]

---

### S7.14: Daily AI Patrol — Schedule + Changelog

**Acceptance Criteria:**
- [ ] Endpoint `POST /api/data-discovery/{id}/patrol-config` (line 884)
- [ ] Changelog entries auto-created (line 882)
- [ ] Schedule persists across deployments

**Pass/Fail:** [ ]

---

### S7.15: Discovery Changelog View

**Acceptance Criteria:**
- [ ] Endpoint `GET /api/data-discovery/{id}/changelogs` (line 882)
- [ ] Paginated response
- [ ] Sortable by timestamp

**Pass/Fail:** [ ]

---

### S7.16: Person Scan — Search Data Subject

**Acceptance Criteria:**
- [ ] Endpoint `GET /api/data-discovery/search-dsr/subject` (line 830)
- [ ] Query across ALL org systems with completed scans
- [ ] Return: table + column + found_count (masked, no actual data)
- [ ] Execution <30s (3 systems max in loop)

**Code Audit:** `searchSubject()` line 595-642; `DatabaseScanner::searchSubject()` line 628

**Pass/Fail:** [ ]

---

### S7.17: OCR Scan for Unstructured Files

**Acceptance Criteria:**
- [ ] OcrScannerService processes image/PDF
- [ ] Text extraction + AI classification
- [ ] Image (jpg, png) skipped dengan warning

**Code Audit:** `scanUnstructured()` line 877

**Pass/Fail:** [ ]

---

### S7.18: Protection Assessment (Security Posture)

**Acceptance Criteria:**
- [ ] GET `/api/data-discovery/{id}/protection-assessment` line 872
- [ ] Save endpoint PUT line 873
- [ ] AI endpoint POST line 874
- [ ] Methods: `getProtectionAssessment()`, `saveProtectionAssessment()`, `aiProtectionAssessment()` line 1316+

**Pass/Fail:** [ ]

---

### S7.19: List Country Adequacy

**Acceptance Criteria:**
- [ ] Endpoint `GET /api/cross-border/countries` line 780
- [ ] Filter by country_name / country_code
- [ ] Filter by tier (adequacy, sccs, none)
- [ ] Sorted by tier + country_name

**Code Audit:** `CrossBorderController::listCountries()` line 368-389

**Pass/Fail:** [ ]

---

### S7.20: Create Cross-Border Transfer Record

**Acceptance Criteria:**
- [ ] POST `/api/cross-border` line 785
- [ ] Validate fields
- [ ] status default 'draft'
- [ ] Auto-TIA triggered (line 52-57)
- [ ] AuditLog `cross_border, transfer_created`

**Pass/Fail:** [ ]

---

### S7.21: Country Lookup by Code/Name

**Acceptance Criteria:**
- [ ] Endpoint `GET /api/cross-border/countries/{codeOrName}` line 781
- [ ] Resolve by code OR name
- [ ] tier_label provided for FE display
- [ ] Return null if not found → tier='none'

**Code Audit:** `resolveCountry()` line 391-406

**Pass/Fail:** [ ]

---

### S7.22: Trigger TIA from Cross-Border

**Acceptance Criteria:**
- [ ] POST `/api/cross-border/{id}/assessTIA` line 796
- [ ] Two modes: ai (with fallback) | manual (rubric)
- [ ] Rubric scoring line 244-303
- [ ] Legal basis mapping: adequacy/bcr +15pt, sccs +10pt, none -25pt (line 266-272)
- [ ] Auto-status: high/critical → pending, else → approved
- [ ] review_due_at set +1 year

**Code Audit:** `assessTIA()` line 139-229

**Pass/Fail:** [ ]

---

### S7.23: Legacy TIA Inline

**Acceptance Criteria:** Backwards compat endpoint POST line 796.

**Pass/Fail:** [ ]

---

### S7.24: Upload Single Document

**Acceptance Criteria:**
- [ ] Endpoint `POST /api/documents/upload` line 1620
- [ ] Validate max:51200 (50MB), mimes:docx,xlsx,xls,csv,pdf
- [ ] Validate target_module in:ropa,dpia
- [ ] status='queued', progress=0
- [ ] Storage via TenantStorageService (line 38)
- [ ] Job dispatched (line 57)
- [ ] Audit log (line 60)

**Code Audit:** `DocumentImportController::upload()` line 26-73

**Pass/Fail:** [ ]

---

### S7.25: Batch Upload Multiple Documents

**Acceptance Criteria:**
- [ ] Endpoint `POST /api/documents/batch-upload` line 1621
- [ ] File limit: 20 (cloud) or 100 (on-premise) — env DEPLOYMENT_MODE
- [ ] Batch name required
- [ ] Each file validated individually
- [ ] All files + jobs queued in one request

**Code Audit:** `batchUpload()` line 78-131

**Pass/Fail:** [ ]

---

### S7.26: AI Field Mapping Auto-Detect

**Acceptance Criteria:**
- [ ] ImportDocumentJob calls AI mapping service
- [ ] mapped_fields auto-populated with confidence scores
- [ ] Status transitions: queued → parsing → analyzing → mapping → review → creating → completed

**Code Audit:** `ImportDocumentJob::createRecordFromMapping()` line 196

**Pass/Fail:** [ ]

---

### S7.27: Admin Approve Mapping per Import

**Acceptance Criteria:**
- [ ] Endpoint `PUT /api/documents/imports/{id}/approve` line 1624
- [ ] Check status=review before allow (line 187)
- [ ] Call `createRecordFromMapping()`
- [ ] Return created_record_id

**Code Audit:** `approve()` line 182-209

**Pass/Fail:** [ ]

---

### S7.28: Edit Mapping Manual + Re-process

**Acceptance Criteria:**
- [ ] Endpoint `PUT /api/documents/imports/{id}/edit-mapping` line 1625
- [ ] mapped_fields as array (line 217)
- [ ] Only allow status='review' or 'mapping' (line 223)
- [ ] AuditLog `mapping_edited`

**Code Audit:** `editMapping()` line 214-233

**Pass/Fail:** [ ]

---

### NT7.1 (NEG): Test Connection — Wrong Password

**Acceptance Criteria:** 422/400, error message dari DB.

**Pass/Fail:** [ ]

---

### NT7.2 (NEG): AI Deep Scan Without Standard Scan First

**Acceptance Criteria:** 400 "Please perform a standard scan first..." line 665-666.

**Pass/Fail:** [ ]

---

### NT7.3 (NEG): DB Connection Timeout

**Acceptance Criteria:** 10s timeout; DB::disconnect() prevents idle leak (line 705).

**Pass/Fail:** [ ]

---

### NT7.4 (NEG): Document Upload >50MB

**Acceptance Criteria:** 422 validation error.

**Pass/Fail:** [ ]

---

### NT7.5 (NEG): Batch Upload Exceeds File Count

**Acceptance Criteria:** 422 "max 20 items" (cloud mode).

**Pass/Fail:** [ ]

---

### NT7.6 (NEG): Approve Import Not in Review Status

**Acceptance Criteria:** 400 "Import ini tidak dalam status review."

**Pass/Fail:** [ ]

---

### NT7.7 (NEG): Cross-Border TIA Invalid Destination

**Acceptance Criteria:** Treats unknown country as tier='none', risk -25pt.

**Pass/Fail:** [ ]

---

**Group 7 Total:** 28 main + 7 negative = **35 scenarios**

---

<a id="group-8"></a>
# Group 8: Breach Management + Fire Drill + Security Posture (DSPM)

**Cakupan:** Breach incident lifecycle + containment workflow + PDF reports (Komdigi, subject letter, full report), Fire Drill simulation, DSPM findings.

**Estimasi waktu uji:** 2 jam (28 skenario)

### S8.1: Report New Breach Incident

**Acceptance Criteria:**
- [ ] BRC-YYYY-NNN format unique per org
- [ ] Timestamp sesuai detected_at
- [ ] containment_checklist initialized empty
- [ ] timeline_log[0] entry created
- [ ] notification_deadline = now + 72 hours (Pasal 46 UU PDP)

**Code Audit:** `POST /api/m/breach` → `ModuleCrudController@store`; Model `BreachIncident.php:1-74`

**Pass/Fail:** [ ]

---

### S8.2: Auto-Initialize Containment Checklist + Timeline

**Acceptance Criteria:**
- [ ] Template applied via POST `/breach/{id}/apply-template`
- [ ] Each step has raci: {responsible, accountable, consulted[], informed[]}
- [ ] is_default steps copy-on-fork
- [ ] timeline_log include template_applied event

**Code Audit:** `ContainmentController@applyTemplate` line 174-195; copy-on-write line 85-95

**Pass/Fail:** [ ]

---

### S8.3: Update Containment Step (Done + Evidence)

**Acceptance Criteria:**
- [ ] done=true set automatically
- [ ] completed_by/completed_at populated
- [ ] NotificationService dispatch RACI roles
- [ ] Cannot un-mark without admin override
- [ ] evidence_files persisted

**Code Audit:** `updateStep()` line 198-299; step locking line 242-245; RACI notif line 272-296

**Pass/Fail:** [ ]

---

### S8.4: Apply RACI Matrix Template

**Acceptance Criteria:**
- [ ] Template rows mapped by step.category
- [ ] Unmatched categories keep old RACI
- [ ] usage_count incremented
- [ ] System templates (is_system=true) not editable by tenants

**Code Audit:** `applyRaciTemplate()` line 423-458; matching line 444-449

**Pass/Fail:** [ ]

---

### S8.5: Edit RACI Assignment Per Role

**Acceptance Criteria:**
- [ ] Bulk-save endpoint returns fresh breach
- [ ] Non-RACI step fields unchanged
- [ ] Normalize matrix: accountable/responsible as strings, consulted/informed as arrays

**Code Audit:** `updateRaciForBreach()` line 467-499

**Pass/Fail:** [ ]

---

### S8.6: AI Breach Advisor

**Acceptance Criteria:**
- [ ] `breachAdvisor` endpoint called dengan incident_code + description
- [ ] Response: severity_recommendation (CRITICAL|HIGH|MEDIUM|LOW)
- [ ] containment_steps non-empty array
- [ ] User can "Apply" to auto-populate checklist

**Code Audit:** Route `POST /breach/{id}/advisor` line 1177; AiFeatureController@breachAdvisor

**Pass/Fail:** [ ]

---

### S8.7: Export PDF Komdigi Report

**Acceptance Criteria:**
- [ ] PDF renders without HTTP errors
- [ ] incident_code in filename
- [ ] DPO name/email/phone from org.settings or User.role='dpo'
- [ ] Paper size param respected (default a4)
- [ ] 72h deadline visible

**Code Audit:** `GET /breach/{id}/pdf/komdigi` → `BreachReportController@komdigi` line 28-33; buildPdf line 56-74

**Pass/Fail:** [ ]

---

### S8.8: Export PDF Subject Notification Letter

**Acceptance Criteria:**
- [ ] Filename includes incident_code
- [ ] Subject letter does NOT reveal breach cause (regulatory safe)
- [ ] Includes credential change recommendation
- [ ] Org branding applied

**Code Audit:** `subjectLetter()` line 35-40

**Pass/Fail:** [ ]

---

### S8.9: Export PDF Full Report (Internal)

**Acceptance Criteria:**
- [ ] Full containment checklist with completion status
- [ ] timeline_log chronological
- [ ] RACI matrix table
- [ ] Evidence file references

**Code Audit:** `fullReport()` line 42-47

**Pass/Fail:** [ ]

---

### S8.10: Telegram/SIEM Webhook Integration

**Acceptance Criteria:**
- [ ] Webhook called dengan correct auth headers
- [ ] Payload structure matches schema
- [ ] HTTP 200+ logged as success
- [ ] Failure logged with retry count

**Pass/Fail:** [ ]

---

### S8.11: List Breach + Filter Status

**Acceptance Criteria:**
- [ ] GET /api/m/breach?status=open&sort=-created_at
- [ ] Pagination respects per_page
- [ ] Sort order consistent
- [ ] containment_progress computed (steps_done/total*100)

**Pass/Fail:** [ ]

---

### S8.12: Close Breach + Final Report

**Acceptance Criteria:**
- [ ] Cannot close kalau containment has pending non-skipped steps
- [ ] 422 dengan incomplete_steps list
- [ ] closed_at populated
- [ ] status='closed' persisted

**Pass/Fail:** [ ]

---

### S8.13: List Scenarios (Tabletop/Walkthrough/Quiz)

**Acceptance Criteria:**
- [ ] GET `/simulations/scenarios` returns array
- [ ] total_questions >0 for each
- [ ] Emoji icon included

**Code Audit:** `SimulationController@scenarios` line 32-47

**Pass/Fail:** [ ]

---

### S8.14: Start Drill — Interactive Quiz

**Acceptance Criteria:**
- [ ] BreachSimulation.status='running' after start
- [ ] started_at = now()
- [ ] Briefing text in response
- [ ] Questions array complete (no answer keys)
- [ ] time_limit per question shown

**Code Audit:** `start()` line 99-128; answer keys filtered line 121-126

**Pass/Fail:** [ ]

---

### S8.15: Submit Answers + Calculate Score

**Acceptance Criteria:**
- [ ] overall_score 0-100%
- [ ] score_breakdown includes question_results
- [ ] Response time < time_limit measured
- [ ] detailed_results with feedback
- [ ] Findings logged: participant_id, completed_at, duration_seconds

**Code Audit:** `submitResponses()` line 133-199; `calculateDrillScore()` line 148

**Pass/Fail:** [ ]

---

### S8.16: View Drill History per Team

**Acceptance Criteria:**
- [ ] List shows completed drills desc
- [ ] Scores visible
- [ ] Participant + date shown
- [ ] CSV export option

**Pass/Fail:** [ ]

---

### S8.17: Delete Drill Record

**Acceptance Criteria:**
- [ ] deleted_at set on soft-delete
- [ ] Not in main list
- [ ] Restore: POST /restore
- [ ] Force-delete: DELETE /force

**Code Audit:** `destroy()` line 201-205; `restore()` line 207-212; `forceDelete()` line 214-218

**Pass/Fail:** [ ]

---

### S8.18: View Posture Score + Trend Chart

**Acceptance Criteria:**
- [ ] overall_score 0-100 from PostureScoreService
- [ ] Trend array daily data points (30 days)
- [ ] has_baseline=true kalau >=7 snapshots
- [ ] Message hints to build trend kalau <7

**Code Audit:** `PostureController@getPosture` line 18-25; `getTrend` line 32-52

**Pass/Fail:** [ ]

---

### S8.19: List Findings + Filter Severity

**Acceptance Criteria:**
- [ ] Severity filter works
- [ ] Status filter: open|in_progress|resolved
- [ ] Overdue calculation: sla_due_at < now()
- [ ] Sort options: severity_then_sla (default), newest, oldest, sla_due
- [ ] Pagination 1-500, default 25

**Code Audit:** `PostureFindingController@index` line 20-63

**Pass/Fail:** [ ]

---

### S8.20: Assign Finding to User + Status Change

**Acceptance Criteria:**
- [ ] assigned_to user_id set
- [ ] status=in_progress
- [ ] Notification dispatched to assignee
- [ ] assigned_at timestamp populated

**Pass/Fail:** [ ]

---

### S8.21: Resolve Finding + Evidence

**Acceptance Criteria:**
- [ ] status='resolved'
- [ ] resolved_by = user_id
- [ ] resolved_at = now()
- [ ] evidence_files persisted
- [ ] AuditLog created

**Pass/Fail:** [ ]

---

### S8.22: Alert Engine Config + Notification Dispatch

**Acceptance Criteria:**
- [ ] Endpoint `POST /api/alerts/scan` line 142-152
- [ ] AlertEngineService.runAllRules(org_id) line 145
- [ ] New alerts persisted to security_alerts
- [ ] Recipient routing: user_id OR role OR org-wide

**Code Audit:** `AlertController@scan` line 142-152; `index()` line 24-79

**Pass/Fail:** [ ]

---

### NT-G8.1 (NEG): Report Breach Without Permission → 403

**Pass/Fail:** [ ]

---

### NT-G8.2 (NEG): Drill Scenario Not Found → 404

**Pass/Fail:** [ ]

---

### NT-G8.3 (NEG): Posture API Without security:read → 403

**Pass/Fail:** [ ]

---

### NT-G8.4 (NEG): Breach Close Without Containment Done → 422

**Pass/Fail:** [ ]

---

### NT-G8.5 (NEG): Apply Non-Existent Template → 404

**Pass/Fail:** [ ]

---

### NT-G8.6 (NEG): Edit System Template (Tenant) → Auto-Fork

**Acceptance Criteria:** 200 response dengan forked_from=system_template_id; new org-owned template; original system template untouched.

**Code Audit:** `updateTemplate()` line 77-96

**Pass/Fail:** [ ]

---

**Group 8 Total:** 22 main + 6 negative = **28 scenarios**

---

<a id="group-9"></a>
# Group 9: AI Agent + AI Credits + AI Features Cross-Module

**Cakupan:** AI Agent chat + function calling + tool approval, AI Credits ledger + topup, AI Features cross-module (GAP/ROPA/DPIA/Breach/DSR/Consent/Discovery/Policy/Contract autofill + analysis).

**Estimasi waktu uji:** 2 jam (26 skenario)

### S9.1: AI Agent Chat Basic Query (Bahasa Indonesia)

**Prasyarat:** License include `ai_agent` atau perpetual; AI credit >0.

**Acceptance Criteria:**
- [ ] Response time <30 detik
- [ ] AiCreditLog entry action_type='chat', status='success'
- [ ] Response berbahasa Indonesia
- [ ] ChatMessage row tersimpan (role=assistant)
- [ ] AuditLog module='ai_agent'

**Code Audit:** `POST /api/ai-agent/chat` → `AiAgentController.php:35`; credit check line 153-157; response stream NDJSON line 392-621; message save line 209-221

**Pass/Fail:** [ ]

---

### S9.2: Function Calling — list_ropa (Read-Only)

**Acceptance Criteria:**
- [ ] Tool execution <2 detik
- [ ] Step log saved di ChatMessage "Menjalankan..."
- [ ] AI response coherent sesuai DB
- [ ] No approval UI (read-only)

**Code Audit:** `AiAgentToolExecutor::getToolDefinitions()`; MUTATION_TOOLS const line 32-38 NOT include list_ropa; execution line 479; spotlight nonce line 231; injection line 505-513

**Pass/Fail:** [ ]

---

### S9.3: Function Calling — create_ropa (Mutation Propose)

**Acceptance Criteria:**
- [ ] Tool NOT executed (pending_approval envelope)
- [ ] Response stream `approval_required`
- [ ] ChatMessage created "AI mengusulkan aksi..."
- [ ] No Ropa record created
- [ ] No credit deducted

**Code Audit:** MUTATION_TOOLS check line 32-38; block logic; approval event line 518-525; break loop line 537

**Pass/Fail:** [ ]

---

### S9.4: Approval Flow — User Klik Setujui

**Acceptance Criteria:**
- [ ] Response 200 dengan result + new Ropa ID
- [ ] Ropa row exists dengan org_id + correct data
- [ ] ChatMessage approval confirmation
- [ ] AuditLog includes initiator_user_id + conversation_id (meta)
- [ ] Credit deducted after execution

**Code Audit:** `approveAction()` line 629; tool exec (approved) line 655; `auditPayload()` line 78-99

**Pass/Fail:** [ ]

---

### S9.5: Rejection Flow — User Klik Tolak

**Acceptance Criteria:**
- [ ] No DB changes
- [ ] Rejection message in conversation
- [ ] Credits unchanged

**Code Audit:** `rejectAction()` line 678; message log line 692-697

**Pass/Fail:** [ ]

---

### S9.6: Mention @ropa-id Context Injection

**Acceptance Criteria:**
- [ ] GET /mentions/ropa returns 200 [{id, registration_number, label}]
- [ ] AI can reference ROPA by number
- [ ] No extra credit (same chat turn)

**Code Audit:** `mentions()` line 706; ROPA branch line 713 (limit 20)

**Pass/Fail:** [ ]

---

### S9.7: Chat History Listing

**Acceptance Criteria:**
- [ ] Response structure correct
- [ ] Ordering: last_message_at desc
- [ ] Only user's own conversations
- [ ] No message content (only metadata)

**Code Audit:** `history()` line 736; query line 739-743

**Pass/Fail:** [ ]

---

### S9.8: Multi-Turn Conversation (Context Preserved)

**Acceptance Criteria:**
- [ ] conversation_id same in all turns
- [ ] ChatMessage count increases
- [ ] Context window dari prior turns visible
- [ ] Credit log per turn

**Code Audit:** Conversation lookup line 162-174; history load line 344-349 (last 10); credit per turn line 585-587

**Pass/Fail:** [ ]

---

### S9.9: Anti-Prompt-Injection (Phase 2 Security)

**Acceptance Criteria:**
- [ ] Morse/base64/hex/ROT13 NOT decoded
- [ ] Nonce-spotlighting prevents fake closing markers
- [ ] Role-token "SYSTEM:" in tool result treated as DATA
- [ ] Suspicious patterns logged warning

**Code Audit:** Nonce generation line 231; system prompt rules line 317-323; spotlight line 505-513

**Pass/Fail:** [ ]

---

### S9.10: Anti-Jailbreak (AI Rejects DATA Instructions)

**Acceptance Criteria:**
- [ ] AI recognizes "===FAKE SYSTEM===" sebagai suspicious DATA
- [ ] AI does NOT follow embedded instruction
- [ ] Warning logged/displayed
- [ ] Normal operation unaffected

**Code Audit:** Document injection line 372; system prompt rule 15

**Pass/Fail:** [ ]

---

### S9.11: View AI Credits Usage Stats

**Acceptance Criteria:**
- [ ] GET /api-credits/usage returns 200 correct structure
- [ ] Remaining = monthly + purchased - used
- [ ] Breakdown accurate
- [ ] Reset date (1st of next month)

**Pass/Fail:** [ ]

---

### S9.12: Monthly History Breakdown per Feature

**Acceptance Criteria:**
- [ ] Response structure matches spec
- [ ] Month format YYYY-MM
- [ ] Breakdown sums to credits_used
- [ ] Actions count accurate

**Pass/Fail:** [ ]

---

### S9.13: Topup Credits (Admin Only)

**Acceptance Criteria:**
- [ ] Only admin/superadmin/root (403 others)
- [ ] DB: ai_credits_purchased updated
- [ ] Audit log entry
- [ ] Response reflects new pool

**Pass/Fail:** [ ]

---

### S9.14: Credit Exhausted → 402

**Acceptance Criteria:**
- [ ] HTTP 402 (not 403/400)
- [ ] `credits_exhausted: true` flag
- [ ] No AI API call made
- [ ] Clear message to user

**Code Audit:** AiAgentController line 153-157; `CreditService::hasCredit()` line 65-75; OnPrem bypass line 66

**Pass/Fail:** [ ]

---

### S9.15: Credit Reserved + Refund on Failure

**Acceptance Criteria:**
- [ ] No credit deducted on failure
- [ ] AiCreditLog entry status='failed'
- [ ] Organization.ai_credits_remaining unchanged
- [ ] Error message informative

**Code Audit:** Deduct condition line 585; success deduct line 586; failed log line 97

**Pass/Fail:** [ ]

---

### S9.16: GAP Evidence Analyze (1 Credit, Cached)

**Acceptance Criteria:**
- [ ] POST /gap/{id}/analyze-evidence 200
- [ ] 1 credit deducted (kalau not cached)
- [ ] Cache hit: instant response, no credit
- [ ] AiResult entry saved

**Code Audit:** Route `/api/gap/{id}/analyze-evidence` line 319; COSTS['ai_doc_analyze']=1.0 line 28

**Pass/Fail:** [ ]

---

### S9.17: ROPA AI Autofill

**Acceptance Criteria:**
- [ ] Autofill response complete (all wizard fields)
- [ ] Data reasonable (legal basis matches GDPR/PDP)
- [ ] 1 credit deducted
- [ ] User can edit before save

**Code Audit:** `autofillRopa()` route line 1194; COSTS['autofill_ropa']=1.0 line 15

**Pass/Fail:** [ ]

---

### S9.18: DPIA AI Risk Scoring

**Acceptance Criteria:**
- [ ] Risk score 0-10
- [ ] Risk level: LOW/MEDIUM/HIGH/CRITICAL
- [ ] Mitigation suggestions provided
- [ ] 1 credit deducted

**Code Audit:** `dpiaRiskScoring()` route line 1176; COSTS['analysis_dpia']=1.0 line 20

**Pass/Fail:** [ ]

---

### S9.19: Breach AI Advisor + Containment Steps

**Acceptance Criteria:**
- [ ] Severity assessment matches incident
- [ ] Immediate actions practical
- [ ] Notification template GDPR/local compliant
- [ ] 1 credit deducted
- [ ] Steps feed into BreachContainment

**Code Audit:** Route line 1177; COSTS['analysis_breach']=1.0 line 21; containment steps endpoint line 1199

**Pass/Fail:** [ ]

---

### S9.20: DSR AI Response Drafter

**Acceptance Criteria:**
- [ ] Response body professional + legally compliant
- [ ] Deadline referenced
- [ ] 0.5 credit deducted
- [ ] Draft saved to AiResult
- [ ] User can edit before sending

**Code Audit:** `dsrDraft()` route line 1178; COSTS['autofill_dsr']=0.5 line 18

**Pass/Fail:** [ ]

---

### N9.1 (NEG): Chat Without License ai_agent → 403

**Pass/Fail:** [ ]

---

### N9.2 (NEG): Prompt Size Guard Exceed → 413

**Code Audit:** `AiPromptGuard::assertPromptSize()` line 382-383

**Pass/Fail:** [ ]

---

### N9.3 (NEG): Output Guard Repetitive → Rejection + Warning Log

**Code Audit:** `$outputGuard->isSafe()` line 549; rejection line 555; log line 550-553

**Pass/Fail:** [ ]

---

### N9.4 (NEG): Mutation Tool Without Approval → Pending + No Execution

**Code Audit:** Pending check line 641; approval required line 518-525; no execution line 537

**Pass/Fail:** [ ]

---

### N9.5 (NEG): Prompt Injection in Tool Result

**Code Audit:** System prompt rule 13 line 321

**Pass/Fail:** [ ]

---

### N9.6 (NEG): Base64-Encoded Instruction in Attachment

**Code Audit:** System prompt rule 11 line 319

**Pass/Fail:** [ ]

---

**Group 9 Total:** 20 main + 6 negative = **26 scenarios**

---

<a id="group-10"></a>
# Group 10: Platform Admin + Tenant Settings + User Management

**Cakupan:** User mgmt + invitation, Settings 15+ tabs, Branding, KB, Notifications, License, Tenant offboarding, System Settings, QA Center, API Hub, Master AI Audit.

**Estimasi waktu uji:** 3 jam (35 skenario — modul kompleks)

### S10.1: Invite User to Org

**Acceptance Criteria:**
- [ ] Email duplicate rejected (422)
- [ ] Permission `users:write` enforced
- [ ] AuditLog tercatat
- [ ] Notification seed berhasil

**Code Audit:** `UserController.php:143-265 store()`; permission check line 17-50; route line 266

**Pass/Fail:** [ ]

---

### S10.2: Activate User via Email Link

**Acceptance Criteria:**
- [ ] Link expired → 410
- [ ] Invalid hash → 403
- [ ] Throttle rate limit 6/min/IP
- [ ] AuditLog verifikasi

**Code Audit:** Endpoint line 138-142; `AuthController::verifyEmail()`; throttle:6,1

**Pass/Fail:** [ ]

---

### S10.3: Assign Role + Permission to User

**Acceptance Criteria:**
- [ ] Admin can't assign other tenant (403)
- [ ] Non-existent role rejected (422)
- [ ] Role sync: legacy `role` field auto-updated
- [ ] AuditLog change tracked

**Code Audit:** `update()` line 291-410; permission mapping line 373-382

**Pass/Fail:** [ ]

---

### S10.4: Deactivate User (Soft Delete)

**Acceptance Criteria:**
- [ ] Self-delete rejected (400)
- [ ] Scope check: admin only own org
- [ ] Soft delete (not hard)
- [ ] AuditLog

**Code Audit:** `destroy()` line 415-436

**Pass/Fail:** [ ]

---

### S10.5: Hard Delete User (Superadmin Only)

**Acceptance Criteria:**
- [ ] Admin can't hard delete (403)
- [ ] Foreign key constraints handled
- [ ] AuditLog `force_delete`

**Pass/Fail:** [ ]

---

### S10.6: Edit User Profile (Own User)

**Acceptance Criteria:**
- [ ] User only edits self
- [ ] Phone format validated (max 20)
- [ ] AuditLog tracked

**Code Audit:** Endpoint line 224; `AuthController::updateProfile()`

**Pass/Fail:** [ ]

---

### S10.7: Setup 2FA (Security Tab)

**Acceptance Criteria:**
- [ ] Setup returns secret + QR
- [ ] Confirm validates TOTP
- [ ] Invalid TOTP rejected (422)
- [ ] Recovery codes saved hashed
- [ ] AuditLog

**Code Audit:** Endpoints line 233-236; setupTwoFactor(), confirmTwoFactor()

**Pass/Fail:** [ ]

---

### S10.8: Update Organization Info

**Acceptance Criteria:**
- [ ] Admin only own org
- [ ] Superadmin any org
- [ ] AuditLog tracked

**Code Audit:** Endpoint line 259; `OrganizationController:64-82`

**Pass/Fail:** [ ]

---

### S10.9: CRUD Departments

**Acceptance Criteria:**
- [ ] Duplicate department name rejected
- [ ] Head user exists validation
- [ ] Delete cascade (update department_id to null)
- [ ] AuditLog

**Code Audit:** Routes line 275-279

**Pass/Fail:** [ ]

---

### S10.10: CRUD Positions

**Acceptance Criteria:**
- [ ] Level enum validation
- [ ] Duplicate position name in org rejected
- [ ] AuditLog

**Code Audit:** Routes line 282-285

**Pass/Fail:** [ ]

---

### S10.11: Configure SSO (OIDC/SAML)

**Acceptance Criteria:**
- [ ] Discovery URL reachable
- [ ] Invalid creds rejected (422)
- [ ] Encrypted storage (client_secret masked)
- [ ] AuditLog

**Code Audit:** Routes line 262-263; TenantSsoController

**Pass/Fail:** [ ]

---

### S10.12: Configure Breach Integration (Telegram/SIEM)

**Acceptance Criteria:**
- [ ] Invalid webhook URL rejected
- [ ] Test connection returns status
- [ ] Masked sensitive fields in response
- [ ] AuditLog

**Code Audit:** Routes line 802-807; 1653-1661

**Pass/Fail:** [ ]

---

### S10.13: CRUD Roles + Permissions

**Acceptance Criteria:**
- [ ] System roles (Admin/DPO/Maker/Viewer) immutable
- [ ] Permission format validated
- [ ] Delete blocked if users assigned (422)
- [ ] AuditLog

**Code Audit:** Routes line 270; TenantRoleController

**Pass/Fail:** [ ]

---

### S10.14: Cloud Storage Config (S3/GCS/MinIO)

**Acceptance Criteria:**
- [ ] Bucket exists check
- [ ] Credentials validated
- [ ] Encryption at rest enabled
- [ ] AuditLog with masked secrets

**Code Audit:** Routes line 1679-1686; `SystemSettingsController:43-100+`; encrypted keys line 46-51

**Pass/Fail:** [ ]

---

### S10.15: AI Providers Config (Superadmin)

**Acceptance Criteria:**
- [ ] API key encrypted (Crypt::encryptString)
- [ ] Connection test success
- [ ] Only one active per mode
- [ ] Invalid endpoint rejected (422)
- [ ] AuditLog

**Pass/Fail:** [ ]

---

### S10.16: Upload Logo + Favicon

**Acceptance Criteria:**
- [ ] File type validation (image only)
- [ ] Size limit enforced
- [ ] CDN URL returned
- [ ] Old logo deleted on update
- [ ] AuditLog

**Code Audit:** Routes line 1400-1414; `TenantThemeController:90-100+`

**Pass/Fail:** [ ]

---

### S10.17: Set CSS Variable Colors

**Acceptance Criteria:**
- [ ] Hex color format validated
- [ ] Default palette fallback if invalid
- [ ] CSS variables injected in embed widgets
- [ ] AuditLog

**Pass/Fail:** [ ]

---

### S10.18: Preview Theme in Embed Widgets

**Acceptance Criteria:**
- [ ] CSS injected via style tag in iframe
- [ ] Fallback to default if missing
- [ ] CSS cached

**Pass/Fail:** [ ]

---

### S10.19: CRUD Knowledge Base Section

**Acceptance Criteria:**
- [ ] Superadmin only edit shared (org_id=null)
- [ ] Tenant only edit own KB (org_id=user.org_id)
- [ ] module_key regex validated
- [ ] Duplicate module_key per scope rejected (422)
- [ ] AuditLog

**Code Audit:** Routes line 1304-1309; `KnowledgeBaseController:16-100+`; scope logic line 30-34, 52-70

**Pass/Fail:** [ ]

---

### S10.20: List Notifications Inbox

**Acceptance Criteria:**
- [ ] Visibility rules enforced (org_id + role)
- [ ] Pagination working
- [ ] Unread count accurate
- [ ] Mark read endpoint

**Code Audit:** `AlertController:24-80`; scopedQuery()

**Pass/Fail:** [ ]

---

### S10.21: Set Notification Preferences

**Acceptance Criteria:**
- [ ] Bulk update idempotent
- [ ] Defaults inherited for empty rows
- [ ] Channels: in_app, email, wa, push
- [ ] Digests: instant, hourly, daily, off
- [ ] AuditLog

**Code Audit:** Routes line 1121-1125; `NotificationPreferenceController:18-79`; modules const line 20-25

**Pass/Fail:** [ ]

---

### S10.22: View License Info + Expiry

**Acceptance Criteria:**
- [ ] Scope enforcement (admin ≠ superadmin)
- [ ] Auto-expire check on index
- [ ] Pagination support
- [ ] AuditLog for view

**Code Audit:** Routes line 1136-1148; `LicenseController:17-60`; expiry check line 52-57

**Pass/Fail:** [ ]

---

### S10.23: Activate License with Key

**Acceptance Criteria:**
- [ ] License key format validated
- [ ] License Manager connection tested
- [ ] Invalid key → 422
- [ ] Duplicate key rejected
- [ ] Admin only own org (403)
- [ ] AuditLog

**Code Audit:** Route line 1140; `activate()` method

**Pass/Fail:** [ ]

---

### S10.24: Tenant Offboarding Workflow (Freeze → Archive → Export)

**Acceptance Criteria:**
- [ ] Password verified
- [ ] Scope: only root/superadmin
- [ ] Freeze → Archive workflow enforced
- [ ] Export includes all compliance data
- [ ] Hard delete scheduled via job
- [ ] AuditLog lifecycle transition

**Code Audit:** Routes line 1336-1343; `TenantOffboardController:27-100+`; freeze line 28-56, unfreeze 58-79

**Pass/Fail:** [ ]

---

### S10.25: System Settings — Redis/AI/Deployment

**Acceptance Criteria:**
- [ ] Permission `settings:write` enforced
- [ ] Sensitive fields encrypted
- [ ] Test endpoint validates connection
- [ ] Invalid config rejected (422)
- [ ] Cache invalidation works
- [ ] AuditLog with masked secrets

**Code Audit:** Routes line 1679-1686; `SystemSettingsController:43-100+`

**Pass/Fail:** [ ]

---

### S10.26: QA Center — Create Test Case + Assign Tester

**Acceptance Criteria:**
- [ ] Module/feature validation
- [ ] Role-based applicability
- [ ] License package filtering
- [ ] Test case catalog queryable
- [ ] AuditLog

**Code Audit:** Routes line 1354-1395; `QaCenterController:75-95+`; middleware `role.root_only`

**Pass/Fail:** [ ]

---

### S10.27: QA Center — Log Bug + Attach Screenshot

**Acceptance Criteria:**
- [ ] Bug severity enum validated
- [ ] Screenshots max 5MB, PNG/JPG only
- [ ] File path secured
- [ ] AuditLog

**Code Audit:** Routes line 1381-1391

**Pass/Fail:** [ ]

---

### S10.28: API Hub — Issue API Key for Partner

**Acceptance Criteria:**
- [ ] Key entropy sufficient (32+ bytes)
- [ ] Key not shown after creation
- [ ] Permissions validated
- [ ] IP allowlist applied
- [ ] Rate limit enforced
- [ ] Expiry checked per request
- [ ] AuditLog

**Code Audit:** Routes line 1634-1648; `ApiHubController:25-74`

**Pass/Fail:** [ ]

---

### S10.29: API Hub — Webhook Config + Delivery Log

**Acceptance Criteria:**
- [ ] URL reachability check
- [ ] HMAC signing (SHA256)
- [ ] Retry logic exponential backoff
- [ ] Delivery log retention 30 days
- [ ] AuditLog

**Code Audit:** Routes line 1644-1648

**Pass/Fail:** [ ]

---

### S10.30: Master AI Audit — Superadmin Cross-Tenant

**Acceptance Criteria:**
- [ ] Superadmin only (403 admin)
- [ ] Org_id filtering works
- [ ] Conversation history complete
- [ ] Admin reply tagged + timestamped
- [ ] AuditLog `master_audit_view`

**Code Audit:** Routes line 1162-1165; AiChatController:60+

**Pass/Fail:** [ ]

---

### NT10.1 (NEG): User Invite Without Permission → 403

**Pass/Fail:** [ ]

---

### NT10.2 (NEG): Activate User with Expired Link → 410

**Pass/Fail:** [ ]

---

### NT10.3 (NEG): License Activation Invalid Key → 422

**Pass/Fail:** [ ]

---

### NT10.4 (NEG): Tenant Offboard Without Password → 422

**Pass/Fail:** [ ]

---

### NT10.5 (NEG): Master AI Audit for Admin → 403

**Pass/Fail:** [ ]

---

**Group 10 Total:** 30 main + 5 negative = **35 scenarios**

---

<a id="sign-off"></a>
# Summary Matrix + Sign-Off

## Total Scenarios Per Group

| Group | Modul | Total Skenario | Time Estimate |
|-------|-------|---------------|---------------|
| 1 | Auth + Dashboard + Holding | 17 | 1 jam |
| 2 | RoPA (CRUD + 7-step + Approval) | 25 | 2 jam |
| 3 | DPIA + LIA + TIA + Maturity | 27 | 2.5 jam |
| 4 | GAP + Policy + Contract Review | 30 | 2 jam |
| 5 | DSR + Consent | 28 | 2.5 jam |
| 6 | TPRM Full Lifecycle | 49 | 4 jam |
| 7 | Data Discovery + Cross-Border + Doc Import | 35 | 2 jam |
| 8 | Breach + Drill + Security Posture | 28 | 2 jam |
| 9 | AI Agent + Credits + AI Features | 26 | 2 jam |
| 10 | Platform Admin + Settings + User Mgmt | 35 | 3 jam |
| **TOTAL** | **Full Platform** | **300** | **~23 jam** |

## Recommended Test Sequence

**Day 1 (8 jam):** Group 1, 2, 3, 4 — Auth + core compliance modules
**Day 2 (8 jam):** Group 5, 6 — DSR/Consent + TPRM lifecycle
**Day 3 (7 jam):** Group 7, 8, 9, 10 — Data discovery + security + AI + admin

## Pass Criteria

UAT dianggap **PASS** kalau:
- ≥ 95% positive scenarios pass tanpa workaround
- 100% negative tests pass (security/validation)
- 0 critical/blocker bugs
- ≤ 3 major bugs dengan known mitigations

## Sign-Off

| Role | Nama | Tanda Tangan | Tanggal |
|------|------|--------------|---------|
| QA Lead | _____________ | _____________ | _____________ |
| DPO | _____________ | _____________ | _____________ |
| Project Manager | _____________ | _____________ | _____________ |
| CTO/CISO | _____________ | _____________ | _____________ |

**Overall Result:** [ ] PASS [ ] FAIL [ ] CONDITIONAL PASS

**Known Issues (carried forward):**
1. _____________________________________________
2. _____________________________________________
3. _____________________________________________

**Action Items:**
1. _____________________________________________
2. _____________________________________________

---

# Appendix

## Code Audit References

Semua skenario sudah berisi referensi file:line ke codebase. Untuk navigasi cepat saat verifikasi:

- **Routes:** `backend/routes/api.php` (912+ endpoint)
- **Controllers:** `backend/app/Http/Controllers/Api/*`
- **Services:** `backend/app/Services/*`
- **Models:** `backend/app/Models/*`
- **FE Pages:** `frontend/src/app/(dashboard)/*`
- **FE Components:** `frontend/src/components/*`

## Known Limitations / TODO

- Beberapa scenario menyebut "TODO verify" — endpoint atau line number perlu konfirmasi saat eksekusi
- Optional features (streaming AI analysis, deadline alert command) belum diimplementasi penuh — di-flag dengan note
- Multi-language i18n testing (id/en) tidak coverage 100% per skenario — testers harus spot-check

## Glossary

- **DPO** — Data Protection Officer
- **PDP** — Pelindungan Data Pribadi (UU 27/2022 Indonesia)
- **RoPA** — Record of Processing Activities
- **DPIA** — Data Protection Impact Assessment
- **LIA** — Legitimate Interest Assessment
- **TIA** — Transfer Impact Assessment
- **DSR** — Data Subject Request
- **TPRM** — Third Party Risk Management
- **DSPM** — Data Security Posture Management
- **RACI** — Responsible/Accountable/Consulted/Informed
- **RTP** — Risk Treatment Plan
- **KOMDIGI** — Kementerian Komunikasi dan Digital (regulator Indonesia)

---

**Dokumen disiapkan oleh:** AI assistant (Claude Opus 4.7)
**Untuk:** Tim QA Privasimu BUMN compliance platform
**Versi:** 1.0
**Tanggal:** 2026-05-18


