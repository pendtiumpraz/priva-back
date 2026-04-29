# LIA / TIA / Maturity — Progress Tracker

> Companion to `LIA_TIA_MATURITY_PLAN.md` (design) and `frontend/docs/LIA_TIA_MATURITY_UI.md` (wireframes).
> Live tracking of sprint progress, decision log, and risks. Update as work proceeds.

**Last updated**: 2026-04-29 (planning + Sprint X1 kickoff)

---

## Approval Workflow — Decision

User pertanyaan: **apakah perlu approval workflow di LIA/TIA/Maturity?**

| Module | Spec demands? | Decision | Implementation |
|---|---|---|---|
| **LIA** | ✅ **YES — eksplisit di PDF** (RACI matrix: DPO=Approver, Atasan PIC=Checker opsional, PIC=Maker, Atasan DPO=Informed) | **Wajib punya** | Reuse existing `ApprovalWorkflow` model + lock state + conclusion section yang diisi Checker/Approver |
| **TIA** | ✅ **YES — implicit di PDF** ("Maker____" + "Approval____" footer) | **Wajib punya** | Sama pattern dengan LIA, plus integrasi dengan Vendor risk score saat approve |
| **Maturity** | ❌ **NO** — PDF tidak mention RACI; ini self-assessment internal | **Tidak perlu formal approval** | Cukup status: `draft` → `submitted` → `published`. DPO submit, dashboard publish ke board. Tidak ada approver gate. |

### LIA RACI (sesuai PDF)
```
Maker      = Process Owner / PIC          [Responsible]
Checker    = Atasan Process Owner         [Consulted, opsional]
Approver   = DPO                          [Accountable]
Informed   = Atasan DPO                   [Auto-CC notification]
```
Setelah submit → lock (tidak bisa diedit). Checker review + bisa reject ke Maker. Approver isi conclusion (Lulus/Tidak Lulus per uji × 3) + final approve/reject.

### TIA RACI (inferred dari PDF + PRIVASIMU pattern)
```
Maker     = Process Owner / PIC
Approver  = DPO
Checker   = (opsional) Atasan PIC, terutama untuk transfer high-risk
Informed  = Atasan DPO + Vendor Owner (kalau linked_vendor_id)
```

### Maturity (no formal RACI)
```
Submitted_by = DPO atau Compliance team
Reviewed_by  = (opsional) Board / Audit committee — sebagai informational, bukan gate
Status       = draft → submitted → published
```

---

## Open Decisions

| # | Pertanyaan | Status | Catatan |
|---|---|---|---|
| AD1 | Approval workflow LIA wajib? | 🟢 decided | YES — RACI per PDF |
| AD2 | Approval workflow TIA wajib? | 🟢 decided | YES — Maker + Approver minimal |
| AD3 | Approval workflow Maturity? | 🟢 decided | NO — status flow saja |
| AD4 | LIA approval reuse existing `ApprovalWorkflow` model atau dedicated columns? | 🟢 decided | **Dedicated columns** di `lia_assessments` (`maker_id`, `checker_id`, `approver_id`, timestamps, `is_locked`). Lebih query-friendly dari pivot ApprovalWorkflow generic. |
| AD5 | TIA risk score weight ke vendor risk overall | ⏳ pending | Confirm dengan product: kalau TIA approved dengan high risk, apakah Vendor.overall_risk_score auto-update? |
| AD6 | Maturity 3 input methods — semua di v1 atau staged? | 🟢 decided | **Staged**: Sprint X3 ship method 1 (questionnaire) + method 3 (auto-derive). Method 2 (document AI scoring) di Sprint X4 bareng AI integration. |
| AD7 | LIA conclusion 3-verdict — bisa partial pass (lulus 2 dari 3 uji)? | 🟢 decided | YES — overall result = combination of 3 verdicts. UI tunjukkan badge per uji + overall badge. |
| AD8 | Lock state — admin (root) bisa unlock LIA submitted? | 🟢 decided | YES — emergency override via tinker / admin UI, tapi tidak via normal user flow. Audit log mandatory. |

