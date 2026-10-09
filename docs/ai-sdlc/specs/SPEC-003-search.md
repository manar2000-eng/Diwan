# SPEC-003 Search by City / Date / Shift

Source: `intent/diwan.md` L8 `available seats today? electricity and internet working? price?` + L24 `search by city/date/shift`
Status: approved
Related: SPEC-000-shared.md (INV-01..06)

## Scope
- IN: Client filters by city/date/shift, sees availability + electricity/internet + price before trip.
- OUT: Map, waiting list, reviews display.

## Requirements (each traced, each testable)

### R1 Filters [trace intent L24]
- Action: GET `public/search.php?city=&date=&shift_id=`, default `date=TODAY`.
- Logic Layer: `searchHubs()` in `includes/functions.php` only, no SQL in presentation.
- Rules:
  - Validate `date >= TODAY` else 400; `shift_id` must exist and belong to hub.
  - Subscription check `end_date >= TODAY AND status='active'` (INV-03); exclude `emergency_closures` date (SPEC-006).
  - `hub_id` from `$_SESSION`, never GET/POST where applicable (INV-01).
- Fail cases: past date → validation error; expired/pending/emergency → excluded.

### R2 Card content [trace intent L8]
- Action: Render hub card in `public/search.php`.
- Logic Layer: `getAvailability()` = total active − booked (INV-05).
- Rules:
  - Show: available seats, electricity_status, internet_strength, price/hour, branch city.
- Fail cases: 0 available → `مكتمل` but still listed unless emergency.

### R3 Perf / RTL [trace intent L22]
- Action: Mobile-first render.
- Rules: `lang="ar" dir="rtl"`, Bootstrap 5 RTL, <1.5MB, lazy images, no AJAX required (INV-06).
- Fail cases: oversize, missing RTL.

## Data / Contracts
- Tables: `hubs`, `hub_subscriptions`, `branches`, `seats`, `shifts(hub_id,branch_id,start_time,end_time)`, `bookings`, `emergency_closures`.
- Invariants:
  - Shifts: >=2 active/branch, `start < end`, no overlap same branch.
  - Occupancy = COUNT(confirmed,attended)/COUNT(seats active=1) same branch+date+shift (INV-05).

## Security / UX
- Roles: public search, no login required; no cross-hub leak (INV-01).
- Output `htmlspecialchars()`, POST+CSRF where state-changing.
- RTL: `lang="ar" dir="rtl"`, Bootstrap 5 RTL, mobile-first, <1.5MB, <3 steps for booking (INV-06).

## Acceptance (Given/When/Then, automated)
- Given hubA active/valid + hubB expired When search city Then hubA only.
- Given 10 seats, 4 booked confirmed/attended When search Then card shows `6 متاح`.
- Given date < TODAY When search Then validation error.
- Given emergency-closed date When search Then `مغلق طارئ`.

## Conflicts / Deferred
- Waiting list (intent L35) deferred; full shows `مكتمل`, no auto-promote.

## Verify
1. `php -l public/search.php`
2. Reload `http://localhost/Diwan/public/search.php` — check RTL, size
3. SQL asserts for counts/status/error string
