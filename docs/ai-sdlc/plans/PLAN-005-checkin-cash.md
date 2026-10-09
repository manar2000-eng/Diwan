# PLAN-005 Check-in + Cash Record

Spec: `docs/ai-sdlc/specs/SPEC-005-checkin-cash.md` (approved)
Planner: `.opencode/agents/architect.md`
Depends on: PLAN-000 (auth/CSRF), PLAN-002 (branch price), PLAN-004 (bookings + `qr_code`)

## Starting state
Bookings exist with a pre-generated `qr_code` string. There is no hub-side page for "today", and no `payments_cash` writes anywhere.
Staff and hub_admin both need this page.

## Smallest change
One page and three functions. Two deliberate omissions make it small:
- **No QR library.** SPEC-005 says "visual scan" — staff read the 12-char string off the client's phone, or the phone camera reads the plain text. A QR generator would add a dependency for zero MVP value.
- **No camera/scanner JS.** Same reason. If a phone camera reads it, that is the OS, not our code.

Check-in and cash record are **one POST → one function**, because they always happen together at the desk. Two separate screens would be two chances for staff to forget the cash half.

## Files to create

| File | Purpose |
|------|---------|
| `dashboard/hub/attendance.php` | `requireRole(['hub_admin','staff'])`. Today's bookings list + "check in" POST per row + typed-QR form |
| `includes/functions.php` *(modify)* | Add `listTodaysBookings()`, `confirmAttendance()`, `recordCash()`, `getPrice()`, `confirmAndPay()`, and a retry wrapper around `createBooking()` |

## Functions to add

```
listTodaysBookings(hubId, date = today)
  SELECT b.id, b.qr_code, b.status, s.seat_code, u.phone, u.name, sh.name_ar
  FROM bookings b
  JOIN seats s   ON s.id = b.seat_id
  JOIN shifts sh ON sh.id = b.shift_id
  JOIN users u   ON u.id = b.client_id
  WHERE b.hub_id=? AND b.booking_date=?      -- INV-01, from session
  ORDER BY sh.start_time, s.seat_code
```
Seats the client can actually scan: this is the offline "is he here" list, so it doubles as the no-camera fallback path.

```
confirmAttendance(hubId, qrCode, staffId)
  BEGIN
    SELECT * FROM bookings
      WHERE qr_code=? AND hub_id=?     -- the hub_id predicate is the INV-01 guard
      FOR UPDATE
      -> none => 'رمز الحضور غير صالح'
      -> already 'attended' => 'تم تسجيل الحضور مسبقاً'   (guards duplicate scan)
      -> 'cancelled' or any other status => 'رمز الحضور غير صالح'
    UPDATE bookings SET status='attended' WHERE id=? AND hub_id=?
  COMMIT
  returns the booking row so recordCash() can use it
```
`FOR UPDATE` plus the status guard makes a double-tap or double-scan idempotent — the spec's "duplicate QR → error" case, handled by state rather than by a lookup table.

The plan filtered inside the lock with `status IN ('pending','confirmed')`, which would make an already-`attended` booking indistinguishable from a missing one and report `رمز الحضور غير صالح` instead of `تم تسجيل الحضور مسبقاً`. The row is therefore locked by `qr_code + hub_id` only and the status is inspected in PHP.

```
recordCash(bookingId, hubId, staffId, amount)
  - amount comes ONLY from the argument; the caller passes getPrice(...).
    A POSTed amount is never read (spec R2 fail case).
  - INSERT IGNORE-free plain INSERT into
    payments_cash(booking_id, hub_id, amount, received_by, received_at)
  - the PLAN-000 UNIQUE KEY (booking_id) makes a second insert fail
    -> 'تم تسجيل الدفعة مسبقاً'
```

```
getPrice(hubId, branchId)   -- from branches.price_per_hour, set in PLAN-002
```
Price lives on the branch, so a single `SELECT` suffices. No per-shift or per-room pricing (SPEC-002 puts rooms OUT).

```
confirmAndPay(hubId, qrCode, staffId)      -- thin orchestrator used by the page
  1. booking = confirmAttendance(...)       -- its own transaction, commits
  2. recordCash(booking.id, hubId, staffId, getPrice(hubId, booking.branch_id))
```
Splitting this from the two primitives keeps `recordCash()` unit-testable and stops the page from ever assembling an amount.

**These two writes are separate transactions, on purpose.** Attendance and payment are not one unit of work: the client did arrive, so attendance must survive even if the cash insert fails. A forced `payments_cash` insert failure therefore leaves the booking `attended` and unpaid, which the page surfaces through the `لم يُحصّل` counter so the owner can settle it. Rolling both back together would erase a fact that actually happened.

## Page structure (`dashboard/hub/attendance.php`)
- Today's date, list of bookings: seat code, shift, client name/phone, `qr_code`, status badge.
- Text input to paste/type a `qr_code` + "تسجيل الحضور" button (the camera-free path).
- Per-row "تسجيل الحضور" button (the path for staff who already matched the client by name).
- Money column shows the amount that will be recorded; staff never types it.

Both entry points call the same `confirmAndPay()`.

## Steps
1. Add the 5 functions to `includes/functions.php`.
2. `dashboard/hub/attendance.php` — role guard, list, both entry forms, CSRF.
3. Log in as staff, check in a confirmed booking; verify status + one `payments_cash` row.
4. Scan the same code again; verify the error and `COUNT(payments_cash) = 1`.
5. Lint + run tests.

