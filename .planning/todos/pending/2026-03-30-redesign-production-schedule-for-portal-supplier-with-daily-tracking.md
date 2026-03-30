---
created: 2026-03-30T14:25:08.992Z
title: Redesign Production Schedule for portal/supplier with daily tracking
area: ui
files:
  - app/Filament/Portal/Resources/
  - resources/views/portal/infolists/pi-production-progress.blade.php
---

## Problem

The current Production Schedule experience is insufficient in two ways:
1. **Portal/Supplier input UX** — it's not easy or intuitive for portal/supplier users to enter and manage the production schedule. The UI needs to be visually modern and user-friendly.
2. **Forecast vs Actual tracking** — there is no daily mechanism to confirm forecasted production quantities against what was actually produced each day.

## Solution

- Analyze current production schedule models, relationships, and existing portal views
- Redesign the portal/supplier production schedule input UI (modern, visual, possibly calendar or timeline-based)
- Add a daily "confirm actual production" flow: for each scheduled day, the supplier/portal user marks actual quantity produced
- Surface forecast vs actual comparison (chart or table) visible to both admin and portal/client
- Consider notifications or alerts when actual falls behind forecast
