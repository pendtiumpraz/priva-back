# LIA / TIA / Maturity Assessment — Extension Plan (Backend)

> **Status**: Existing scaffolding shipped Sprint F1/F2/F3 (basic models + tables + AssessmentsController generic CRUD + frontend wizard pages, ~430-477 LOC each). This plan covers the **gap between current shallow implementation and the full feature spec** in `docs/new_feat/Fitur LIA.pdf`, `docs/new_feat/Fitur TIA.pdf`, and `docs/new_feat/Privacy Compliance Maturity Assessment.pdf`.
>
> **Scope**: backend schema extensions, new relations (TIA ↔ Cross-Border + Vendor/TPRM, LIA ↔ DPIA), API endpoints, RACI workflow, and integration with existing modules.

## Current State (audit)

| Module | Model | Migration | Controller | FE Page | Linked relations | Spec coverage |
|---|---|---|---|---|---|---|
| LIA | `LiaAssessment` | `2026_04_17_000008_create_lia_assessments_table` | `AssessmentsController` (generic) | `(dashboard)/lia/page.tsx` | ROPA via `linked_ropa_id` | ~30% (3-phase test JSON columns ada, tapi pertanyaan + RACI + risk register + RoPA auto-fill belum) |
| TIA | `TiaAssessment` | `2026_04_17_000009_create_tia_assessments_table` | `AssessmentsController` (generic) | `(dashboard)/tia/page.tsx` | CrossBorderTransfer via `linked_cross_border_id` | ~25% (JSON column ada, tapi 8 risk-metric ruler + country assessment + maturity scoring + supplementary docs belum, tidak ada Vendor relation) |
| Maturity | `MaturityAssessment` | `2026_04_17_000010_create_maturity_assessments_table` | `AssessmentsController` (generic) | `(dashboard)/maturity/page.tsx` | (none) | ~20% (`dimensions` JSON, `overall_level` integer ada, tapi 18 UU PDP questions + ruler 1-10 + 3 input methods + auto-derive belum) |

**Existing flow** for all three:
1. User clicks `Tambah` → wizard form → fill JSON columns → submit
2. AI analysis available via `POST /api/ai/assessment/{kind}/analysis`
3. Generic CRUD via `/api/assessments/{kind}/{id}`
4. No approval workflow, no RoPA auto-fill, no relation to DPIA/Vendor, no risk register UI

## What the spec demands

### LIA (Legitimate Interest Assessment)

Sesuai PDF `Fitur LIA.pdf`:

**Section 1 — Informasi Pengisi Formulir** (auto-resolved dari ROPA):
- Pejabat PDP (Approver) — DPO of org
- Process Owner/PIC (Maker)
- Atasan Process Owner (Checker, opsional)

**Section 2 — Informasi Internal Organisasi** (semua auto dari ROPA except ID LIA):
- ID LIA (text, format: `LIA-[UNIT]-[AKTIVITAS]-[NOMOR]`)
- ID RoPA (FK)
- ID DPIA opsional (FK ← **belum ada di model existing**)
- 11 fields auto-fill: Nama Aktivitas, Unit Kerja, Tujuan, Peran, Subjek Data, Jenis Data, Skala, Sistem, Lokasi, Pihak Lain

**Section 3 — Dasar Pemrosesan**:
- `is_using_legitimate_interest` (Yes/No dropdown)
- `legitimate_interest_reason` (text)

**Section 4 — Uji Tujuan (Purpose Test)** — 5 pertanyaan teks panjang:
1. Mengapa pemrosesan dibutuhkan
2. Manfaat yang diharapkan
3. Keuntungan (multi-select checkbox: operasional/bisnis/keamanan/pelayanan/pengembangan + "lainnya" text)
4. Tingkat kepentingan (high/medium/low)
5. Dampak jika tidak diproses

**Section 5 — Uji Kebutuhan (Necessity Test)** — 4 pertanyaan:
1. Diperlukan untuk capai tujuan? (Yes/No + alasan)
2. Proporsional? (Yes/No + alasan)
3. Bisa tanpa data pribadi? (Yes/No)
4. Ada pendekatan lebih ramah privasi? (Yes/No + alasan)

