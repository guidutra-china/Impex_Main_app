---
created: 2026-03-30T14:25:08.992Z
title: Redesign Production Schedule for portal/supplier with daily tracking and component inventory
area: ui
files:
  - app/Filament/Portal/Resources/
  - resources/views/portal/infolists/pi-production-progress.blade.php
---

## Problem

The current Production Schedule experience is insufficient in three ways:
1. **Portal/Supplier input UX** — it's not easy or intuitive for portal/supplier users to enter and manage the production schedule. The UI needs to be visually modern and user-friendly.
2. **Forecast vs Actual tracking** — there is no daily mechanism to confirm forecasted production quantities against what was actually produced each day.
3. **Component/parts inventory visibility** — for each PO, there is no way for the supplier to indicate the status of each product's components: whether the part is already at the factory or still at a third-party supplier. This directly affects production readiness and should be reflected in the production schedule.

## Solution

### Production Schedule
- Analyze current production schedule models, relationships, and existing portal views
- Redesign the portal/supplier production schedule input UI (modern, visual, possibly calendar or timeline-based)
- Add a daily "confirm actual production" flow: for each scheduled day, the supplier/portal user marks actual quantity produced
- Surface forecast vs actual comparison (chart or table) visible to both admin and portal/client
- Consider notifications or alerts when actual falls behind forecast

### Component / Parts Inventory (per PO)
- Add a component inventory section per PO accessible to the supplier portal
- Each product in the PO can have sub-components listed; supplier marks each as:
  - `at_factory` — component already received at the factory
  - `at_supplier` — component still at a third-party supplier (with optional ETA)
  - `in_transit` — component shipped from third-party, not yet received
- Component readiness must be visible alongside the production schedule so delays in parts surface immediately as a risk to the scheduled production dates
- Admin view should aggregate component readiness per PO and flag POs at risk
