# PLAN-001 Hub Approval

Spec: `docs/ai-sdlc/specs/SPEC-001-hub-approval.md` (approved)
Planner: `.opencode/agents/architect.md`
Depends on: PLAN-000 (auth, CSRF, `layout.php`, schema)
Blocks: PLAN-002 (hub needs an active session), PLAN-003/004 (subscription gate needs real subscriptions)

## Starting state
`PLAN-000` gives us `users`, `hubs`, `hub_subscriptions`, `requireRole()`, CSRF, `layout.php`, flash messages.
Nothing exists for hub registration or super_admin approval.

## Smallest change
Two presentation pages and three functions. **No separate hub-owner login** — reuse the single `users` table from PLAN-000 with `role='hub_admin'` and a `hub_id` column, so `login.php` is untouched. `hub_id` is written server-side at registration and thereafter read **only** from `$_SESSION['hub_id']`.

## Files to create

| File | Purpose |
|------|---------|
| `public/register-hub.php` | Public form: name, city, phone, password. POST + CSRF → `registerHub()` |
| `dashboard/super/hubs.php` | `requireRole(['super_admin'])`. Lists pending/active/rejected/suspended, Approve / Reject / Suspend buttons (POST + CSRF) |
| `dashboard/super/index.php` | Redirect to `hubs.php` so `/dashboard/super/` resolves |
| `includes/functions.php` *(modify)* | Add `registerHub()`, `approveHub()`, `setHubStatus()` |

## Functions to add

```
registerHub(name, city, phone, password)
  - validate: non-empty name/city/phone, password >= 8
  - phone UNIQUE in users -> duplicate => throw 'مسجل مسبقاً'
  - INSERT users(role='hub_admin', password=password_hash(...))
  - INSERT hubs(status='pending', name, city, phone, user_id)
  - UPDATE users SET hub_id = new hub id        <-- server-side only, never POST
  - NO hub_subscriptions row yet
```

```
approveHub(hubId)
  - UPDATE hubs SET status='active' WHERE id=? AND status='pending'
  - INSERT hub_subscriptions(hub_id, start_date=CURDATE(), end_date=CURDATE()+INTERVAL 1 MONTH)
  - both inside one transaction so a hub is never active without a subscription (INV-03)
```

```
setHubStatus(hubId, 'rejected'|'suspended')
  - UPDATE hubs SET status=?, and also expire the subscription row
    (end_date = CURDATE() - INTERVAL 1 DAY) so INV-03 fails closed
```

Approval duration is a single constant at the top of the function (30 days). Tier logic is deferred per SPEC-001, so no `saas_plans` table is created — that is the smallest change that satisfies R2.

## Steps
1. Add the 3 functions to `includes/functions.php`.
2. `public/register-hub.php` — form + POST handler + CSRF, no SQL.
3. `dashboard/super/hubs.php` + `index.php` — list + POST actions + CSRF.
4. Register a pending hub, then approve as super_admin; confirm `subscriptionOk()` from PLAN-000 flips to true.
5. Lint + run tests.

## Reuse
- `subscriptionOk()` (PLAN-000) — not reimplemented, just starts returning true after approval.
- `requireRole()`, `csrf_token()/csrf_check()`, `pageStart/pageEnd`, `redirect()`, flash messages — all from PLAN-000.
- No new package, no mailer, no queue.

## Out of scope
Subscription tiers and pricing, auto-approval, hub self-editing after registration, rejection reasons, email notifications, renewal reminders, plan upgrade/downgrade.

## Rollback note
```
rm public/register-hub.php
rm -r dashboard/super
git checkout includes/functions.php     # drops the 3 added functions
DELETE FROM hub_subscriptions; DELETE FROM hubs WHERE role-owning users exist;  -- optional
```
`login.php`, the schema, and PLAN-000 helpers are untouched, so PLAN-000 keeps working.

## Tests → acceptance mapping

| Spec acceptance | Verification | Result |
|---|---|---|
| Pending hub → 0 rows in search | Register a hub, then run the PLAN-003 `searchHubs()` query for its city → `COUNT = 0` | ☐ |
| Approve with valid subscription → 1 row in search | `approveHub()` then same query → `COUNT = 1` | ☐ |
| Suspended hub → `createBooking()` rejects, 0 new rows | Assert booking count unchanged after calling `createBooking()` (mechanics in PLAN-004) | ☐ |
| POST `hub_id` spoof on approve ignored | Submit `hub_id=<other hub>` in the approve POST; assert the row changed is the session/DB id, and `SELECT status FROM hubs WHERE id=<other>` is unchanged | ☐ |
| Duplicate phone → `مسجل مسبقاً` | Register twice with the same phone → exact string in the flash | ☐ |
| Missing city → validation error | Submit empty city → validation message, no `hubs` row | ☐ |
| Non-super → 403 | `hub_admin` session GET `/dashboard/super/hubs.php` → `403` | ☐ |
| Password hashed, never stored plain | `SELECT password FROM users` starts with `$2y$` | ☐ |
| Pending hub has no subscription row | `SELECT COUNT(*) FROM hub_subscriptions WHERE hub_id=<new>` → `0` | ☐ |
| Approve is atomic | Simulate failure between the two statements; assert `hubs.status` did not become `active` | ☐ |
| `php -l` | `php -l public/register-hub.php`, `php -l dashboard/super/hubs.php` | ☐ |
| Reload page, check RTL + size | Load `/dashboard/super/hubs.php`, confirm `lang="ar" dir="rtl"` and <1.5MB | ☐ |