**Section 6 — Uji Keseimbangan (Balancing Test)** — **risk register table**, identical pattern dengan DPIA risk register existing:
- Potensi peristiwa risiko (text, multiple rows)
- Dampak (dropdown: kecil/sedang/besar)
- Probabilitas (dropdown: jarang/kadang/sering)
- Risiko inheren (auto-calc: low/moderate/high — generated dari damp × prob)
- Control (dropdown: non-effective/partial/effective)
- Nama Kontrol (text)
- Risiko residual (auto-calc)

Plus pertanyaan terakhir: subjek bisa kehilangan kendali? (Yes/No + alasan)

**Section 7 — Submit + Lock**:
- Pop-up konfirmasi → setelah submit, isi LIA tidak bisa diedit (lock)
- Status berubah → `submitted` → masuk antrean Checker

**Section 8 — Kesimpulan (Checker / Approver only)**:
- 3 verdict dropdown: Lulus/Tidak lulus untuk uji tujuan, kebutuhan, keseimbangan
- Approver (DPO) dan Checker (Atasan PIC) bisa accept/reject + comment

**Section 9 — RACI**:
- Informed: Atasan DPO
- Accountable (Approver): DPO
- Consulted (Checker, opsional): Atasan Process Owner
- Responsible (Maker): Process Owner/PIC

### TIA (Transfer Impact Assessment)

Sesuai PDF `Fitur TIA.pdf`:

**Section 1 — Informasi Pengisi** (auto dari ROPA): Approver/Maker/Checker

**Section 2 — Informasi Internal** (auto dari ROPA + ID TIA + ID RoPA), dan tambahan **deskripsi transfer**:
- Volume Data
- Frekuensi Data
- Dasar Transfer (Kontrak / Persetujuan / Binding Corporate Rules / Lainnya)

**Section 3 — Penilaian Risiko Pihak**:
- Apakah negara tujuan punya regulasi PDP?
- Apakah negara tujuan punya otoritas PDP?
- Tingkat maturitas organisasi penerima dan pemberi (1-10)

**Section 4 — Penilaian Risiko Transfer** — 6 metrik 1-10 (ruler):
1. Adanya kemungkinan ketidaksesuaian standar perlindungan (1-10)
2. Potensi pelanggaran kontraktual (1-10)
3. Potensi sanksi administrasi (1-10)
4. Kemungkinan kebocoran data (1-10)
5. Integritas data (1-10)
6. Risiko kedaulatan dan akses pemerintah (1-10)

**Section 5 — Tingkat Pengamanan Data** — 2 metrik 1-10:
1. Implementasi protokol aman antar jaringan (1-10)
2. Enkripsi, anonimisasi, pseudonimisasi (1-10)

**Section 6 — Dokumen Pelengkap Transfer** (file upload):
- Salinan akta / profil organisasi
- Kontrak
- Dokumen lain

**Section 7 — Maker / Approver workflow** — sama pattern dengan LIA.

**Penting — relasi**:
- TIA ↔ Cross-Border Transfer (existing FK)
- TIA ↔ **Vendor / TPRM** (BARU — penerima data adalah pihak ketiga yang juga di-assess di Vendor Risk Management)
- TIA ↔ ROPA (untuk context auto-fill)

Auto-flow yang harus diadd:
- Saat submit Cross-Border Transfer dengan negara tujuan = luar Indonesia → suggest create TIA
- Saat assess Vendor dengan transfer flag = true → suggest create TIA
- Skor risiko TIA → influence overall vendor risk score

### Maturity Assessment

Sesuai PDF `Privacy Compliance Maturity Assessment.pdf`:

**4 Level Definition** (UU PDP-aligned, bukan 5-level CMMI biasa):
- Level 1 — Ad-hoc (skor rata-rata 1-3)
- Level 2 — Defined (skor rata-rata 4-6)
- Level 3 — Managed (skor rata-rata 7-8)
- Level 4 — Optimized (skor rata-rata 9-10)

**18 Pertanyaan** (struktur yang spesifik UU PDP) terbagi 4 domain:

**A. Tata Kelola & Penunjukan DPO (Pasal 53)** — 2 pertanyaan
1. Penunjukan DPO yang kompeten (1-10)
2. Struktur organisasi & program kerja PDP (1-10)

**B. Dasar Pemrosesan & Hak Subjek Data (Pasal 20 & 5-13)** — 2 pertanyaan
3. Setiap pemrosesan punya dasar hukum sah (1-10)
4. Mekanisme hak subjek data (1-10)

