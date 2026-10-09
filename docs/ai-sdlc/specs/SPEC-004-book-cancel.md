# SPEC-004 Book / Cancel by Mobile

Source: `intent/diwan.md` L9 `Books in under a minute from mobile` + L24 `book/cancel` + locked L29-L30
Status: approved
Related: SPEC-000-shared.md (INV-01..06)

## Scope
- IN: Mobile client books/cancels seat in <3 steps, cash on arrival, race-safe.
- OUT: Online payment, waiting list auto-promote.

## Requirements (each traced, each testable)

### R1 Book [trace intent L9 + L29-L30]
- Action: POST `public/booking.php` (seat_id, booking_date, shift_id) + `requireLogin()` + CSRF.
- Logic Layer: `createBooking()` in `includes/functions.php` only, no SQL in presentation.
- Rules:
  - `hub_id` from `$_SESSION`, never GET/POST (INV-01).
  - PDO prepared + transaction + `SELECT ... FOR UPDATE` (INV-04).
  - Subscription check `end_date >= TODAY AND status='active'` (INV-03); check seat belongs to hub; check `emergency_closures`; `qr_code=RANDOM`, `status='pending'`.
- Fail cases: expired → `اشتراك المساحة منتهي`; taken → `المقعد محجوز`; cross-hub → reject; past date → reject.

### R2 Cancel [trace intent L24]
- Action: POST `public/my-bookings.php` cancel + `requireLogin()` + CSRF, own booking only.
- Logic Layer: `cancelBooking()` → `pending/confirmed` → `cancelled`, frees seat.
- Rules: `attended` cannot cancel.
- Fail cases: others booking → 403; attended → reject.

### R3 Mobile flow [trace intent L9]
- Action: Search → hub → confirm, redirect `my-bookings.php?success=1`.
- Rules: <3 steps, RTL, no JS required (INV-06).

## Data / Contracts
- Tables: `bookings(client_id,hub_id,branch_id,seat_id,booking_date,shift_id,status,qr_code)`, `shifts(hub_id,branch_id,start_time,end_time)`, `emergency_closures`.
- Invariants:
  - Shifts: >=2 active/branch, `start < end`, no overlap same branch.
  - Occupancy = COUNT(confirmed,attended)/COUNT(seats active=1) same branch+date+shift (INV-05).

## Security / UX
- Roles: client books/cancels own only; hub_id never from POST.
- Output `htmlspecialchars()`, POST+CSRF, `password_hash` if auth.
- RTL: `lang="ar" dir="rtl"`, Bootstrap 5 RTL, mobile-first, <1.5MB, <3 steps for booking (INV-06).

## Acceptance (Given/When/Then, automated)
- Given free seat When 2 races same second Then COUNT pending/confirmed =1, one `المقعد محجوز`.
- Given expired subscription When book Then `اشتراك المساحة منتهي`, 0 rows.
- Given cancelled When re-book same seat/date/shift Then success.

## Conflicts / Deferred
- Waiting list manual vs auto (intent L35) deferred; cancel simply frees seat.

## Verify
1. `php -l public/booking.php`
2. Reload `http://localhost/Diwan/public/booking.php` — check RTL, size
3. SQL asserts for counts/status/error string
