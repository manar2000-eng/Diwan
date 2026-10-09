# PLAN-002 Branch + Seats + Shifts

Spec: `docs/ai-sdlc/specs/SPEC-002-branch-seats-shifts.md` (approved)
Planner: `.opencode/agents/architect.md`
Depends on: PLAN-000 (auth, layout, schema), PLAN-001 (active hub session exists)

## Starting state
`hubs` active with a valid subscription. `branches`, `seats`, `shifts` tables exist but are empty.
No hub-side dashboard at all — `/dashboard/hub/` does not resolve yet.

## Smallest change
Three CRUD pages and four functions. MVP says **1 branch**, so `branches` is created once and then edited — no multi-branch management UI, even though `branch_id` exists as a column because `emergency_closures` and occupancy are per-branch.
Add `price_per_hour` to `branches` now (one column) rather than a `rooms` pricing table — SPEC-002 puts rooms pricing OUT, and PLAN-003 needs a price to render on the card.

## Files to create

| File | Purpose |
|------|---------|
| `dashboard/hub/index.php` | Redirect to `branches.php` |
| `dashboard/hub/branches.php` | `requireRole(['hub_admin'])`. Create + edit the single branch |
| `dashboard/hub/seats.php` | List + bulk-add seats per branch |
| `dashboard/hub/shifts.php` | List + create/update shifts per branch |
| `includes/functions.php` *(modify)* | Add `createBranch()`, `updateBranch()`, `createSeats()`, `createShift()`, `updateShift()`, `listShifts()` |

## Functions to add

```
createBranch(city, address, electricity_status, internet_strength, generator_solar, price_per_hour)
  - hub_id = $_SESSION['hub_id']                    (INV-01, never from POST)
  - active = 1
  - 1 branch per hub: SELECT id FROM branches WHERE hub_id=? AND active=1
    -> already exists => 'تم إنشاء الفرع مسبقاً'
  - missing city => validation error

createSeats(branchId, codes[])      <- one row per code, one transaction
  - hub_id from session; branch must belong to that hub  (cross-hub guard)
  - UNIQUE(branch_id, seat_code) -> duplicate => 'كود المقعد مكرر'
  - active = 1
  - Accepts codes[] so the owner can add 20 seats in one POST, not 20 POSTs
    (smallest change that makes seeding realistic; still one INSERT per code)

createShift(branchId, name_ar, start_time, end_time)
  - hub_id from session; branch must belong to it
  - start_time < end_time else 'وقت النهاية يجب أن يكون بعد البداية'
  - overlap: existing WHERE branch_id=? AND active=1
             AND start_time < new.end_time AND end_time > new.start_time
             => 'تداخل في الورديات'
  - active = 1
  - MVP guard: COUNT(active shifts in branch) < 2 required, else
    'مطلوب ورديتان على الأقل'  (blocks 3rd-less setups, not the 2nd one)

updateShift(shiftId, name_ar, start_time, end_time)
  - re-runs the same start<end + overlap check, excluding itself
```

Overlap is checked in the Logic Layer with a plain `SELECT`, not a lock — two owners editing shifts simultaneously is not a realistic MVP race, and the DB `UNIQUE(branch_id, start_time, end_time)` catches exact duplicates.

## Steps
1. Add the 6 functions to `includes/functions.php`.
2. `dashboard/hub/index.php` → redirect.
3. `branches.php` — the electricity/internet/generator fields the card later renders (PLAN-003 R2).
4. `seats.php` — bulk add + list with `active=1` count.
5. `shifts.php` — create + edit, showing `start–end` per branch.
6. Seed a realistic hub: 1 branch, 10 seats, shifts 08:00–14:00 and 14:00–22:00.
7. Lint + run tests.

## Reuse
- `requireRole(['hub_admin'])`, `csrf_*`, `pageStart/pageEnd`, `redirect()`, flash, `e()` — all PLAN-000.
- `$_SESSION['hub_id']` written by PLAN-001; read-only from here on.
- `shifts(branch_id, start_time, end_time)` UNIQUE from the PLAN-000 schema — no extra index needed here.

## Out of scope
Multiple branches per hub UI, rooms, room/hourly tier pricing, seat maps, shift deletion (deactivate only), seat numbering schemes, bulk CSV import, per-shift pricing.

## Rollback note
```
rm -r dashboard/hub
git checkout includes/functions.php     # drops the 6 added functions
DELETE FROM shifts; DELETE FROM seats; DELETE FROM branches;   -- optional
```
PLAN-000 and PLAN-001 are untouched and keep working.

## Tests → acceptance mapping

| Spec acceptance | Verification | Result |
|---|---|---|
| 1 branch + 10 seats + 2 shifts (08:00-14:00, 14:00-22:00) saved, dashboard counts match DB | Seed via the UI, then compare the on-screen counts against `SELECT COUNT(*) FROM seats WHERE branch_id=? AND active=1` and the shifts list | ☐ |
| Overlapping 13:00-15:00 → reject, COUNT unchanged | Create 13:00-15:00 on the branch that already has 08:00-14:00 → exact string `تداخل في الورديات`, `COUNT(shifts)` still 2 | ☐ |
| Cross-hub `shift_id` in booking → reject | HubA `shift_id` passed to hubB's branch → `createBooking()` rejects (mechanics in PLAN-004); here assert `createShift()`/`updateShift()` also reject a foreign `branch_id` | ☐ |
| `hub_id` never from GET/POST | POST `hub_id=<other>` to seats/shifts → `SELECT hub_id FROM seats WHERE seat_code=<posted>` equals session hub | ☐ |
| staff/client → 403 on these pages | staff session GET `seats.php` → `403` | ☐ |
| Missing city → validation error | Submit empty city → message, no `branches` row | ☐ |
| Duplicate `seat_code` same branch → reject | Add `A-01` twice → `كود المقعد مكرر` | ☐ |
| `start_time < end_time` enforced | 22:00 → 08:00 → `وقت النهاية يجب أن يكون بعد البداية` | ☐ |
| Only 1 shift → `مطلوب ورديتان على الأقل` | Create a single shift on a fresh branch → exact string | ☐ |
| Seat count is the occupancy denominator | `SELECT COUNT(*) FROM seats WHERE active=1` returns the value PLAN-006 divides by | ☐ |
| Card shows electricity/internet/generator | Values saved in `branches` appear on the PLAN-003 hub card | ☐ |
| `php -l` | `php -l` on all 3 dashboard files | ☐ |
| Reload dashboard, check RTL + size | Load `/dashboard/hub/shifts.php`, confirm RTL and <1.5MB | ☐ |
