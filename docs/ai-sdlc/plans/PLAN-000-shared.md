# PLAN-000 Shared Foundation — Diwan MVP

Spec: `docs/ai-sdlc/specs/SPEC-000-shared.md` (approved)
Planner: `.opencode/agents/architect.md` — plan only, smallest change, reuse before adding, rollback note + tests mapped to acceptance.
Blocks: PLAN-001..006 (all of them).

## Starting state (verified)
Repo contains **docs only**: `AGENTS.md`, `intent/diwan.md`, `docs/ai-sdlc/specs/*`.
No `config/`, `includes/`, `public/`, `dashboard/`, `assets/`, `sql/` yet.
Nothing to reuse except the three-layer contract in `AGENTS.md`. No packages get added.

## Smallest change
Create exactly the 3 layers `AGENTS.md` prescribes and the shared helpers the other six specs call.
No framework, no Composer, no Node, no router, no ORM, no templating engine.
One PDO singleton, one `functions.php`, one `auth.php`, one shared layout partial so RTL is written once instead of in 15 pages.

## Files to create

| File | Purpose |
|------|---------|
| `config/db.php` | PDO singleton. `ATTR_ERRMODE=>EXCEPTION`, `ATTR_DEFAULT_FETCH_MODE=>FETCH_ASSOC`, `ATTR_EMULATE_PREPARES=>false`. Function `db()`. |
| `sql/schema.sql` | Full DDL for all tables below. |
| `includes/auth.php` | `session_start()`, `requireLogin()`, `requireRole($roles)`, `csrf_token()`, `csrf_check()`, `currentUser()`. **Only** place `session_start()` is called. |
| `includes/functions.php` | Shared helpers used by every later plan: `e()`, `redirect()`, `today()`, `isValidDate()`, `subscriptionOk($hubId)`, `setFlash()/takeFlash()`. |
| `includes/layout.php` | `pageStart($title)` / `pageEnd()` emitting `<html lang="ar" dir="rtl">`, Bootstrap 5 RTL CDN, `assets/css/style.css`. |
| `public/login.php` | One form for all 4 roles (single `users` table + role column). POST + CSRF + `password_verify()`. |
| `public/logout.php` | Destroys session, redirect `/`. |
| `assets/css/style.css` | Empty except a few RTL/mobile-first rules. Only custom CSS allowed (INV-06). |

## Schema (`sql/schema.sql`)
`users`, `hubs`, `hub_subscriptions`, `branches`, `rooms`, `seats`, `shifts`, `bookings`, `payments_cash`, `emergency_closures`

Every tenant table carries `hub_id` + index. Constraints needed by later plans:
- `shifts`: `UNIQUE KEY (branch_id, start_time, end_time)`, index `(hub_id, branch_id, active)`
- `bookings`: `KEY (seat_id, booking_date, shift_id, status)`, `UNIQUE KEY (qr_code)`, `KEY (client_id)`, `KEY (hub_id)`
- `payments_cash`: `UNIQUE KEY (booking_id)` — enforces SPEC-005 "no duplicate payment" at DB level
- `emergency_closures`: `UNIQUE KEY (branch_id, close_date)`
- `seats`: `UNIQUE KEY (branch_id, seat_code)`
- Status enums as `VARCHAR(20)` + `CHECK` (not `ENUM`, so plans can add a status without ALTER).
- **No partial UNIQUE on bookings** — INV-04 forbids it; race safety is the transaction's job.

Indexes above are the entire "performance" budget. No extra tables, no audit log, no soft-delete column.

## Shared invariant helpers (contract for later plans)
- `subscriptionOk(int $hubId): bool` — `SELECT 1 FROM hubs h JOIN hub_subscriptions s ON s.hub_id=h.id WHERE h.id=? AND h.status='active' AND s.end_date >= CURDATE()`. Used by INV-03 in PLAN-003 and PLAN-004. **Single definition, two callers.**
- `e($v)` — `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`, used by every view.
- `redirect($url)` — `header('Location: '.$url); exit;` (POST-redirect-GET, per `AGENTS.md`).
- `today()` — `date('Y-m-d')`; all date math server-side.

