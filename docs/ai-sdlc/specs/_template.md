# SPEC-### <Title>

Source: `intent/<name>.md` L<xx> `<quote>` + locked corrections <date> if any
Status: draft | approved
Related: SPEC-000-shared.md (INV-01..06)

## Scope
- IN: <one sentence, must serve daily seat booking per golden rule>
- OUT: <explicit non-goals>

## Requirements (each traced, each testable)

### R1 <name> [trace intent Lxx]
- Action: <POST `public/....php` / `dashboard/...` + `requireLogin()` / `requireRole([...])` + CSRF>
- Logic Layer: <`functionName()` in `includes/functions.php` only, no SQL in presentation>
- Rules:
  - `hub_id` from `$_SESSION`, never GET/POST (INV-01)
  - PDO prepared + transaction + `SELECT ... FOR UPDATE` if booking (INV-04)
  - Subscription check `end_date >= TODAY AND status='active'` if listing/booking (INV-03)
- Fail cases: <exact Arabic error strings>

### R2 <name> [trace intent Lxx]
- <repeat structure>

## Data / Contracts
- Tables: <`branches`, `seats`, `shifts(hub_id,branch_id,start_time,end_time)`, `bookings`, `payments_cash`, `emergency_closures`>
- Invariants:
  - Shifts: >=2 active/branch, `start < end`, no overlap same branch
  - Occupancy = COUNT(confirmed,attended)/COUNT(seats active=1) same branch+date+shift (INV-05)

## Security / UX
- Roles: <super_admin / hub_admin / staff / client + 403 cases>
- Output `htmlspecialchars()`, POST+CSRF, `password_hash` if auth
- RTL: `lang="ar" dir="rtl"`, Bootstrap 5 RTL, mobile-first, <1.5MB, <3 steps for booking (INV-06)

## Acceptance (Given/When/Then, automated)
- Given <seed DB state> When <action> Then <SQL count / error string / redirect>
- Given <race/expired/overlap> When <action> Then <assert 1 row / reject / hidden>

## Conflicts / Deferred
- <open question from intent, left unresolved, not in MVP>

## Verify
1. `php -l <file>`
2. Reload `http://localhost/Diwan/<page>` — check RTL, size
3. SQL asserts for counts/status/error string