---

## Milestones

Status: 🔵 not started · 🟡 in progress · 🟢 done · 🔴 blocked

### Sprint X1 — LIA Full Implementation 🟢 (BE + FE done 2026-04-29; PDF export deferred to X4)

**Goal**: LIA module sesuai PDF spec end-to-end — RoPA auto-fill, 5-step wizard, risk register, RACI workflow, lock state, PDF export.

**Effort**: 1.5 minggu

**Backend tasks** (BE commit: `c5a40ae`):
- [x] Migration `extend_lia_assessments`: 13 kolom baru (lia_code, linked_dpia_id, legitimate_interest_*, balancing_risk_events, subject_loses_control_*, conclusion_*, maker_id/checker_id/approver_id + timestamps, is_locked + unlocked_*)
- [x] `LiaController` dengan workflow methods (CRUD + submit/check/approve/reject/fromRopa/unlock + override `update` block kalau locked)
- [x] Routes `/api/lia/*` (13 endpoints, verified via `php artisan route:list`)
- [x] `ROPA_AUTOFILL_FIELDS` const + `fromRopa()` snapshot logic
- [ ] Auto-trigger di RoPA save: deferred (notif system rebuild di sprint terpisah)
- [ ] PDF export → Sprint X4

**Frontend tasks** (FE commit: `feat(lia): 5-step wizard + RACI workflow UI`):
- [x] `<MaturityRuler>` — 1-10 ruler red→green gradient, level labels, `inverted` prop, customLevelLabels
- [x] `<RopaAutoFillCard>` — read-only 13-field display dengan icon per field
- [x] `<RaciWorkflowStepper>` — horizontal stepper Maker→Checker→Approver→Done + lock badge + rejection banner + compact mode
- [x] `<RiskRegisterTable>` — auto-calc Inheren (dampak × prob) + Residual (× faktor kontrol), seed-5-defaults, locked mode
- [x] Refactor `(dashboard)/lia/page.tsx`:
  - [x] List view: 4 KPI cards + status filter + search + lock icon + verdict pill
  - [x] 5-step wizard mengikuti PDF spec persis
  - [x] Detail view dengan RaciWorkflowStepper + section cards + workflow buttons + Approve modal (3 verdict picker)
  - [x] Lock-state UI di list + edit blocked + root-only Emergency Unlock button
- [ ] PDF export → Sprint X4

**Acceptance criteria**:
- [ ] Bisa create LIA dari RoPA detail page (button "Create LIA")
- [ ] 13 RoPA fields auto-fill di Step 1
- [ ] Risk register table di Balancing Test berfungsi (dampak × prob → inheren auto-calc)
- [ ] Submit flow: Maker submit → Checker review → Approver decide
- [ ] Lock state enforced di backend + frontend
- [ ] Conclusion 3-verdict diisi Approver
- [ ] PDF export menghasilkan compliance-ready document

---

### Sprint X2 — TIA Full Implementation 🟢 (BE + FE done 2026-04-30; PDF export deferred ke X4)

**Goal**: TIA module dengan 8 risk metrics ruler, country assessment, supplementary docs, Vendor relation, RACI workflow.

**Effort**: 1.5 minggu

**Backend tasks**:
- [ ] Migration `extend_tia_assessments`: tambah `tia_code`, `linked_ropa_id`, `linked_vendor_id`, `transfer_volume`, `transfer_frequency`, `transfer_basis`, `destination_has_pdp_law`, `destination_has_pdp_authority`, `recipient_maturity_score`, `sender_maturity_score`, 6 risk metrics, 2 security metrics, `supplementary_doc_ids`, RACI fields, `overall_risk_score` (computed)
- [ ] `TiaController` dengan workflow methods (sama pattern LIA)
  - [ ] `fromCrossBorder` shortcut
  - [ ] `fromVendor` shortcut (TPRM integration)
  - [ ] `uploadDoc` — attach Document model file
