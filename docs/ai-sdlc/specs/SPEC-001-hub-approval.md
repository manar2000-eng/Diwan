# SPEC-001 Hub Approval

Source: `intent/diwan.md` L24 `hub approval` + L13-17 owner/platform value
Status: approved
Related: SPEC-000-shared.md (INV-01..06)

## Scope
- IN: Register hub as pending, super approves to active with subscription so it can be searched and booked.
- OUT: Tiers, auto-approval, public hub editing.

## Requirements (each traced, each testable)

### R1 Register as pending [trace intent L24]
- Action: POST `public/register-hub.php` (name, city, phone, password) + CSRF.
- Logic Layer: `registerHub()` in `includes/functions.php` only, no SQL in presentation.
- Rules:
  - `hub_id` from session/DB, never GET/POST (INV-01).
  - PDO prepared, `password_hash`, `hubs.status='pending'`, no active `hub_subscriptions` row yet.
- Fail cases: duplicate phone → `مسجل مسبقاً`; missing city → validation error.

### R2 Super approve [trace intent L24]
- Action: `dashboard/super/*` + `requireRole(['super_admin'])` lists pending → Approve POST + CSRF.
- Logic Layer: `approveHub(hub_id)` sets `status='active'` + inserts `hub_subscriptions(hub_id, start_date=TODAY, end_date)`.
- Rules:
  - Subscription check `end_date >= TODAY AND status='active'` applies after (INV-03).
- Fail cases: non-super → 403.

### R3 Reject / suspend [trace intent L24]
- Action: `dashboard/super/*` + `requireRole(['super_admin'])`.
- Logic Layer: `setHubStatus()` → `rejected` / `suspended`.
- Rules: Both hidden from search + `createBooking()` rejects.
- Fail cases: booking suspended hub → `اشتراك المساحة منتهي` or `المساحة موقوفة`.

## Data / Contracts
- Tables: `hubs(status: pending,active,rejected,suspended)`, `hub_subscriptions(hub_id,start_date,end_date)`.
- Invariants:
  - Shifts: >=2 active/branch, `start < end`, no overlap same branch.
  - Occupancy = COUNT(confirmed,attended)/COUNT(seats active=1) same branch+date+shift (INV-05).

## Security / UX
- Roles: super_admin only for approve/reject/suspend; hub_admin/client cannot self-approve.
- Output `htmlspecialchars()`, POST+CSRF, `password_hash` if auth.
- RTL: `lang="ar" dir="rtl"`, Bootstrap 5 RTL, mobile-first, <1.5MB, <3 steps for booking (INV-06).

## Acceptance (Given/When/Then, automated)
- Given pending hub When search city Then 0 rows for that hub.
- Given super approves with valid subscription When search city Then 1 row.
- Given suspended hub When `createBooking()` Then reject, 0 new rows.
- Given POST `hub_id` spoof When approve Then ignored, uses DB id.

## Conflicts / Deferred
- Subscription price tiers (intent L33) deferred, single default end_date used.

## Verify
1. `php -l public/register-hub.php`
2. Reload `http://localhost/Diwan/public/search.php` — check RTL, size
3. SQL asserts for counts/status/error string
