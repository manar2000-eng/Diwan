# PLAN-004 Book / Cancel by Mobile

Spec: `docs/ai-sdlc/specs/SPEC-004-book-cancel.md` (approved)
Planner: `.opencode/agents/architect.md`
Depends on: PLAN-000 (auth/CSRF/transaction-capable `db()`), PLAN-001 (`subscriptionOk()`), PLAN-002 (seats + shifts), PLAN-003 (search → hub entry point)

## Starting state
A client can search and see availability but has no way to hold a seat, and cannot sign in as a client.
`bookings` table exists and is empty.

## Smallest change
Two pages and two functions. The whole race-safety story lives in one transaction inside `createBooking()` — no lock table, no queue, no `SELECT ... FOR UPDATE` retry loop, no seat-hold expiry job (none of that is possible on shared hosting per `AGENTS.md`).
`qr_code` is a 12-char random string generated in PHP, **not** an image — scanning is visual or phone-camera, which is what SPEC-005 needs and it costs nothing here.

Booking flow stays at **2 steps**: search → seat picker → confirm POST. `<3 steps` is a hard requirement (INV-06) and a seat-picker page is what keeps it there.

## Files to create

| File | Purpose |
|------|---------|
| `public/hub.php` | Seat picker for one branch on a date/shift: shows free seats, POST → `booking.php` |
| `public/booking.php` | `requireLogin()` + CSRF. The single confirm POST → `createBooking()` |
| `public/my-bookings.php` | List own bookings with QR string + Cancel button (POST + CSRF) |
| `includes/functions.php` *(modify)* | Add `getFreeSeats()`, `createBooking()`, `cancelBooking()`, `listMyBookings()`, `generateQrCode()`, `getBookableBranch()`, `listBranchShifts()`, `formatDateLong()` |
| `public/search.php` *(modify)* | The disabled book button became a real link to `hub.php` |

## Functions to add

```
getFreeSeats(branchId, date, shiftId)
  SELECT s.* FROM seats s
  WHERE s.branch_id=? AND s.active=1
    AND s.id NOT IN (
      SELECT seat_id FROM bookings
      WHERE branch_id=? AND booking_date=? AND shift_id=?
        AND status IN ('pending','confirmed'))
  ORDER BY s.seat_code
```
Reuses the exact availability numerator from PLAN-003 so the picker and the card can never disagree.

```
createBooking(clientId, seatId, date, shiftId)
  BEGIN
    1. seat:  SELECT id, hub_id, branch_id, active FROM seats WHERE id=?
                       -> missing or active=0 => 'المقعد غير موجود'
              shift: SELECT id, branch_id, hub_id, active FROM shifts WHERE id=?
                       -> missing or active=0 => 'الوردية غير متاحة'
    2. INV-01:  shift.hub_id and shift.branch_id must match the seat's
                 -> cross-hub => 'المقعد غير متاح'
    3. hubs.status='active' else => 'المساحة موقوفة'
    4. INV-03:  subscriptionOk(hubId) else => 'اشتراك المساحة منتهي'
    5. date validity + date >= CURDATE() is checked before BEGIN
    6. SPEC-006: SELECT 1 FROM emergency_closures
                 WHERE branch_id=? AND close_date=?  -> 'مغلق طارئ'
    7. INV-04:  SELECT id FROM bookings
                WHERE seat_id=? AND booking_date=? AND shift_id=?
                  AND status IN ('pending','confirmed')
                FOR UPDATE              <-- serialises the race
                -> found => 'المقعد محجوز'
    8. INSERT bookings(client_id, hub_id, branch_id, seat_id, booking_date,
                      shift_id, status='pending', qr_code=random12())
  COMMIT
  any throw => ROLLBACK, return the Arabic string
```
`hub_id` for the INSERT comes from the **seat row read in step 1**, not from POST. The client is a `client` role and has no `hub_id` in session, so this step doubles as the tenant guard.

**Two ordering changes from the plan text**, both to avoid a misleading message:
- The shift lookup moved up beside the seat lookup. A missing or foreign shift is a client-side mistake worth naming, and running it after the hub-state checks would let `المساحة موقوفة` mask it.
- `hubs.status` is now checked *before* `subscriptionOk()`. `subscriptionOk()` also requires `status='active'`, so the plan's order reported `اشتراك المساحة منتهي` for a suspended hub that has paid up. A suspended hub now says `المساحة موقوفة`.

A `PDOException` with code `23000` (the `UNIQUE(qr_code)` collision) is reported as `المقعد محجوز` instead of surfacing a driver error.
`FOR UPDATE` on a non-existent row is a gap lock in InnoDB only when a suitable index exists — the PLAN-000 `KEY (seat_id, booking_date, shift_id, status)` guarantees it. That index is what makes this correct, so it must not be dropped.

```
cancelBooking(bookingId, clientId)
  SELECT ... FROM bookings WHERE id=? AND client_id=?   -- own booking only
  status='attended'            => 'لا يمكن إلغاء حضور مؤكد'
  status='cancelled'           => no-op
  else pending|confirmed       => UPDATE status='cancelled'
  frees the seat automatically because getFreeSeats excludes only pending/confirmed
```

```
listMyBookings(clientId)  -- JOIN hubs/branches/shifts for readable rows
```

## Steps
1. Add the 4 functions to `includes/functions.php`. Confirm `db()` returns the connection used for `beginTransaction()`.
2. `public/hub.php` — free-seat grid from `getFreeSeats()`, one `<form>` per seat or one form + select (pick the single form; simpler DOM).
3. `public/booking.php` — POST only, `requireLogin()`, CSRF, then `redirect('/public/my-bookings.php?success=1')`.
4. `public/my-bookings.php` — list + QR + cancel.
5. Full happy path, then the race test (step 8).
6. Lint + run tests.