**C. Kewajiban Pengendali & Prosesor (Pasal 35-39)** — 12 pertanyaan
5. Kualitas RoPA (1-10)
6. Kualitas DPIA (1-10)
7. Data flow / data mapping (1-10)
8. Kontrak DPA (1-10)
9. Verifikasi akurasi data (1-10)
10. Purpose limitation (1-10)
11. Retention & pemusnahan (1-10)
12. Enkripsi & anonimisasi (1-10)
13. Internal breach log (1-10)
14. Audit prosesor data (1-10)
15. Privacy by Design (1-10)
16. Pakta kerahasiaan + training (1-10)

**D. Keamanan & Penanganan Kegagalan (Pasal 46-48)** — 2 pertanyaan
17. Langkah teknis & organisasional keamanan (1-10)
18. SOP mitigasi & notifikasi 3x24 jam (1-10)

**3 Input Methods** (penting):
1. **Self-questionnaire** — DPO klik 1-10 ruler manual per pertanyaan
2. **Document upload** — upload SOP/Kebijakan/SDLC, AI baca + score otomatis
3. **Auto-derive from Nexus dashboard** — sistem hitung otomatis dari data existing (jumlah ROPA, jumlah DPIA, breach response time, audit log frequency, dll.)

**Output**:
- Overall score (rata-rata)
- Overall level (1-4 berdasarkan range)
- Per-domain breakdown
- Per-question score
- Recommendations otomatis berdasarkan level
- Trend chart (history of past assessments)

---

## Backend Schema Changes

### LIA — extend `lia_assessments`

Tambah kolom via migration baru:

```php
Schema::table('lia_assessments', function (Blueprint $table) {
    // RoPA / DPIA linkage upgrade (linked_ropa_id sudah ada)
    $table->uuid('linked_dpia_id')->nullable()->after('linked_ropa_id');

    // Section identifiers + workflow
    $table->string('lia_code', 64)->nullable()->after('id');               // "LIA-MKT-ANL-01"
    $table->string('legitimate_interest_basis', 32)->nullable();           // 'yes'|'no'
    $table->text('legitimate_interest_reason')->nullable();

    // Risk register (Balancing Test) — JSON (mirror DPIA risk events shape)
    $table->json('balancing_risk_events')->nullable();
    $table->string('subject_loses_control', 32)->nullable();               // 'yes'|'no'
    $table->text('subject_loses_control_reason')->nullable();

    // Conclusion — filled by Checker/Approver
    $table->string('conclusion_purpose', 32)->nullable();                  // 'lulus'|'tidak_lulus'
    $table->string('conclusion_necessity', 32)->nullable();
    $table->string('conclusion_balancing', 32)->nullable();
    $table->text('conclusion_notes')->nullable();

    // Approval workflow
    $table->uuid('maker_id')->nullable();
    $table->uuid('checker_id')->nullable();
    $table->uuid('approver_id')->nullable();
    $table->timestamp('submitted_at')->nullable();
    $table->timestamp('checked_at')->nullable();
    $table->timestamp('approved_at')->nullable();
    $table->boolean('is_locked')->default(false);

    $table->index(['org_id', 'lia_code']);
    $table->foreign('linked_dpia_id')->references('id')->on('dpias')->nullOnDelete();
});
```

### TIA — extend `tia_assessments`

