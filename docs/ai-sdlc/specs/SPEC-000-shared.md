# SPEC-000 Shared Invariants — Diwan MVP

Source: `intent/diwan.md` L22 `PHP 8 native + MySQL + Bootstrap 5 RTL` + L26 golden rule + locked L29-L31 (2026-10-08)
Status: approved
Related: Applies to SPEC-001..006

## Scope
- IN: Single source of truth for multi-tenancy, auth, subscription gate, double-booking, occupancy, RTL/perf. If feature does not serve daily seat booking, it is OUT.
- OUT: Online payment, mobile app, map, PalPay, tiers, review moderation, waiting list.

## Requirements (each traced, each testable)

### R1 Multi-tenancy [trace AGENTS.md SaaS + intent L26]
- Action: All `dashboard/hub/*` + `public/booking.php` use `$_SESSION['hub_id']`.
- Logic Layer: Every tenant query in `includes/functions.php` includes `WHERE hub_id = ?`.
- Rules:
  - `hub_id` from session only, never GET/POST.
  - Only `super_admin` may query across hubs.
- Fail cases: `?hub_id=other` ignored.

### R2 Auth / Roles [trace AGENTS.md Roles]
- Action: `requireRole([])` at top of every `dashboard/*` file. Session start only in `includes/auth.php`.
- Logic Layer: `requireLogin()`, `requireRole()`, `password_hash` + `password_verify`.
- Rules: PDO prepared only, `htmlspecialchars()` on output, POST + CSRF for state changes.
- Fail cases: unauthenticated → redirect login; wrong role → 403.

### R3 Subscription gate [trace intent L29]
- Action: Search listing + `createBooking()` both check gate.
- Logic Layer: `hub_subscriptions.end_date >= TODAY AND hubs.status='active'`.
- Rules: Expired = hidden + reject.
- Fail cases: exact string `اشتراك المساحة منتهي`.

### R4 Double-booking [trace intent L30]
- Action: `createBooking()` transaction only.
- Logic Layer: `BEGIN; SELECT ... FOR UPDATE WHERE seat_id=? AND booking_date=? AND shift_id=? AND status IN ('pending','confirmed'); INSERT if empty; COMMIT`.
- Rules: No partial UNIQUE in MySQL.
- Fail cases: exact string `المقعد محجوز`.

### R5 Occupancy [trace intent L31]
- Action: `getOccupancy(branch_id, date, shift_id)`.
- Logic Layer: `COUNT(bookings status IN ('confirmed','attended')) / COUNT(seats WHERE active=1)` same branch+date+shift. No other formula.
- Fail cases: `pending`/`cancelled` excluded from numerator.

### R6 Frontend / Perf [trace intent L22]
- Action: All `public/*.php`, `dashboard/*/*.php` with `lang="ar" dir="rtl"`.
- Rules: Bootstrap 5 RTL CDN, custom CSS only `assets/css/style.css`, mobile-first, booking <3 steps, page <1.5MB, lazy images, no AJAX required.
- Fail cases: missing RTL attrs, oversize page.

## Data / Contracts
- Tables: `hubs`, `hub_subscriptions`, `branches`, `rooms`, `seats`, `shifts(hub_id,branch_id,start_time,end_time)`, `bookings`, `payments_cash`, `emergency_closures`.
- Invariants:
  - Shifts: >=2 active/branch, `start < end`, no overlap same branch.
  - Occupancy per R5.

## Security / UX
- Roles: super_admin cross-hub only; hub_admin owns data; staff no finance; client books own only.
- Output `htmlspecialchars()`, POST+CSRF, `password_hash` if auth.
- RTL: `lang="ar" dir="rtl"`, Bootstrap 5 RTL, mobile-first, <1.5MB.

## Acceptance (Given/When/Then, automated)
- Given hubA session When GET `?hub_id=hubB` Then sees hubA only.
- Given unauthenticated When GET dashboard Then redirect login; staff GET finance Then 403.
- Given expired subscription When search Then hub hidden; When `createBooking()` Then `اشتراك المساحة منتهي`.
- Given 2 concurrent INSERTs same seat/date/shift When committed Then COUNT pending/confirmed =1.
- Given 10 seats, 3 confirmed +1 attended +1 pending When `getOccupancy()` Then 0.4.

## Conflicts / Deferred
- Tiers, review moderation, waiting list (intent L33-37) deferred per golden rule L26.

## Verify
1. `php -l includes/functions.php`
2. Reload `http://localhost/Diwan/public/search.php` — check RTL, size
3. SQL asserts for counts/status/error string
