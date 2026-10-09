# SPEC-002 Branch + Seats + Shifts

Source: `intent/diwan.md` L24 `branch+seats+2 shifts` + decision 2026-10-09: shifts configurable per hub
Status: approved
Related: SPEC-000-shared.md (INV-01..06)

## Scope
- IN: Hub_admin creates 1 branch, seats, >=2 configurable shifts so search/book has inventory.
- OUT: Map, rooms hourly pricing tiers, fixed global shift times.

## Requirements (each traced, each testable)

### R1 Branch [trace intent L24]
- Action: POST `dashboard/hub/branches.php` + `requireRole(['hub_admin'])` + CSRF.
- Logic Layer: `createBranch()` in `includes/functions.php` only, no SQL in presentation.
- Rules:
  - `hub_id` from `$_SESSION`, never GET/POST (INV-01).
  - Fields: `city, address, electricity_status, internet_strength, generator/solar, active=1`.
  - Card must show electricity, internet, generator/solar.
- Fail cases: missing city → validation error.

### R2 Seats [trace intent L24]
- Action: POST `dashboard/hub/seats.php` + `requireRole(['hub_admin'])` + CSRF.
- Logic Layer: `createSeat()` in `includes/functions.php`.
- Rules:
  - `hub_id` from `$_SESSION`, never GET/POST (INV-01).
  - `seat_code` UNIQUE per branch, `active=1`; COUNT active = occupancy denominator (INV-05).
- Fail cases: duplicate seat_code same branch → reject.

### R3 Shifts configurable per hub [trace intent L24 + 2026-10-09 decision]
- Action: POST `dashboard/hub/shifts.php` + `requireRole(['hub_admin'])` + CSRF.
- Logic Layer: `createShift()` / `updateShift()` in `includes/functions.php`.
- Rules:
  - `hub_id` from `$_SESSION`, never GET/POST (INV-01).
  - PDO prepared + `start_time < end_time`; no overlap same `branch_id` (`new.start < existing.end AND new.end > existing.start` → reject).
  - MVP requires >=2 active shifts per branch; `booking.shift_id` must belong to same `hub_id`.
- Fail cases: overlap → `تداخل في الورديات`; cross-hub shift_id in booking → reject; 1 shift only → `مطلوب ورديتان على الأقل`.

## Data / Contracts
- Tables: `branches`, `rooms`, `seats(branch_id,hub_id,seat_code,active)`, `shifts(hub_id,branch_id,name_ar,start_time,end_time,active)`.
- Invariants:
  - Shifts: >=2 active/branch, `start < end`, no overlap same branch.
  - Occupancy = COUNT(confirmed,attended)/COUNT(seats active=1) same branch+date+shift (INV-05).

## Security / UX
- Roles: hub_admin CRUD own hub only; staff/client → 403 on these pages.
- Output `htmlspecialchars()`, POST+CSRF, `password_hash` if auth.
- RTL: `lang="ar" dir="rtl"`, Bootstrap 5 RTL, mobile-first, <1.5MB, <3 steps for booking (INV-06).

## Acceptance (Given/When/Then, automated)
- Given 1 branch + 10 seats + 2 shifts 08:00-14:00,14:00-22:00 When saved Then dashboard counts match DB.
- Given overlapping 13:00-15:00 When create Then reject, COUNT unchanged.
- Given cross-hub shift_id When `createBooking()` Then reject.

## Conflicts / Deferred
- Exact shift times fixed vs configurable resolved 2026-10-09: configurable per hub.

## Verify
1. `php -l dashboard/hub/shifts.php`
2. Reload `http://localhost/Diwan/dashboard/hub/` — check RTL, size
3. SQL asserts for counts/status/error string