```php
Schema::table('tia_assessments', function (Blueprint $table) {
    $table->string('tia_code', 64)->nullable()->after('id');               // "TIA-HR-RECR-01"

    // RoPA + Vendor linkage (cross_border_id sudah ada)
    $table->uuid('linked_ropa_id')->nullable();
    $table->uuid('linked_vendor_id')->nullable();                          // for TPRM relation

    // Transfer description
    $table->string('transfer_volume', 32)->nullable();                     // 'low'|'medium'|'high'
    $table->string('transfer_frequency', 32)->nullable();                  // 'one_time'|'periodic'|'continuous'
    $table->string('transfer_basis', 64)->nullable();                      // 'contract'|'consent'|'bcr'|'other'
    $table->string('transfer_basis_other', 255)->nullable();

    // Country + recipient assessment
    $table->boolean('destination_has_pdp_law')->nullable();
    $table->boolean('destination_has_pdp_authority')->nullable();
    $table->unsignedTinyInteger('recipient_maturity_score')->nullable();   // 1-10
    $table->unsignedTinyInteger('sender_maturity_score')->nullable();      // 1-10

    // 6 risk metrics 1-10
    $table->unsignedTinyInteger('risk_regulation_mismatch')->nullable();
    $table->unsignedTinyInteger('risk_contractual_breach')->nullable();
    $table->unsignedTinyInteger('risk_admin_sanctions')->nullable();
    $table->unsignedTinyInteger('risk_data_leak')->nullable();
    $table->unsignedTinyInteger('risk_data_integrity')->nullable();
    $table->unsignedTinyInteger('risk_sovereign_access')->nullable();

    // 2 security metrics 1-10
    $table->unsignedTinyInteger('security_protocol_score')->nullable();
    $table->unsignedTinyInteger('security_encryption_score')->nullable();

    // Supplementary documents (file refs to Document model)
    $table->json('supplementary_doc_ids')->nullable();                     // array of Document UUIDs

    // Workflow (mirror LIA)
    $table->uuid('maker_id')->nullable();
    $table->uuid('checker_id')->nullable();
    $table->uuid('approver_id')->nullable();
    $table->timestamp('submitted_at')->nullable();
    $table->timestamp('checked_at')->nullable();
    $table->timestamp('approved_at')->nullable();
    $table->boolean('is_locked')->default(false);

    // Computed overall risk (auto-derived from 6+2 scores)
    $table->decimal('overall_risk_score', 5, 2)->nullable();               // weighted average

    $table->index(['org_id', 'tia_code']);
    $table->foreign('linked_ropa_id')->references('id')->on('ropas')->nullOnDelete();
    $table->foreign('linked_vendor_id')->references('id')->on('vendors')->nullOnDelete();
});
```

### Maturity — extend `maturity_assessments` + new table for question responses

```php
Schema::table('maturity_assessments', function (Blueprint $table) {
    $table->string('input_method', 32)->default('questionnaire');          // 'questionnaire'|'document'|'auto_derive'
    $table->json('domain_scores')->nullable();                             // { governance: 7.5, processing: 6, ... }
    $table->json('uploaded_doc_ids')->nullable();                          // file refs jika method=document
    $table->timestamp('submitted_at')->nullable();
    $table->uuid('submitted_by')->nullable();
});

// New table: per-question responses (more queryable than burying in dimensions JSON)
Schema::create('maturity_question_responses', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('assessment_id');
    $table->string('question_code', 32);                                   // 'A1'..'D18' atau 'governance.dpo_appointment'
    $table->string('domain', 32);                                          // 'governance'|'processing'|'controller_obligations'|'security'
    $table->unsignedTinyInteger('score');                                  // 1-10
    $table->text('notes')->nullable();
    $table->timestamps();

    $table->foreign('assessment_id')->references('id')->on('maturity_assessments')->cascadeOnDelete();
    $table->unique(['assessment_id', 'question_code']);
    $table->index(['assessment_id', 'domain']);
});

// Master list of questions (seedable, versioned per regulation update)
Schema::create('maturity_questions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('question_code', 32)->unique();
    $table->string('domain', 32);
    $table->string('regulation_ref', 100)->nullable();                     // 'Pasal 53'
    $table->text('question_text');
    $table->text('description')->nullable();
    $table->json('scoring_guide')->nullable();                             // { '1-3': '...', '4-6': '...', ... }
    $table->boolean('is_active')->default(true);
    $table->integer('sort_order')->default(0);
    $table->timestamps();
});
```

The 18 questions get seeded via `MaturityQuestionsSeeder` from the PDF spec (codes A1-A2, B3-B4, C5-C16, D17-D18). Versionable — tambah question baru kalau UU PDP update tanpa nyentuh existing schemas.

---

## Backend Relations Map

