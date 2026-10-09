# SPEC-006 Daily Report + Emergency Close

Source: `intent/diwan.md` L13 `bookings, occupancy, attendance, daily income` + L24 `daily report + emergency close` + locked L31
Status: approved
Related: SPEC-000-shared.md (INV-01..06)

## Scope
- IN: Hub_admin daily income + occupancy + emergency block for date.
- OUT: Cross-hub platform analytics (super only, separate).

## Requirements (each traced, each testable)

### R1 Daily report [trace intent L13 + L31]
- Action: GET `dashboard/hub/reports.php` + `requireRole(['hub_admin'])` (staff denied → 403).
- Logic Layer: `getHubIncome(hub_id session, date)` = SUM payments_cash; `getOccupancy(branch_id, date, shift_id)` per INV-05.
- Rules:
  - `hub_id` from `$_SESSION`, never GET/POST (INV-01).
  - Shows: total bookings, attended, occupancy %, cash total.
- Fail cases: staff GET → 403.

### R2 Emergency close [trace intent L24]
- Action: POST `dashboard/hub/emergency.php` + `requireRole(['hub_admin'])` + CSRF.
- Logic Layer: `addEmergencyClose()` inserts `emergency_closures(hub_id session, branch_id, close_date, reason)`; `createBooking()` blocks that date.
- Rules: Existing bookings stay + flagged; other dates unaffected.
- Fail cases: book closed date → `مغلق طارئ`.

## Data / Contracts
- Tables: `payments_cash`, `bookings`, `seats(active)`, `emergency_closures(hub_id,branch_id,close_date,reason)`, `shifts(hub_id,branch_id,start_time,end_time)`.
- Invariants:
  - Shifts: >=2 active/branch, `start < end`, no overlap same branch.
  - Occupancy = COUNT(confirmed,attended)/COUNT(seats active=1) same branch+date+shift (INV-05).

## Security / UX
- Roles: hub_admin only for report + close; staff 403 on finance.
- Output `htmlspecialchars()`, POST+CSRF.
- RTL: `lang="ar" dir="rtl"`, Bootstrap 5 RTL, mobile-first, <1.5MB (INV-06).

## Acceptance (Given/When/Then, automated)
- Given 10 seats, 4 attended ×10 When report date Then income 40, occupancy 0.4, pending excluded.
- Given emergency row for date D When book D Then reject `مغلق طارئ`; When book D+1 Then success.
- Given staff When GET reports Then 403.

## Conflicts / Deferred
- None; formula locked L31, no alternative allowed.

## Verify
1. `php -l dashboard/hub/reports.php`
2. Reload `http://localhost/Diwan/dashboard/hub/reports.php` — check RTL, size
3. SQL asserts for counts/status/error string