- [ ] Auto-compute `overall_risk_score` dari 6 risk + 2 security metrics
- [ ] Auto-trigger CrossBorder save: destination_country !== 'ID' → suggest TIA
- [ ] Auto-trigger Vendor save: cross_border + processes_personal_data → suggest TIA
- [ ] (AD5 pending) saat TIA approved high-risk, optional: update Vendor.overall_risk_score

**Frontend tasks**:
- [ ] Component `<TiaRiskHeatmap>` — radar chart 8 metrics
- [ ] Refactor `(dashboard)/tia/page.tsx`:
  - [ ] List view dengan negara + vendor columns
  - [ ] 6-step wizard (Source, Transfer Description, Pihak Assessment, Risk Ruler×6, Security Ruler×2, Supplementary Docs)
  - [ ] Detail page dengan radar visualization
- [ ] Reuse `<MaturityRuler>` dengan `inverted` prop untuk risk metrics

---

### Sprint X3 — Maturity Full Implementation 🔵

**Goal**: 18 UU PDP questions, 1-10 ruler, 2 input methods (questionnaire + auto-derive), recommendations, trend chart.

**Effort**: 1.5 minggu

**Backend tasks**:
- [ ] Migration `extend_maturity_assessments`: tambah `input_method`, `domain_scores`, `uploaded_doc_ids`, `submitted_at`, `submitted_by`
- [ ] Migration `create_maturity_question_responses_table`
- [ ] Migration `create_maturity_questions_table`
- [ ] Seeder `MaturityQuestionsSeeder` — 18 questions sesuai PDF (A1-A2, B3-B4, C5-C16, D17-D18)
- [ ] `MaturityController`:
  - [ ] `index`, `show`, `store`, `update`
  - [ ] `submit` — finalize + compute overall_score + level
  - [ ] `autoDerive` — call MaturityAutoDeriveService
  - [ ] `recommendations` — return template recommendations berdasarkan level
  - [ ] `questions` — master list untuk wizard
  - [ ] `trend` — last N assessments untuk chart
  - [ ] `export` — PDF compliance report
- [ ] Service `App\Services\MaturityAutoDeriveService` — 18 helper methods scoreXXX($orgId)
- [ ] AutoDerive cache 24 jam (avoid recomputation cost)

**Frontend tasks**:
- [ ] Component `<MaturityGauge>` — score gauge with color
- [ ] Component `<MaturityRadarChart>` — 4 domain breakdown radar
- [ ] Component `<MaturityTrendChart>` — line chart of past assessments
- [ ] Refactor `(dashboard)/maturity/page.tsx`:
  - [ ] Landing dashboard dengan gauge + domain cards + trend + past list
  - [ ] 4-step wizard (input method picker, questionnaire/auto-derive, review, result+recs)
  - [ ] Detail page tabs: Overview, Per-Domain, Per-Question, Trend, Recommendations
- [ ] Recommendation panel based on level (4 templates dari PDF)

---

### Sprint X4 — AI Integration + Reports 🔵

**Goal**: "Tanya AI" tombol per pertanyaan, document AI scoring untuk Maturity input_method=document, PDF export semua 3 modules, share with board feature.

**Effort**: 1 minggu

**Backend tasks**:
- [ ] AI tools di `AiAgentToolExecutor`:
  - [ ] `lia_suggest_answer($questionCode, $ropaId, $currentAnswer)` — context-aware suggestion
  - [ ] `tia_risk_suggest($tiaId)` — pre-fill 6 risk metrics berdasarkan country + vendor profile
  - [ ] `maturity_score_documents($docIds)` — AI baca SOP/Kebijakan + map ke 18 questions
  - [ ] `maturity_recommendations($assessmentId)` — generate concrete action plan
- [ ] PDF export controllers (3 modules) — Compliance-ready format dengan chart embeds
- [ ] Email/Slack notification untuk approval queue + Informed party