## Reuse
- `requireRole([...])`, `csrf_*`, `redirect()`, flash, `e()`, `pageStart/pageEnd` — PLAN-000.
- `bookings.qr_code` and `status` — PLAN-004. No new booking field is added here.
- `branches.price_per_hour` — PLAN-002. No `rooms` pricing table.
- `payments_cash UNIQUE(booking_id)` — PLAN-000 schema; the DB enforces "no duplicate payment", so no extra check code is needed.

## Out of scope
Real payment processing, PalPay, QR image generation, camera/scanner JS, refunds, partial payments, multi-currency, receipts/PDF, expense tracking, staff shift wages, salary reports.

## Rollback note
```
rm dashboard/hub/attendance.php
git checkout includes/functions.php     # drops the 5 added functions
DELETE FROM payments_cash;              -- optional
```
Bookings keep existing (only `status` changed, which is revertible); nothing else depends on this page yet.

## Tests → acceptance mapping

| Spec acceptance | Verification | Result |
|---|---|---|
| Confirmed booking + valid QR → `attended` + 1 `payments_cash` row with server price | Check in a booking priced 10 → `SELECT status FROM bookings` = `attended`; `SELECT amount FROM payments_cash WHERE booking_id=?` = 10 | ✅ status `attended`, 1 row, amount 10.00, `hub_id` = session hub, `received_by` = staff user |
| Same QR twice → error, COUNT payments = 1 | Re-submit the code → exact string `تم تسجيل الحضور مسبقاً`; `SELECT COUNT(*) FROM payments_cash WHERE booking_id=?` = 1 | ✅ exact string, still 1 payment, status unchanged; the attended row also loses its check-in button |
| Client role POST → 403 | Client session POSTs `attendance.php` → `403` | ✅ 403 on GET and POST, 0 payments created |
| Tampered POST amount ignored | POST `amount=1` with a 10-priced booking → stored amount is still 10 | ✅ `amount=1` and `amount=0` both stored 10.00; the page never reads the field |
| `hub_id` never from POST | POST `hub_id=<other hub>` → assert `payments_cash.hub_id` equals session hub, and that a QR from another hub is rejected | ✅ hub A staff scanning hub B's QR → `رمز الحضور غير صالح`, 0 payments, hub B booking untouched; spoofed `hub_id`/`staff_id` ignored, 0 rows in hub B |
| Unknown QR → `رمز الحضور غير صالح` | POST a random string → exact string, no rows changed | ✅ random and empty QR both rejected; a `cancelled` booking's QR is rejected too and stays `cancelled` |
| `pending` booking can be checked in | Seed `status='pending'`, check in → `attended` | ✅ pending → `attended` with 1 payment row |
| Today's list is hub-scoped | HubA staff sees only hubA bookings for today | ✅ hub A staff sees hub A only, hub B's QR and price absent; hub B's staff sees the mirror image; hub_admin of hub A also gets 200 |
| Missing CSRF rejected | POST without token → no attendance change | ✅ `انتهت صلاحية الجلسة`, booking still `confirmed`, 0 payments |
| Offline-friendly, no live API | Inspect `attendance.php`: no external request, no scanner dependency | ✅ no `http://`/`https://` in the file, no `Html5Qrcode`/`getUserMedia`/`qrcode.min.js`, no QR image generation, no `<script>` |
| `php -l` | `php -l dashboard/hub/attendance.php` | ✅ 19 PHP files, 0 errors |
| Reload dashboard, check RTL + size | Load `/dashboard/hub/attendance.php`, confirm RTL and <1.5MB | ✅ RTL shell, well under 1.5MB, no page-specific JS |

Also verified: invalid and future dates redirect with a flash; yesterday's view still renders; `getPrice()` returns 10.00 / 25.00 per hub and throws `الفرع غير موجود` for a foreign branch; `recordCash()` twice → `تم تسجيل الدفعة مسبقاً` with the first amount intact; `confirmAndPay()` returns the booking and amount; a forced `payments_cash` insert failure leaves the booking `attended` and unpaid with no payment row; the totals row shows حجوزات / حضور / نقد محصّل / لم يُحصّل; the row shows `دفعة مسجلة: 10.00` after payment.

Total: 83 checks for PLAN-005. PLAN-000 (34), PLAN-001 (65), PLAN-002 (95), PLAN-003 (87), PLAN-004 (109) and the rollback probe (11) still pass.

## Bug found in PLAN-004 while running PLAN-005
The PLAN-004 race test had been passing by luck. A dedicated stress run — 30 rounds of three concurrent `createBooking()` calls plus 10 trials of four on a single slot — surfaced `SQLSTATE[40001] ... 1213 Deadlock found`. Under contention InnoDB picks one transaction as the deadlock victim, and the raw driver error reached the caller instead of `المقعد محجوز`.

`createBooking()` is now a thin retry wrapper around `createBookingOnce()`: error 1213 (deadlock) and 1205 (lock wait timeout) roll back and retry up to 3 times with a short backoff, since neither leaves a trace and the FOR UPDATE check is idempotent. Every other rejection returns immediately. After the change the stress run is clean: 40/40 rounds, exactly one winner each, every loser got `المقعد محجوز`, zero driver errors, zero INV-04 violations.
