# Feature Audit Spec — Shared Reference

Spec untuk 20 parallel agents yang masing-masing assess 1 module Privasimu.
Output di-assemble jadi `backend/docs/PLATFORM_FEATURE_AUDIT.md`.

## Audit Goals

Untuk setiap module:
1. **Functional completeness** — apakah fitur spec di dokumen sudah diimplementasi?
2. **Integration status** — apakah ter-wire ke modul lain (AI, audit, notif)?
3. **Production readiness** — apakah ada gap yang block production deploy?
4. **Quality concerns** — code smell, security, perf, UX
5. **Belum aktif** — code ada tapi belum di-route, atau pre-wired tapi config disabled

## Output Format (LOCKED per agent)

Setiap agent harus output dengan format INI VERBATIM:

```markdown
## Module: <Name>

**Status overall:** ✅ Active / ⚠️ Partial / ❌ Not Active / 🟡 Ready but Disabled

**Satisfaction score:** X/10

**Verdict 1-liner:** <one sentence yang bisa quote untuk executive summary>

### What Works ✅
- Bullet list fitur yang fully implemented + ter-wire + tested
- Pakai file:line references untuk evidence

### Partially Working ⚠️
- Fitur yang code ada tapi belum lengkap (mis. backend ada, FE belum)
- Atau ada bug minor yang tidak block

### Not Active / Missing ❌
- Fitur yang sudah di-doc tapi belum diimplementasi
- Atau code ada tapi tidak di-route / dimatikan
- Atau dependency lain yang belum siap

### Quality Concerns 🔍
- Security issue (kalau ada)
- Performance bottleneck
- UX confusion / accessibility
- Test coverage gap

### Quick Wins 🎯
- 2-5 item kecil yang kalau di-fix bisa bump score 1-2 point
- Format: "Fix X di file:line — estimate Y jam"

### Verdict
1-2 paragraf honest assessment. Production ready? Klien BUMN bisa demo besok?
Apa yang akan bikin auditor / DPO worry?
```

## Word Limit

Maks **800 kata per agent**. Spec ini sudah ~400 kata, jadi total report
sekitar 16k kata (manageable).

## Scoring Guide

| Score | Meaning |
|---|---|
| 10/10 | Production-ready, full coverage, integrated, tested |
| 8-9 | Solid, minor gaps yang acceptable |
| 6-7 | Functional core tapi gaps yang harus di-fix sebelum SoftLaunch |
| 4-5 | Skeleton ada tapi banyak feature belum jalan |
| 1-3 | Partial implementation, banyak yang miss |
| 0 | Tidak diimplementasi sama sekali |

## Rules

1. **Honest, jangan kompromi** — kalau scoring jelek, kasih jelek. User pakai
   feedback memory "Match reference quality, jangan kompromi".
2. **Code evidence wajib** — setiap claim sebut file:line.
3. **Bahasa Indonesia formal** (Anda/sudah/tidak), bukan casual.
4. **Cross-reference module lain** kalau relevan — pakai `[[module-name]]`.
5. **Konsisten format** — agent assembly nanti merge dengan pattern fixed.
6. **Tidak edit file lain** — output cuma report ke caller, biar caller
   assemble ke single .md.

## Module Assignments (20 agents)

| # | Module | Scope |
|---|---|---|
| 1 | Authentication + RBAC + Org | Sanctum, 2FA, password policy, lockout, tenant roles, SSO |
| 2 | RoPA | Wizard 7 step, auto-risk, approval, templates, export, history |
| 3 | DPIA | 21 kategori, risk events, RTP, RACI, framework editor |
| 4 | LIA + TIA | 3-stage RACI, lock/unlock, export PDF, auto-trigger |
| 5 | GAP Assessment | 33 indikator, custom questions, comparison, evidence upload |
| 6 | Maturity Assessment | 18/33 Q, 4 domain, auto-derive, recommendations |
| 7 | DSR Management | Public widget, SQL pack, execution tracking, certificate |
| 8 | Consent + Cookie Banner | Collection, capture, CRM extract, webhook, multi-locale |
| 9 | TPRM Full | Library, 3-stage workflow, AI screening, monitoring, incidents |
| 10 | Data Discovery | Standard scan, AI deep scan, leak detection, person scan, OCR |
| 11 | Cross-Border + Adequacy | Country lookup, transfer rubric, TIA auto-trigger |
| 12 | Breach Management | Containment, RACI, timeline, PDF Komdigi, integrations |
| 13 | Security Posture | Snapshot, findings, alerts, baseline detection |
| 14 | Fire Drill | Scenarios, simulation, score, history, export |
| 15 | AI Agent + Function Calling | Chat, tools, approval flow, anti-injection, credits |
| 16 | AI Features cross-module | Autofill RoPA/DPIA, Risk Scoring, Breach Advisor, DSR drafter |
| 17 | RAG / Vector DB (NEW) | pgvector, EmbeddingService, semantic search tools, RLS |
| 18 | Platform Admin | System settings, users, licenses, menu registry, white-label |
| 19 | Notifications + KB + Doc Import | Inbox, preferences, KB CRUD, batch upload |
| 20 | API Hub + Partner API | API keys, webhook delivery, rate limit, audit |

End of spec.