```
                  ┌─────────────┐
                  │    ROPA     │  ← context source untuk LIA + TIA
                  └──────┬──────┘
                         │
        ┌────────────────┼─────────────────┐
        ▼                ▼                 ▼
  ┌─────────┐       ┌─────────┐      ┌──────────────┐
  │   LIA   │──┬───►│  DPIA   │      │   CrossBorder │
  └─────────┘  │    └─────────┘      │   Transfer    │
               │                     └──────┬───────┘
               │                            │
               │     ┌─────────────────┐    │
               └────►│ Risk Register   │    │
                     │ (shared pattern │    ▼
                     │  with DPIA)     │ ┌───────┐    ┌─────────┐
                     └─────────────────┘ │  TIA  │───►│ Vendor  │
                                         └───┬───┘    │ (TPRM)  │
                                             │        └─────────┘
                                             ▼
                                  ┌──────────────────────┐
                                  │ Supplementary Docs   │
                                  │ (Document model)     │
                                  └──────────────────────┘

  ┌──────────────────────┐
  │ Maturity Assessment  │  ← reads-only aggregate dari semua di atas
  │ (no FK, service-     │     plus questionnaire + document upload
  │  layer aggregation)  │
  └──────────────────────┘
```

### TIA ↔ Cross-Border Transfer auto-trigger

Saat user save CrossBorderTransfer dengan `destination_country !== 'ID'`:
- Auto-suggest "Buat TIA untuk transfer ini" — banner di detail page
- Saat klik, redirect ke `/tia/new?cross_border_id=...&ropa_id=...` (auto-fill)

Reverse: di list TIA, tampilkan badge "Linked CBDT: [code]" untuk traceability.

### TIA ↔ Vendor (TPRM)

Saat user assess Vendor dan menandai vendor sebagai data processor cross-border:
- Auto-create TIA dengan `linked_vendor_id` pre-filled
- Saat TIA selesai, vendor risk score auto-update (overall vendor risk dipengaruhi TIA result)

Tabel relasi: tetap pakai `tia_assessments.linked_vendor_id` (single FK). Kalau 1 vendor punya N TIA (untuk N data flow berbeda), itu OK karena masing-masing TIA punya konteks RoPA berbeda.

### LIA ↔ DPIA