**Frontend tasks**:
- [ ] "Tanya AI" button di setiap question (LIA, TIA, Maturity) — modal dengan AI response
- [ ] Document upload + AI scoring UI (Maturity Sprint X3 stub upgrade)
- [ ] PDF download dengan filename pattern `LIA-{code}-{date}.pdf`, dst.
- [ ] "Share with Board" feature — generate signed URL ke read-only view

---

## Risk Register (project-level, bukan compliance risk)

| ID | Risk | Severity | Mitigation |
|---|---|---|---|
| PR1 | RoPA model belum punya semua 13 fields yang dibutuhkan LIA auto-fill | Medium | Audit RoPA model dulu, tambah missing columns kalau perlu (kemungkinan ada `legal_basis_detail`, `entity` dll) |
| PR2 | DPIA model relation belum siap untuk LIA `linked_dpia_id` | Low | DPIA tabel sudah ada — cuma tambah FK |
| PR3 | Auto-derive heuristic Maturity bisa salah skor | Medium | Allow manual override di review step + log auto-derived vs final score |
| PR4 | TIA risk score → Vendor risk update bisa cyclic | Low | Update via async event, tidak bidirectional, document di code comments |
| PR5 | Lock state bug bisa hilang data Maker | High | Save Draft auto-save di FE setiap field change + backup di wizard_data JSON |
| PR6 | 18 Maturity questions bisa berubah ikut update UU PDP | Low | Versionable via `MaturityQuestion.version` + answer locked ke version saat submit |
| PR7 | Existing data di shallow LIA/TIA model bisa konflik dengan extension | Medium | Migration guard: hanya add kolom, tidak modify existing |

---

## Decision Log

| Date | Decision | Reasoning |
|---|---|---|
| 2026-04-29 | LIA + TIA wajib approval workflow, Maturity tidak | PDF spec eksplisit RACI untuk LIA/TIA; Maturity adalah self-assessment internal |
| 2026-04-29 | Approval workflow pakai dedicated columns, bukan `ApprovalWorkflow` pivot | Query-friendly + lebih jelas state per record. ApprovalWorkflow pivot tetap dipakai untuk modul lain (ROPA, DPIA) |
| 2026-04-29 | Maturity 3 input methods — staged release | Method 2 (document AI) butuh AI tooling yang baru → Sprint X4. Method 1 (questionnaire) + 3 (auto-derive) bisa di Sprint X3 |
| 2026-04-29 | LIA conclusion = 3 verdict separate (Tujuan/Kebutuhan/Keseimbangan), bukan single verdict | Sesuai PDF spec; UX juga lebih jelas |
| 2026-04-29 | Lock state irreversible kecuali emergency unlock by root | Audit-friendly + sesuai PDF "tidak bisa diedit kembali" |

---

## Glossary

- **LIA** — Legitimate Interest Assessment (UU PDP Pasal 20). Penilaian apakah pemrosesan data berdasarkan kepentingan sah valid.
- **TIA** — Transfer Impact Assessment. Penilaian risiko transfer data lintas batas.
- **Maturity Assessment** — Privacy Compliance Maturity. Self-evaluation tingkat kepatuhan PDP organisasi (4-level CMMI-style).
- **RACI** — Responsibility Assignment Matrix (Responsible, Accountable, Consulted, Informed).
- **CBDT** — Cross-Border Data Transfer (existing module, parent dari TIA).
- **TPRM** — Third-Party Risk Management (existing Vendor Risk module, sister dari TIA).
- **Maturity Ruler** — UI component custom: 1-10 scale dengan red→green gradient + level labels.

---

## See Also

- `backend/docs/LIA_TIA_MATURITY_PLAN.md` — schema gaps + relations + API plan
- `frontend/docs/LIA_TIA_MATURITY_UI.md` — wireframes + component spec + UX flow
- `docs/new_feat/Fitur LIA.pdf` — original spec
- `docs/new_feat/Fitur TIA.pdf` — original spec
- `docs/new_feat/Privacy Compliance Maturity Assessment.pdf` — original spec
