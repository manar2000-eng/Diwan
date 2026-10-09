# PLAN-006 Daily Report + Emergency Close

Spec: `docs/ai-sdlc/specs/SPEC-006-report-emergency.md` (approved)
Planner: `.opencode/agents/architect.md`
Depends on: PLAN-000 (auth/roles), PLAN-002 (seats + shifts), PLAN-004 (`createBooking()` emergency check), PLAN-005 (`payments_cash`)

## Starting state
Bookings, attendance and cash rows all exist. Nothing aggregates them, and `emergency_closures` is never written — PLAN-003 and PLAN-004 already *read* it and are currently dead branches because it is always empty.
This is the last feature plan and the only one that touches PLAN-003/004 code.

## Smallest change
Two pages and four functions. No charting library — the report is a 4-row table of numbers, and a chart would be the heaviest asset on the lowest-bandwidth target. No PDF/CSV export.
`emergency_closures` is write-only for humans; PLAN-003 (hide from search) and PLAN-004 (reject booking) consume it, so this plan closes the loop those two already left open.

## Files to create

| File | Purpose |
|------|---------|
| `dashboard/hub/reports.php` | `requireRole(['hub_admin'])` — **staff must get 403**. Date picker + totals + occupancy table |
| `dashboard/hub/emergency.php` | `requireRole(['hub_admin'])` — form to close a branch for a date, plus the closure list with a remove action |
| `includes/functions.php` *(modify)* | Add `getDailyReport()`, `getOccupancy()`, `addEmergencyClose()`, `listClosures()`, `removeEmergencyClose()` |

Step 4 ("wire the emergency clause into PLAN-003/004") needed no change: both clauses shipped with those plans and were only waiting for rows. Closing a date is what makes them live.

## Functions to add

```
getDailyReport(hubId, date)
  bookings_total  = COUNT(*) WHERE hub_id=? AND booking_date=?
  attended        = COUNT(*) WHERE ... AND status='attended'
  confirmed       = COUNT(*) WHERE ... AND status='confirmed'
  pending         = COUNT(*) WHERE ... AND status='pending'
  cancelled       = COUNT(*) WHERE ... AND status='cancelled'
  cash_total      = COALESCE(SUM(amount),0) FROM payments_cash
                   WHERE hub_id=? AND DATE(received_at)=?     -- INV-01
  branches[]      = for each active branch of this hub:
                      per active shift:
                        getOccupancy(branchId, date, shiftId)
```

```
getOccupancy(branchId, date, shiftId): float
  -- INV-05 locked. No alternative formula is permitted.
  SELECT
    (SELECT COUNT(*) FROM bookings
      WHERE branch_id=? AND booking_date=? AND shift_id=?
        AND status IN ('confirmed','attended'))            AS numerator,
    (SELECT COUNT(*) FROM seats
      WHERE branch_id=? AND active=1)                      AS denominator
  -- denominator 0 => return 0.0 (no divide-by-zero on a fresh hub)
  -- pending and cancelled are excluded from the numerator (spec fail case)
```
This is the single definition of occupancy. PLAN-003's `getAvailability()` stays separate — it counts `pending,confirmed` because a pending seat is still held. The two differ **only** in numerator, and both are spec-locked; do not unify them.

```
addEmergencyClose(hubId, branchId, date, reason)
  - branch must belong to hub (INV-01)
  - date >= CURDATE() else 'تاريخ غير صالح'
  - reason non-empty
  - INSERT emergency_closures(hub_id, branch_id, close_date, reason)
    -> PLAN-000 UNIQUE(branch_id, close_date) => duplicate => 'الإغلاق مسجل مسبقاً'
  - Existing bookings are NOT touched (spec R2: they stay + flagged).
    No UPDATE, no DELETE, no notification.
```

```
listClosures(hubId)  -- upcoming + past, for the form page and reports.php badge
```

## Page structure
- `reports.php`: date input (default today) + cards for `إجمالي الحجوزات`, `الحضور`, `الإلغاءات`, `النقد المحصل`, then a per-branch/per-shift occupancy table (`4/10 — 40%`).
- `emergency.php`: branch select + date + reason + submit; list of upcoming closures with a remove action.