Existing: ROPA dengan risk=HIGH auto-create DPIA draft (sudah jalan). Baru: kalau RoPA pakai legitimate interest sebagai legal basis, tambahan trigger:
- Auto-suggest "Buat LIA untuk pemrosesan ini"
- Linked DPIA fillable di LIA wizard step 1 (auto-detect dari ROPA's linked DPIA)

### Maturity ↔ All modules (read-only auto-derive)

Service: `App\Services\MaturityAutoDeriveService`. Untuk metode `auto_derive`:

```php
public function deriveScores(string $orgId): array
{
    return [
        'A1_dpo_appointment' => $this->scoreDpoAppointment($orgId),         // dari User table dengan role=dpo
        'A2_org_structure'   => $this->scoreOrgStructure($orgId),           // dari Department table
        'B3_processing_basis'=> $this->scoreProcessingBasis($orgId),        // % ROPA dengan legal_basis filled
        'B4_subject_rights'  => $this->scoreSubjectRights($orgId),          // dari DSR module config
        'C5_ropa_quality'    => $this->scoreRopaQuality($orgId),            // ROPA count + completeness rate
        'C6_dpia_quality'    => $this->scoreDpiaQuality($orgId),            // DPIA count for HIGH-risk ROPA
        'C7_data_mapping'    => $this->scoreDataMapping($orgId),            // InformationSystem coverage
        'C8_dpa_contracts'   => $this->scoreDpaContracts($orgId),           // Vendor contract status
        'C9_data_accuracy'   => $this->scoreDataAccuracy($orgId),           // DSR rectification handled rate
        'C10_purpose_limit'  => $this->scorePurposeLimitation($orgId),
        'C11_retention'      => $this->scoreRetention($orgId),              // RetentionPolicy coverage
        'C12_encryption'     => $this->scoreEncryption($orgId),             // SecurityPosture findings
        'C13_breach_log'     => $this->scoreBreachLog($orgId),              // BreachIncident timeline_log
        'C14_processor_audit'=> $this->scoreProcessorAudit($orgId),         // Vendor assessment count
        'C15_privacy_design' => $this->scorePrivacyByDesign($orgId),
        'C16_training'       => $this->scoreStaffTraining($orgId),
        'D17_security'       => $this->scoreSecurity($orgId),               // SecurityPosture overall
        'D18_breach_response'=> $this->scoreBreachResponse($orgId),         // BreachIncident TTR < 72h rate
    ];
}
```

Ini bukan magic — tiap helper kembalikan integer 1-10 berdasarkan heuristik real:

- `scoreRopaQuality` = base 5, +1 per condition: ada >5 RoPA, semua RoPA punya legal_basis, semua RoPA punya retention, ada DPIA untuk HIGH risk, ada wizard_data >50% complete, etc.
- `scoreDpiaQuality` = base 3, +1 untuk setiap %20 RoPA HIGH yang ada DPIA-nya, +1 kalau DPIA ada mitigation_tracking, dst.

Masing-masing dijalankan async (cached 24 jam) supaya tidak nge-block save.

---

## API Endpoints Plan

### LIA

```
GET    /api/lia                         — list (sudah via /assessments/lia)
POST   /api/lia                         — create draft (auto-fill dari ropa_id query param)
GET    /api/lia/{id}                    — detail
PUT    /api/lia/{id}                    — update (block kalau is_locked)
POST   /api/lia/{id}/submit             — Maker submit, lock, status=submitted
POST   /api/lia/{id}/check              — Checker comment + status=checked
POST   /api/lia/{id}/approve            — Approver decide + conclusion
POST   /api/lia/{id}/reject             — Approver reject + reason
POST   /api/lia/from-ropa/{ropaId}      — Quick-create dari RoPA detail page
GET    /api/lia/{id}/export             — PDF export (compliance-ready)
```

### TIA

```
GET    /api/tia                         — list
POST   /api/tia                         — create draft
GET    /api/tia/{id}                    — detail
PUT    /api/tia/{id}
POST   /api/tia/{id}/submit
POST   /api/tia/{id}/check
POST   /api/tia/{id}/approve
POST   /api/tia/{id}/reject
POST   /api/tia/from-cross-border/{id}  — Quick-create dari Cross-Border detail
POST   /api/tia/from-vendor/{id}        — Quick-create dari Vendor TPRM
POST   /api/tia/{id}/upload-doc         — attach Document file (akta/kontrak)
GET    /api/tia/{id}/export
```

### Maturity

```
GET    /api/maturity                    — list (history of assessments)
POST   /api/maturity                    — create draft + pilih input_method
GET    /api/maturity/{id}               — detail (with question responses + computed level)
PUT    /api/maturity/{id}               — update (responses)
POST   /api/maturity/{id}/submit
POST   /api/maturity/{id}/auto-derive   — kalau method=auto_derive, run service
POST   /api/maturity/{id}/upload-docs   — kalau method=document, attach + AI score
GET    /api/maturity/{id}/recommendations  — generated dari level
GET    /api/maturity/questions          — master list (untuk wizard)
GET    /api/maturity/trend              — last N assessments comparison untuk chart
GET    /api/maturity/{id}/export        — PDF compliance report
```

---

## Migration Files to Create

```
database/migrations/2026_05_01_000001_extend_lia_assessments.php
database/migrations/2026_05_01_000002_extend_tia_assessments.php
database/migrations/2026_05_01_000003_extend_maturity_assessments.php
database/migrations/2026_05_01_000004_create_maturity_question_responses_table.php
database/migrations/2026_05_01_000005_create_maturity_questions_table.php
database/seeders/MaturityQuestionsSeeder.php
```

---

## Controller Strategy

Existing `AssessmentsController` (generic kind dispatcher) sudah cover basic CRUD. Tambahan needs **per-kind controllers** untuk workflow + relation handlers:

- `LiaController` — extends AssessmentsController concept, adds: submit, check, approve, reject, fromRopa, export
- `TiaController` — adds: submit/check/approve/reject, fromCrossBorder, fromVendor, uploadDoc, export
- `MaturityController` — adds: submit, autoDerive, uploadDocs, recommendations, questions, trend, export

Routes refactor: keep `/api/assessments/{kind}/*` for legacy generic CRUD (back-compat), tambah per-kind route group untuk workflow:
```php
Route::prefix('lia')->group(function () { Route::post('/{id}/submit', ...); ... });
Route::prefix('tia')->group(function () { ... });
Route::prefix('maturity')->group(function () { ... });
```

---

## Dependencies & Auto-Triggers

Update `App\Services\CrossModuleAutoTriggerService` (atau pattern existing di ROPA save hook):

```php
// Di RoPA save:
if ($ropa->legal_basis === 'legitimate_interest' && !$ropa->lia_id) {
    notify($ropa->dpo, 'Buat LIA untuk pemrosesan ini');
}

// Di CrossBorderTransfer save:
if ($cbdt->destination_country !== 'ID' && !$cbdt->tia_id) {
    notify($cbdt->dpo, 'Buat TIA untuk transfer ke ' . $cbdt->destination_country);
}

// Di Vendor save:
if ($vendor->processes_personal_data && $vendor->is_cross_border && !$vendor->tia_id) {
    notify($vendor->owner, 'Buat TIA untuk vendor cross-border ini');
}
```

---

## RACI Workflow Implementation

Reuse existing `ApprovalWorkflow` model + `ApprovalController` (already used by ROPA/DPIA/etc) — tambah module identifier `lia` + `tia`. Approval steps:

| Step | Role | Action |
|---|---|---|
| 1 | Maker (Process Owner / PIC) | Create + fill draft |
| 2 | Checker (Atasan PIC, opsional) | Review + comment, can reject back to maker |
| 3 | Approver (DPO) | Decide + conclusion |
| 4 | Informed (Atasan DPO) | Auto-CC notification |

`is_locked` flag set ke `true` saat status=submitted, di-unlock kembali kalau Checker/Approver reject.

---

## AI Integration (extend existing)

Existing route `POST /api/ai/assessment/{kind}/analysis` sudah ada. Tambahan needed:

1. **Per-question "Tanya AI" tooltip**: setiap pertanyaan LIA/TIA punya button "Tanya AI" — kirim question + context (RoPA data) → AI suggest jawaban berdasarkan tooltip + contoh use case.
   - Endpoint: `POST /api/ai/assessment/lia/suggest-answer` body: `{ question_code, ropa_id, current_answer? }`

2. **Document scoring untuk Maturity (input_method=document)**: upload SOP/Kebijakan, AI baca + map ke 18 questions + assign score.
   - Endpoint: `POST /api/ai/maturity/score-from-documents` body: `{ document_ids: [...] }`

3. **Recommendation generator**: setelah submit, kalau level=1, AI suggest action plan untuk move to level 2.
   - Endpoint: `POST /api/ai/maturity/{id}/recommendations`

4. **TIA risk auto-score**: berdasarkan country + vendor profile + ROPA scope, AI suggest 6 risk metrics (1-10).
   - Endpoint: `POST /api/ai/tia/{id}/risk-suggest`

Semua via `AiFeatureController` + `AiAgentToolExecutor` pattern existing — no new architecture, just new tools.

---

## Rollout Plan (Sprint Breakdown)

Plan untuk implementasi extension. Each sprint deliverable + commit-able:

### Sprint X1 — LIA full (1.5 minggu)
- Migration extend `lia_assessments`
- LiaController dengan workflow methods
- Routes per-kind
- Frontend: rebuild wizard dengan RoPA auto-fill + risk register table + RACI workflow + lock state
- Approval queue integration

### Sprint X2 — TIA full + Vendor relation (1.5 minggu)
- Migration extend `tia_assessments` + foreign key vendor
- TiaController + workflow
- Frontend: 8 ruler components + country fields + supplementary docs upload
- Cross-Border + Vendor auto-trigger

### Sprint X3 — Maturity questionnaire + auto-derive (1.5 minggu)
- Migration extend `maturity_assessments` + new question/response tables
- MaturityQuestionsSeeder (18 questions)
- MaturityController + AutoDeriveService
- Frontend: 1-10 ruler component (gradient red→green) + 4 input methods

### Sprint X4 — AI integration + reports (1 minggu)
- "Tanya AI" per pertanyaan
- Document AI scoring
- PDF export untuk semua 3 modules
- Trend chart untuk Maturity

### Total: ~5.5 minggu

---

## References

- `docs/new_feat/Fitur LIA.pdf` — full spec LIA dengan 8 sections + RACI
- `docs/new_feat/Fitur TIA.pdf` — full spec TIA dengan 6 metrics ruler + dokumen pelengkap
- `docs/new_feat/Privacy Compliance Maturity Assessment.pdf` — 18 questions + 4 levels + 3 input methods
- `frontend/docs/LIA_TIA_MATURITY_UI.md` — wireframes + UX flows + ruler component spec
- `BYODB.md` — tenant isolation (relevant if FI client wants per-tenant isolated assessments)
