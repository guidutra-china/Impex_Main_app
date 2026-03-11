---
phase: 1
slug: production-control
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-11
---

# Phase 1 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x |
| **Config file** | phpunit.xml |
| **Quick run command** | `php artisan test --filter=ProductionSchedule` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~15 seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=ProductionSchedule`
- **After every plan wave:** Run `php artisan test`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 01-01-01 | 01 | 1 | PROD-01 | feature | `php artisan test --filter=SupplierProductionUpdateTest` | ❌ W0 | ⬜ pending |
| 01-02-01 | 02 | 1 | PROD-02 | unit | `php artisan test --filter=ProductionComparisonTest` | ❌ W0 | ⬜ pending |
| 01-03-01 | 03 | 2 | PROD-03 | unit | `php artisan test --filter=ShipmentReadyQuantityTest` | ❌ W0 | ⬜ pending |
| 01-04-01 | 04 | 2 | PROD-04 | feature | `php artisan test --filter=PaymentScheduleProductionTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/SupplierProductionUpdateTest.php` — stubs for PROD-01
- [ ] `tests/Unit/ProductionComparisonTest.php` — stubs for PROD-02
- [ ] `tests/Unit/ShipmentReadyQuantityTest.php` — stubs for PROD-03
- [ ] `tests/Feature/PaymentScheduleProductionTest.php` — stubs for PROD-04

*Existing test infrastructure (PHPUnit, factories) covers framework needs.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Supplier Portal UI renders correctly | PROD-01 | Visual/Livewire UI | Log in as supplier, navigate to production schedule, verify form renders |
| Planned vs actual visual indicators | PROD-02 | Visual styling | View production schedule, verify color coding for delays/ahead |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