## Steps
1. Add the 4 functions to `includes/functions.php`. This is the **only** change to previously-shipped functions.
2. `dashboard/hub/reports.php`.
3. `dashboard/hub/emergency.php`.
4. Add the emergency-closure check to PLAN-004 `createBooking()` — already planned in step 7 there; this is only where it becomes live. Also add the `NOT EXISTS` clause to PLAN-003 `searchHubs()`, already planned in step 1 there.
5. Seed 10 seats, 4 attended at 10 → income 40, occupancy 0.4.
6. Close date D, try booking D then D+1.
7. Lint + run tests.

## Reuse
- `requireRole(['hub_admin'])` — PLAN-000. This is where the "staff cannot access finance reports" rule from `AGENTS.md` is actually enforced.
- `getOccupancy()`'s denominator `COUNT(seats WHERE active=1)` — already guaranteed by PLAN-002's seat rules.
- `subscriptionOk()` is **not** used here; a hub owner can always read their own report, even if their subscription lapsed. Only search and booking are gated.
- PLAN-003 `searchHubs()` and PLAN-004 `createBooking()` already contain the clauses this feature feeds; no rewrite, only data to act on.
- Bootstrap tables/cards from the CDN already loaded by `layout.php`.

## Out of scope
Cross-hub platform analytics for super_admin (explicitly OUT per SPEC-006), charts, PDF/CSV export, date-range reports, monthly/weekly rollups, expense tracking, subscription revenue page, tax, refunds, closure approval workflow, notifying affected clients.

## Rollback note
```
rm dashboard/hub/reports.php dashboard/hub/emergency.php
git checkout includes/functions.php     # drops the 4 functions AND the two
                                        # NOT- EXISTS clauses added in step 4
DELETE FROM emergency_closures;         -- optional
```
Reverting `functions.php` reverts PLAN-003/004's emergency handling too, since both live in that file — they become permanently-inert `NOT EXISTS`/no-op checks, which is safe.

## Tests → acceptance mapping

| Spec acceptance | Verification | Result |
|---|---|---|
| 10 seats, 4 attended × 10 → income 40, occupancy 0.4, pending excluded | Seed; `getDailyReport()` → `cash_total = 40`, `getOccupancy()` = `0.4`. Add a 5th `pending` booking and confirm income and occupancy do **not** move | ☐ |
| Emergency row for D → book D rejects `مغلق طارئ`; book D+1 succeeds | Via `createBooking()` (PLAN-004) on D and D+1 | ☐ |
| Staff GET reports → 403 | staff session GET `/dashboard/hub/reports.php` → `403` | ☐ |
| `pending`/`cancelled` excluded from occupancy numerator | 10 seats, 3 confirmed + 1 attended + 1 pending → `0.4`; flip pending→cancelled, still `0.4` | ☐ |
| Occupancy is per branch+date+shift | Two branches, different occupancy → each row reports its own value | ☐ |
| No divide-by-zero on a hub with 0 active seats | Set `active=0` on all seats → `getOccupancy()` returns `0.0`, no PHP warning | ☐ |
| `hub_id` never from GET/POST | GET `reports.php?hub_id=<other>` → only session hub's totals | ☐ |
| Existing bookings survive a closure | Insert 3 bookings for D, close D → all 3 still present, statuses unchanged | ☐ |
| Duplicate closure rejected | Close D twice → `الإغلاق مسجل مسبقاً` | ☐ |
| Closed date hidden from search | Close D → `GET /public/search.php?date=D` returns no card for that branch | ☐ |
| Past close date rejected | Close a past date → `تاريخ غير صالح` | ☐ |
| Cash total is hub-scoped | Two hubs with payments on the same day → each report shows only its own | ☐ |
| Missing CSRF rejected | POST emergency without token → no row inserted | ☐ |
| No chart library, no export | Inspect `reports.php`: plain table, no JS chart asset | ☐ |
| `php -l` | `php -l dashboard/hub/reports.php`, `php -l dashboard/hub/emergency.php` | ☐ |
| Reload, check RTL + size | Load `/dashboard/hub/reports.php`, confirm RTL and <1.5MB | ☐ |
