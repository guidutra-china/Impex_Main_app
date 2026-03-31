---
phase: 4
slug: redesign-production-schedule-ux
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-31
---

# Phase 4 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x (via Pest) |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --filter=ProductionSchedule` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~30 seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=ProductionSchedule`
- **After every plan wave:** Run `php artisan test`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| TBD | TBD | TBD | TBD | TBD | TBD | TBD | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/ProductionSchedule/ComponentModelTest.php` — stubs for component CRUD
- [ ] `tests/Feature/ProductionSchedule/StatusWorkflowTest.php` — stubs for submit/approve/reject
- [ ] `tests/Feature/ProductionSchedule/ConfirmDailyProductionTest.php` — stubs for daily confirm flow

*Existing infrastructure covers test framework and fixtures.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Calendar/timeline visual layout | UX redesign | Visual rendering | Open portal, verify date-strip renders correctly with color coding |
| Component risk badge visibility | Risk flagging | Visual indicator | Create PO with late-ETA component, verify risk badge appears |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