## Reuse
- `requireLogin()`, `csrf_token()/csrf_check()`, `redirect()`, flash, `e()`, `pageStart/pageEnd` — PLAN-000.
- `subscriptionOk()` — PLAN-000; already spec'd to be called from exactly two places, this is the second.
- Seat/shift validation and shift ownership — already enforced on write in PLAN-002, re-verified on read here because booking must never trust a stale page.
- Availability numerator — identical to PLAN-003 `getAvailability()`.

## Out of scope
Online payment, waiting list / auto-promote, recurring bookings, monthly passes, seat cancellation deadlines, refunds, booking notes, client cancelling on behalf of others, notifications.

## Rollback note
```
rm public/hub.php public/booking.php public/my-bookings.php
git checkout includes/functions.php     # drops the 4 added functions
DELETE FROM bookings;                   -- optional
```
Search still works (PLAN-003 untouched); cards just lose the book button.

## Tests → acceptance mapping

| Spec acceptance | Verification | Result |
|---|---|---|
| Free seat, 2 races in the same second → COUNT pending/confirmed = 1, one gets `المقعد محجوز` | Two parallel POSTs of the same `(seat_id, date, shift_id)` (curl in parallel, or two PHP CLI callers). Assert `SELECT COUNT(*) FROM bookings WHERE status IN ('pending','confirmed')` = 1 and that exactly one response shows `المقعد محجوز` | ✅ two real concurrent `proc_open` PHP processes, each with its own PDO: exactly 1 row, exactly one `OK:<id>` and one `ERR:المقعد محجوز`; repeated on 6 more date/seat pairs, 6/6 held |
| Expired subscription → `اشتراك المساحة منتهي`, 0 rows | Set `end_date < CURDATE()`, book → exact string, `COUNT(bookings)` unchanged | ✅ exact string, count unchanged; the picker and the search card refuse the hub too |
| Cancelled → re-book same seat/date/shift succeeds | Cancel then re-book → success, 1 pending row | ✅ cancel → `cancelled`, re-book → 302 `success=1` with 1 pending row for that slot |
| Past date rejected | `date` = yesterday → `تاريخ غير صالح` | ✅ exact string; `2026-02-31` rejected as well |
| Cross-hub `shift_id` rejected | HubA shift with hubB seat → `المقعد غير متاح`, 0 rows | ✅ exact string, count unchanged; the picker also rejects a foreign `shift_id` |
| Emergency-closed date → `مغلق طارئ` | Emergency row for D, book D → reject; book D+1 → success | ✅ exact string on D, count unchanged; D books fine |
| Suspended hub → `المساحة موقوفة` | Suspend the hub, book → exact string | ✅ exact string, 0 new rows (this is why the status check precedes `subscriptionOk()`) |
| `hub_id` never from POST | POST `hub_id=<other hub>`; assert the inserted `bookings.hub_id` equals the seat's real hub | ✅ spoofed `hub_id` and `client_id` both ignored; row landed in the seat's hub with the session client |
| Unauthenticated POST → redirect login | POST `booking.php` with no session → `302` to login, 0 rows | ✅ 302 to `/Diwan/public/login.php`, 0 rows |
| Missing/invalid CSRF rejected | POST without token → rejected, 0 rows | ✅ `انتهت صلاحية الجلسة`, 0 rows |
| Cancel other's booking → 403 | Client B cancels client A's booking id → `403`, status unchanged | ✅ `cancelBooking()` matches nothing, page answers `403` + flash `الحجز غير موجود`, booking stays `pending`; client B's list shows only client B's rows |
| `attended` cannot be cancelled | Mark a booking `attended` (via SPEC-005 later, or SQL), cancel → `لا يمكن إلغاء حضور مؤكد` | ✅ exact string, status stays `attended`; cancelling an already-cancelled row is a no-op |
| `qr_code` unique and pre-generated | Assert `SELECT COUNT(DISTINCT qr_code) = COUNT(*)` over several bookings; length 12, no image needed | ✅ 15 generated codes all distinct, 12 chars, charset excludes I/O/0/1; no image, no chart |
| Flow <3 steps, no JS required | Search → hub → confirm → redirect. Disable JS and repeat | ✅ search → picker → confirm → redirect; no `<script>`, `fetch(` or `onclick` in the three pages |
| Transaction rolls back on error | Force a throw after step 8; assert no orphan row | ✅ a temporary `BEFORE INSERT` trigger raising `45000` left 0 orphan rows and no open transaction; booking worked again once the trigger was dropped |
| `php -l` | `php -l public/booking.php`, `php -l public/my-bookings.php`, `php -l public/hub.php` | ✅ 18 PHP files, 0 errors |

Also verified: the booked seat disappears from the picker (10 → 9 options); the same seat on the *other* shift stays bookable; the picker refuses a mismatched `hub_id`/`branch_id` pair, a past date, a foreign `shift_id`, and zero ids; `hub.php` and `my-bookings.php` stay under 1.5MB and RTL; all 14 PHP files in `public/` and `dashboard/` are free of SQL and PDO calls.

Total: 108 checks for PLAN-004. PLAN-000 (34), PLAN-001 (65), PLAN-002 (95), PLAN-003 (87) and the rollback probe (11) still pass.

Seeded hub for PLAN-005: `مساحة النخيل` / Gaza, 10 seats `A-01..A-10`, shifts 08:00-14:00 and 14:00-22:00, hub_admin `0597000001`, staff `0597000002`, client `0597000003`, super_admin `0599000000`, all password `Diwan@2026`.