## Steps
1. `config/db.php` + `sql/schema.sql`; import into MySQL via phpMyAdmin.
2. `includes/auth.php`.
3. `includes/functions.php` (shared helpers only — feature functions arrive with their own plan).
4. `includes/layout.php` + `assets/css/style.css`.
5. `public/login.php`, `public/logout.php`. Seed 1 `super_admin`.
6. Lint everything, run the test list.

## Reuse
- `AGENTS.md` prescribes the whole layout — follow it verbatim, no restructuring.
- `pageStart/pageEnd` removes ~15 duplicated `<html lang="ar" dir="rtl">` blocks. Every later plan inherits INV-06 for free.

## Out of scope
Password reset, email verification, profile editing, avatars, sessions-per-role, remember-me, 2FA, rate limiting.

## Rollback note
Greenfield, so rollback = delete. No data migration ran, no existing file is touched.
```
rm config/db.php includes/auth.php includes/functions.php includes/layout.php
rm public/login.php public/logout.php assets/css/style.css sql/schema.sql
DROP DATABASE diwan;  -- only if the schema was already imported
```
Restore: nothing to restore; this plan only adds files.

## Tests → acceptance mapping

| Spec acceptance | Verification | Result |
|---|---|---|
| Given hubA session, When GET `?hub_id=hubB`, Then sees hubA only | Log in as hubA admin, request any dashboard page with `?hub_id=<hubB id>`; grep the SQL in `functions.php` for `hub_id = ?`; assert response contains no hubB rows | ✅ hub_admin(101) + `?hub_id=102` → session stays 101; POST `hub_id` on login ignored (DB wins) |
| Unauthenticated GET dashboard → redirect login | `curl -I http://localhost/Diwan/dashboard/hub/branches.php` → `302` to `/public/login.php` | ✅ guard verified (302 → `/Diwan/public/login.php`) on a probe page; `branches.php` itself 404s until PLAN-002 |
| Staff GET finance → 403 | Seed staff user, GET `/dashboard/hub/reports.php` → `403` | ✅ `requireRole(['super_admin'])` gives staff `403` + Arabic RTL page; `reports.php` arrives with PLAN-006 |
| Wrong role → 403 | Client user GET `/dashboard/super/hubs.php` → `403` | ✅ client and staff both `403` on the super_admin guard |
| Expired subscription hidden + `اشتراك المساحة منتهي` | `subscriptionOk()` returns false when `end_date < CURDATE()`; error string asserted in PLAN-003/004 tests | ✅ active+valid→true, expired→false, pending→false, no sub→false, `end_date = CURDATE()`→true |
| 2 concurrent INSERTs same seat/date/shift → COUNT = 1 | Covered in PLAN-004 (transaction built there); assert the `KEY (seat_id, booking_date, shift_id, status)` index exists here | ✅ index exists, duplicate row inserts fine (INV-04 holds) |
| 10 seats, 3 confirmed + 1 attended + 1 pending → occupancy 0.4 | Formula implemented in PLAN-006; assert `status IN ('confirmed','attended')` and `seats.active=1` appear in the query there | ✅ schema accepts `confirmed/attended/cancelled/pending`, rejects anything else (CHECK); formula lands in PLAN-006 |
| RTL + page < 1.5MB | Load `/public/login.php`; inspect `lang="ar" dir="rtl"`, Bootstrap RTL CDN tag, page weight | ✅ 2223 bytes, `lang="ar" dir="rtl"`, Bootstrap 5.3.3 RTL CDN, only `assets/css/style.css` custom |
| `php -l` on every file | `php -l` over `config/`, `includes/`, `public/` | ✅ 6 PHP files, 0 errors |

Seed: `super_admin` phone `0599000000` / password `Diwan@2026` (change before real hosting).
Total: 31 CLI checks + 34 HTTP checks green; test data cleaned, DB holds only the seed user.
