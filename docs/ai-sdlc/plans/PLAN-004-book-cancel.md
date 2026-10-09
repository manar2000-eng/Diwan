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
| `includes/functions.php` *(modify)* | Add `getFreeSeats()`, `createBooking()`, `cancelBooking()`, `listMyBookings()` |

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
    1. seat + branch:  SELECT seat_id, hub_id, branch_id FROM seats WHERE id=?
                       -> not found => 'المقعد غير موجود'
    2. INV-01:  seat.hub_id must equal $seat.hub_id; shift must belong to same hub
                -> cross-hub => 'المقعد غير متاح'
    3. INV-03:  subscriptionOk(hubId) else => 'اشتراك المساحة منتهي'
    4. hubs.status='active' else => 'المساحة موقوفة'
    5. date >= CURDATE() else => 'تاريخ غير صالح'
    6. shift.active=1 else => 'الوردية غير متاحة'
    7. SPEC-006: SELECT 1 FROM emergency_closures
                 WHERE branch_id=? AND close_date=?  -> 'مغلق طارئ'
    8. INV-04:  SELECT id FROM bookings
                WHERE seat_id=? AND booking_date=? AND shift_id=?
                  AND status IN ('pending','confirmed')
                FOR UPDATE              <-- serialises the race
                -> found => 'المقعد محجوز'
    9. INSERT bookings(client_id, hub_id, branch_id, seat_id, booking_date,
                      shift_id, status='pending', qr_code=random12())
  COMMIT
  any throw => ROLLBACK, return the Arabic string
```
`hub_id` for the INSERT comes from the **seat row read in step 1**, not from POST. The client is a `client` role and has no `hub_id` in session, so this step doubles as the tenant guard.
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
| Free seat, 2 races in the same second → COUNT pending/confirmed = 1, one gets `المقعد محجوز` | Two parallel POSTs of the same `(seat_id, date, shift_id)` (curl in parallel, or two PHP CLI callers). Assert `SELECT COUNT(*) FROM bookings WHERE status IN ('pending','confirmed')` = 1 and that exactly one response shows `المقعد محجوز` | ☐ |
| Expired subscription → `اشتراك المساحة منتهي`, 0 rows | Set `end_date < CURDATE()`, book → exact string, `COUNT(bookings)` unchanged | ☐ |
| Cancelled → re-book same seat/date/shift succeeds | Cancel then re-book → success, 1 pending row | ☐ |
| Past date rejected | `date` = yesterday → `تاريخ غير صالح` | ☐ |
| Cross-hub `shift_id` rejected | HubA shift with hubB seat → `المقعد غير متاح`, 0 rows | ☐ |
| Emergency-closed date → `مغلق طارئ` | Emergency row for D, book D → reject; book D+1 → success | ☐ |
| Suspended hub → `المساحة موقوفة` | Suspend the hub, book → exact string | ☐ |
| `hub_id` never from POST | POST `hub_id=<other hub>`; assert the inserted `bookings.hub_id` equals the seat's real hub | ☐ |
| Unauthenticated POST → redirect login | POST `booking.php` with no session → `302` to login, 0 rows | ☐ |
| Missing/invalid CSRF rejected | POST without token → rejected, 0 rows | ☐ |
| Cancel other's booking → 403 | Client B cancels client A's booking id → `403`, status unchanged | ☐ |
| `attended` cannot be cancelled | Mark a booking `attended` (via SPEC-005 later, or SQL), cancel → `لا يمكن إلغاء حضور مؤكد` | ☐ |
| `qr_code` unique and pre-generated | Assert `SELECT COUNT(DISTINCT qr_code) = COUNT(*)` over several bookings; length 12, no image needed | ☐ |
| Flow <3 steps, no JS required | Search → hub → confirm → redirect. Disable JS and repeat | ☐ |
| Transaction rolls back on error | Force a throw after step 8; assert no orphan row | ☐ |
| `php -l` | `php -l public/booking.php`, `php -l public/my-bookings.php`, `php -l public/hub.php` | ☐ |
