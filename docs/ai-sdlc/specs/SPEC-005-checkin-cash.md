# SPEC-005 Check-in + Cash Record

Source: `intent/diwan.md` L24 `check-in + cash record` + L9 `pays cash upon arrival`
Status: approved
Related: SPEC-000-shared.md (INV-01..06)

## Scope
- IN: Staff visual QR check-in + cash record, offline-friendly, no live API.
- OUT: Online payment, PalPay.

## Requirements (each traced, each testable)

### R1 Confirm attendance [trace intent L24]
- Action: POST `dashboard/hub/attendance.php` (qr_code) + `requireRole(['hub_admin','staff'])` + CSRF.
- Logic Layer: `confirmAttendance()` in `includes/functions.php` only, no SQL in presentation.
- Rules:
  - `hub_id` from `$_SESSION`, never GET/POST (INV-01).
  - PDO prepared; `pending/confirmed` → `attended`; pre-generated string, visual scan.
- Fail cases: invalid/duplicate QR → error; client role → 403.

### R2 Cash payment [trace intent L9 + L22 cash only]
- Action: Same attendance POST creates cash row.
- Logic Layer: `recordCash()` inserts `payments_cash(booking_id, hub_id from session, amount, received_by, received_at)`; amount from `getPrice()` server-side, not POST.
- Rules: No online payment.
- Fail cases: tampered POST amount ignored; duplicate QR → no duplicate payment.

## Data / Contracts
- Tables: `bookings(qr_code,status)`, `payments_cash(booking_id,hub_id,amount,received_by,received_at)`, `shifts(hub_id,branch_id,start_time,end_time)`.
- Invariants:
  - Shifts: >=2 active/branch, `start < end`, no overlap same branch.
  - Occupancy = COUNT(confirmed,attended)/COUNT(seats active=1) same branch+date+shift (INV-05).

## Security / UX
- Roles: hub_admin/staff only; staff cannot access finance reports (SPEC-006).
- Output `htmlspecialchars()`, POST+CSRF.
- RTL: `lang="ar" dir="rtl"`, Bootstrap 5 RTL, mobile-first, <1.5MB (INV-06).

## Acceptance (Given/When/Then, automated)
- Given confirmed booking When staff scans valid QR Then status attended + 1 payments_cash row with server price.
- Given same QR twice When rescan Then error, COUNT payments =1.
- Given client role When POST attendance Then 403.

## Conflicts / Deferred
- None; cash-only locked per L22.

## Verify
1. `php -l dashboard/hub/attendance.php`
2. Reload `http://localhost/Diwan/dashboard/hub/` — check RTL, size
3. SQL asserts for counts/status/error string
