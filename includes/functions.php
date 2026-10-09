<?php
/**
 * Logic Layer - shared helpers used by every later plan (PLAN-001..006).
 * Spec: SPEC-000-shared.md (R1 multi-tenancy, R3 subscription gate, R6 RTL).
 *
 * No feature function lives here yet: createBooking()/confirmAttendance()/
 * getHubIncome() arrive with their own plan. This file only carries the
 * contract every one of them needs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

// ---------------------------------------------------------------------------
// Output + navigation
// ---------------------------------------------------------------------------

/**
 * Escape every value printed in a view (htmlspecialchars, UTF-8).
 *
 * @param mixed $value
 */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** POST-redirect-GET, per AGENTS.md. */
function redirect(string $url): void
{
    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * Web root of the app as a prefix, computed once per request.
 * Works both when Apache serves the repo root (local: '/Diwan') and when
 * public/ is the docroot on shared hosting ('').
 */
function baseUrl(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $base    = '';
    $appDir  = realpath(__DIR__ . '/..');                                  // .../Diwan
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;

    if ($appDir !== false && $docRoot !== false) {
        $appDir  = str_replace('\\', '/', $appDir);
        $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
        $prefix  = $docRoot . '/';

        $inside = strncmp($appDir, $prefix, strlen($prefix)) === 0;
        if (!$inside && DIRECTORY_SEPARATOR === '\\') {                    // Windows: case-insensitive
            $inside = strncasecmp($appDir, $prefix, strlen($prefix)) === 0;
        }

        if ($inside) {
            $base = '/' . substr($appDir, strlen($prefix));
        }
    }

    return $base;
}

/** Absolute URL for an app path: url('public/login.php'). */
function url(string $path = ''): string
{
    return baseUrl() . '/' . ltrim($path, '/');
}

/** Absolute URL for a static asset: asset('css/style.css'). */
function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

/** First page a role is allowed to land on after login. */
function homeFor(string $role): string
{
    switch ($role) {
        case 'super_admin':
            return url('dashboard/super/index.php');
        case 'hub_admin':
        case 'staff':
            return url('dashboard/hub/index.php');
        default:
            return url('public/search.php');
    }
}

// ---------------------------------------------------------------------------
// Dates (all date math stays server-side)
// ---------------------------------------------------------------------------

function today(): string
{
    return date('Y-m-d');
}

/** Strict Y-m-d check, rejects 2026-02-31 and 2026/01/01. */
function isValidDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

// ---------------------------------------------------------------------------
// Search by city / date / shift (SPEC-003, PLAN-003)
// ---------------------------------------------------------------------------

/** Label sets for the branch card fields collected by PLAN-002. */
function searchOptionLabels(): array
{
    return [
        'electricity_status' => [
            'none' => 'غير متوفر', 'grid' => 'كهرباء شبكة',
            'generator' => 'مولد كهرباء', 'solar' => 'طاقة شمسية',
        ],
        'internet_strength' => [
            'none' => 'غير متوفر', 'weak' => 'ضعيف',
            'good' => 'جيد', 'excellent' => 'ممتاز',
        ],
        'power_source' => [
            'none' => 'غير متوفر', 'generator' => 'مولد كهرباء', 'solar' => 'طاقة شمسية',
        ],
    ];
}

/** Distinct cities that have an active branch, for the city <select>. */
function listCities(): array
{
    return db()->query(
        'SELECT DISTINCT b.city
           FROM branches b
           JOIN hubs h ON h.id = b.hub_id AND h.status = \'active\'
          WHERE b.active = 1
          ORDER BY b.city'
    )->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Shift windows a client can pick, optionally narrowed to one city.
 * Drives the shift <select>; an empty city returns every active shift window.
 *
 * @return array<int,array{id:int,name_ar:string,start_time:string,end_time:string,city:string}>
 */
function listSearchShifts(string $city = ''): array
{
    $sql = 'SELECT s.id, s.name_ar, s.start_time, s.end_time, b.city
              FROM shifts s
              JOIN branches b ON b.id = s.branch_id AND b.active = 1
              JOIN hubs h ON h.id = s.hub_id AND h.status = \'active\'
             WHERE s.active = 1';
    $params = [];

    if ($city !== '') {
        $sql .= ' AND b.city = ?';
        $params[] = $city;
    }

    $sql .= ' ORDER BY b.city, s.start_time';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Daily report + emergency closure (SPEC-006, PLAN-006)
// ---------------------------------------------------------------------------

/**
 * INV-05 occupancy. Single definition, and no other formula is permitted.
 *
 *   numerator   = COUNT(bookings status IN ('confirmed','attended'))
 *   denominator = COUNT(seats WHERE active=1) for the branch
 *
 * `pending` and `cancelled` are excluded from the numerator. A denominator of 0
 * returns 0.0 so a fresh hub cannot divide by zero.
 *
 * This is NOT getAvailability() (PLAN-003), which counts `pending` too because a
 * pending seat is still held. Both are spec-locked; do not unify them.
 *
 * @return array{numerator:int,denominator:int,occupancy:float}
 */
function getOccupancy(int $branchId, string $date, int $shiftId = 0): array
{
    if (!isValidDate($date)) {
        throw new RuntimeException('تاريخ غير صالح');
    }

    $num = db()->prepare(
        "SELECT COUNT(*) FROM bookings
          WHERE branch_id = ?
            AND booking_date = ?
            AND status IN ('confirmed', 'attended')"
            . ($shiftId > 0 ? ' AND shift_id = ?' : '')
    );
    $num->execute($shiftId > 0 ? [$branchId, $date, $shiftId] : [$branchId, $date]);
    $numerator = (int) $num->fetchColumn();

    $den = db()->prepare('SELECT COUNT(*) FROM seats WHERE branch_id = ? AND active = 1');
    $den->execute([$branchId]);
    $denominator = (int) $den->fetchColumn();

    return [
        'numerator'   => $numerator,
        'denominator' => $denominator,
        'occupancy'   => $denominator > 0 ? round($numerator / $denominator, 4) : 0.0,
    ];
}

/**
 * One day for one hub: booking counts per status, the day's cash, and per
 * branch/shift occupancy.
 *
 * hubId always comes from the session, never from the request (INV-01).
 * subscriptionOk() is deliberately NOT applied: an owner may always read their
 * own report even when the subscription lapsed. Only search and booking are gated.
 *
 * @return array<string,mixed>
 */
function getDailyReport(int $hubId, string $date = ''): array
{
    $date = $date === '' ? today() : $date;

    if (!isValidDate($date)) {
        throw new RuntimeException('تاريخ غير صالح');
    }

    $counts = db()->prepare(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(status = \'attended\'), 0)  AS attended,
                COALESCE(SUM(status = \'confirmed\'), 0) AS confirmed,
                COALESCE(SUM(status = \'pending\'), 0)   AS pending,
                COALESCE(SUM(status = \'cancelled\'), 0) AS cancelled
           FROM bookings
          WHERE hub_id = ?
            AND booking_date = ?'
    );
    $counts->execute([$hubId, $date]);
    $totals = $counts->fetch();

    // Money is dated by received_at, not booking_date: cash taken today for a
    // booking made yesterday is today's income.
    $cash = db()->prepare(
        'SELECT COALESCE(SUM(amount), 0) FROM payments_cash
          WHERE hub_id = ?
            AND DATE(received_at) = ?'
    );
    $cash->execute([$hubId, $date]);
    $cashTotal = (float) $cash->fetchColumn();

    $shifts = db()->prepare(
        'SELECT id, branch_id, name_ar, start_time, end_time
           FROM shifts
          WHERE hub_id = ?
            AND active = 1
          ORDER BY branch_id, start_time'
    );
    $shifts->execute([$hubId]);

    $branchRows = [];
    foreach ($shifts->fetchAll() as $shift) {
        $branchId = (int) $shift['branch_id'];

        if (!isset($branchRows[$branchId])) {
            $stmt = db()->prepare(
                'SELECT id, name, city, active FROM branches WHERE id = ? AND hub_id = ? LIMIT 1'
            );
            $stmt->execute([$branchId, $hubId]);
            $branch = $stmt->fetch();

            if ($branch === false || (int) $branch['active'] !== 1) {
                continue;
            }
            $branchRows[$branchId] = ['branch' => $branch, 'shifts' => []];
        }

        $branchRows[$branchId]['shifts'][] = [
            'shift'     => $shift,
            'occupancy' => getOccupancy($branchId, $date, (int) $shift['id']),
        ];
    }

    return [
        'date'      => $date,
        'totals'    => [
            'total'     => (int) $totals['total'],
            'attended'  => (int) $totals['attended'],
            'confirmed' => (int) $totals['confirmed'],
            'pending'   => (int) $totals['pending'],
            'cancelled' => (int) $totals['cancelled'],
        ],
        'cash_total' => $cashTotal,
        'branches'   => $branchRows,
    ];
}

/**
 * Close a branch for one date.
 *
 * Existing bookings are NOT touched: they stay and stay flagged, per SPEC-006.
 * No UPDATE, no DELETE, no notification. PLAN-003 hides the branch from search
 * and PLAN-004 refuses bookings for that date.
 *
 * @throws RuntimeException 'تاريخ غير صالح' / 'سبب الإغلاق مطلوب' /
 *                        'الإغلاق مسجل مسبقاً'
 */
function addEmergencyClose(int $hubId, int $branchId, string $date, string $reason): int
{
    ownedBranch($branchId, $hubId);                    // INV-01 cross-hub guard

    if (!isValidDate($date)) {
        throw new RuntimeException('تاريخ غير صالح');
    }
    if ($date < today()) {
        throw new RuntimeException('تاريخ غير صالح');
    }

    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('سبب الإغلاق مطلوب');
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO emergency_closures (hub_id, branch_id, close_date, reason)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$hubId, $branchId, $date, $reason]);

        return (int) db()->lastInsertId();
    } catch (PDOException $ex) {
        // PLAN-000 UNIQUE(branch_id, close_date)
        if ($ex->getCode() === '23000') {
            throw new RuntimeException('الإغلاق مسجل مسبقاً');
        }
        throw $ex;
    }
}

/**
 * Remove a closure. Past dates are refused: reopening history would change what
 * an occupancy report means for a day that already happened.
 *
 * @throws RuntimeException when the closure is missing, cross-hub, or in the past
 */
function removeEmergencyClose(int $hubId, int $closureId): bool
{
    $stmt = db()->prepare('SELECT id, hub_id, close_date FROM emergency_closures WHERE id = ? LIMIT 1');
    $stmt->execute([$closureId]);
    $closure = $stmt->fetch();

    if ($closure === false || (int) $closure['hub_id'] !== $hubId) {
        throw new RuntimeException('الإغلاق غير موجود');
    }

    if ((string) $closure['close_date'] < today()) {
        throw new RuntimeException('لا يمكن إزالة إغلاق في تاريخ سابق');
    }

    $delete = db()->prepare('DELETE FROM emergency_closures WHERE id = ? AND hub_id = ?');
    $delete->execute([$closureId, $hubId]);

    return true;
}

/**
 * Closures for a hub, upcoming first so the form page shows what is still ahead.
 *
 * @return array<int,array<string,mixed>>
 */
function listClosures(int $hubId): array
{
    $stmt = db()->prepare(
        'SELECT ec.id, ec.branch_id, ec.close_date, ec.reason, ec.created_at,
                b.name AS branch_name, b.city
           FROM emergency_closures ec
           JOIN branches b ON b.id = ec.branch_id
          WHERE ec.hub_id = ?
          ORDER BY ec.close_date >= CURDATE() DESC, ec.close_date, b.name'
    );
    $stmt->execute([$hubId]);

    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Attendance + cash on arrival (SPEC-005, PLAN-005)
// ---------------------------------------------------------------------------

/**
 * Hourly price for a branch. Price lives on the branch (PLAN-002), so one row is
 * enough: no per-shift or per-room pricing in the MVP.
 *
 * @throws RuntimeException when the branch belongs to another hub
 */
function getPrice(int $hubId, int $branchId): float
{
    ownedBranch($branchId, $hubId);              // cross-hub guard

    $stmt = db()->prepare('SELECT price_per_hour FROM branches WHERE id = ? AND hub_id = ? LIMIT 1');
    $stmt->execute([$branchId, $hubId]);

    return (float) $stmt->fetchColumn();
}

/**
 * Today's bookings for one hub, ordered by shift then seat.
 *
 * hubId always comes from the session, never from the request (INV-01). The QR
 * string is listed per row so staff can read it off the client's phone, which
 * is the whole offline check-in story.
 *
 * @return array<int,array<string,mixed>>
 */
function listTodaysBookings(int $hubId, string $date = ''): array
{
    $date = $date === '' ? today() : $date;

    if (!isValidDate($date)) {
        throw new RuntimeException('تاريخ غير صالح');
    }

    $stmt = db()->prepare(
        'SELECT b.id, b.qr_code, b.status, b.booking_date, b.branch_id, b.shift_id,
                s.seat_code, u.name AS client_name, u.phone AS client_phone,
                sh.name_ar AS shift_name, sh.start_time, sh.end_time,
                br.price_per_hour,
                pc.id AS payment_id, pc.amount AS paid_amount
           FROM bookings b
           JOIN seats s    ON s.id = b.seat_id
           JOIN shifts sh  ON sh.id = b.shift_id
           JOIN users u    ON u.id = b.client_id
           JOIN branches br ON br.id = b.branch_id
           LEFT JOIN payments_cash pc ON pc.booking_id = b.id
          WHERE b.hub_id = ?
            AND b.booking_date = ?
          ORDER BY sh.start_time, s.seat_code'
    );
    $stmt->execute([$hubId, $date]);

    return $stmt->fetchAll();
}

/**
 * Mark one booking as attended. The FOR UPDATE lock plus the status guard make a
 * double scan idempotent by state, with no lookup table (SPEC-005).
 *
 * @return array<string,mixed> the booking row, for recordCash()
 * @throws RuntimeException 'رمز الحضور غير صالح' / 'تم تسجيل الحضور مسبقاً'
 */
function confirmAttendance(int $hubId, string $qrCode, int $staffId): array
{
    $qrCode = trim($qrCode);

    if ($qrCode === '') {
        throw new RuntimeException('رمز الحضور غير صالح');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Locked so two staff scanning the same code cannot both pass this check.
        $stmt = $pdo->prepare(
            'SELECT id, hub_id, branch_id, shift_id, seat_id, client_id, status
               FROM bookings
              WHERE qr_code = ?
                AND hub_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$qrCode, $hubId]);
        $booking = $stmt->fetch();

        if ($booking === false) {
            throw new RuntimeException('رمز الحضور غير صالح');
        }

        $status = (string) $booking['status'];

        if ($status === 'attended') {
            throw new RuntimeException('تم تسجيل الحضور مسبقاً');
        }

        if (!in_array($status, ['pending', 'confirmed'], true)) {
            throw new RuntimeException('رمز الحضور غير صالح');
        }

        $update = $pdo->prepare(
            "UPDATE bookings SET status = 'attended' WHERE id = ? AND hub_id = ?"
        );
        $update->execute([(int) $booking['id'], $hubId]);

        $pdo->commit();

        return $booking;
    } catch (Throwable $ex) {
        $pdo->rollBack();

        if ($ex instanceof RuntimeException) {
            throw $ex;
        }
        throw $ex;
    }
}

/**
 * Record the cash payment for one booking.
 *
 * The amount is the argument, never a request field: the caller passes
 * getPrice(), so a tampered POST amount cannot change what is stored. The
 * PLAN-000 UNIQUE(booking_id) makes a duplicate impossible at DB level.
 *
 * @throws RuntimeException 'تم تسجيل الدفعة مسبقاً' on the unique violation
 */
function recordCash(int $bookingId, int $hubId, int $staffId, float $amount): int
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO payments_cash (booking_id, hub_id, amount, received_by)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$bookingId, $hubId, round($amount, 2), $staffId]);

        return (int) db()->lastInsertId();
    } catch (PDOException $ex) {
        // 1062 on UNIQUE(booking_id): the same booking was already paid.
        if ($ex->getCode() === '23000') {
            throw new RuntimeException('تم تسجيل الدفعة مسبقاً');
        }
        throw $ex;
    }
}

/**
 * Check in and take the cash in one call, which is how the desk works: they
 * always happen together.
 *
 * The amount is assembled here from getPrice(), never by the page, so a posted
 * amount has nowhere to enter.
 *
 * @return array{booking:array<string,mixed>,payment_id:int,amount:float}
 * @throws RuntimeException with the exact Arabic message for every rejection
 */
function confirmAndPay(int $hubId, string $qrCode, int $staffId): array
{
    $booking = confirmAttendance($hubId, $qrCode, $staffId);

    // The booking row decides the branch, and ownedBranch() re-checks the tenant.
    $amount   = getPrice($hubId, (int) $booking['branch_id']);
    $paymentId = recordCash((int) $booking['id'], $hubId, $staffId, $amount);

    return ['booking' => $booking, 'payment_id' => $paymentId, 'amount' => $amount];
}

// ---------------------------------------------------------------------------
// Booking / cancel (SPEC-004, PLAN-004)
// ---------------------------------------------------------------------------

/** Length of the pre-generated, offline-friendly attendance string. */
const QR_LENGTH = 12;

/**
 * Free seats for a branch on a date/shift.
 *
 * The numerator matches getAvailability() exactly (PLAN-003), so the picker and
 * the search card can never disagree. `attended` is excluded here because the
 * day is over for that seat.
 *
 * @return array<int,array<string,mixed>>
 */
function getFreeSeats(int $branchId, string $date, int $shiftId = 0): array
{
    if (!isValidDate($date)) {
        throw new RuntimeException('تاريخ غير صالح');
    }

    // Two queries rather than one clever one: the whole-day held seats, then the
    // shift-specific ones. Both use the PLAN-003 numerator.
    $stmt = db()->prepare(
        'SELECT s.id, s.seat_code, s.price
           FROM seats s
          WHERE s.branch_id = ?
            AND s.active = 1
            AND NOT EXISTS (SELECT 1 FROM bookings b
                             WHERE b.seat_id = s.id
                               AND b.branch_id = s.branch_id
                               AND b.booking_date = ?
                               AND b.status IN (\'pending\', \'confirmed\'))
          ORDER BY s.seat_code'
    );
    $stmt->execute([$branchId, $date]);
    $seats = $stmt->fetchAll();

    if ($shiftId <= 0 || $seats === []) {
        return $seats;
    }

    // A seat held for this specific shift is not free for it, even when the same
    // seat is still free on the other shift of that day.
    $taken = db()->prepare(
        'SELECT seat_id FROM bookings
          WHERE branch_id = ? AND booking_date = ? AND shift_id = ?
            AND status IN (\'pending\', \'confirmed\')'
    );
    $taken->execute([$branchId, $date, $shiftId]);
    $held = array_map('intval', $taken->fetchAll(PDO::FETCH_COLUMN));

    if ($held === []) {
        return $seats;
    }

    return array_values(array_filter(
        $seats,
        static fn ($s) => !in_array((int) $s['id'], $held, true)
    ));
}

/** Collision-resistant attendance string, generated before INSERT. */
function generateQrCode(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no I/O/0/1
    $max = strlen($alphabet) - 1;

    $out = '';
    for ($i = 0; $i < QR_LENGTH; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }

    return $out;
}

/**
 * Hold one seat for one shift.
 *
 * The whole race-safety story is the FOR UPDATE lock, serialised against the
 * PLAN-000 index (seat_id, booking_date, shift_id, status). No partial UNIQUE is
 * used (INV-04).
 *
 * Two concurrent callers on the same slot do not always produce a clean win/lose:
 * InnoDB can pick one transaction as the deadlock victim (1213) or time the other
 * out on the lock wait (1205). Neither is a booking failure, so those two are
 * retried a few times; every other rejection returns immediately.
 *
 * @throws RuntimeException with the exact Arabic message for every rejection
 */
function createBooking(int $clientId, int $seatId, string $date, int $shiftId): int
{
    $attempts = 0;

    while (true) {
        try {
            return createBookingOnce($clientId, $seatId, $date, $shiftId);
        } catch (PDOException $ex) {
            $code = (int) ($ex->errorInfo[1] ?? 0);
            if (($code === 1213 || $code === 1205) && $attempts < 3) {
                $attempts++;
                usleep(20_000 * $attempts);      // 20ms, 40ms, 60ms
                continue;
            }
            throw $ex;
        }
    }
}

/** One attempt at the seat hold. See createBooking() for the retry policy. */
function createBookingOnce(int $clientId, int $seatId, string $date, int $shiftId): int
{
    if (!isValidDate($date)) {
        throw new RuntimeException('تاريخ غير صالح');
    }
    if ($date < today()) {
        throw new RuntimeException('تاريخ غير صالح');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 1. the seat decides the hub and the branch
        $seat = $pdo->prepare(
            'SELECT s.id, s.hub_id, s.branch_id, s.active
               FROM seats s
              WHERE s.id = ?
              LIMIT 1'
        );
        $seat->execute([$seatId]);
        $seatRow = $seat->fetch();

        if ($seatRow === false || (int) $seatRow['active'] !== 1) {
            throw new RuntimeException('المقعد غير موجود');
        }

        $hubId    = (int) $seatRow['hub_id'];
        $branchId = (int) $seatRow['branch_id'];

        // 2. the shift must belong to the same branch
        $shift = $pdo->prepare(
            'SELECT id, branch_id, hub_id, active FROM shifts WHERE id = ? LIMIT 1'
        );
        $shift->execute([$shiftId]);
        $shiftRow = $shift->fetch();

        if ($shiftRow === false || (int) $shiftRow['active'] !== 1) {
            throw new RuntimeException('الوردية غير متاحة');
        }
        if ((int) $shiftRow['hub_id'] !== $hubId || (int) $shiftRow['branch_id'] !== $branchId) {
            throw new RuntimeException('المقعد غير متاح');
        }

        // 3. hub state first, so a suspended hub reports itself rather than
        //    hiding behind the generic expired message
        $status = $pdo->prepare('SELECT status FROM hubs WHERE id = ? LIMIT 1');
        $status->execute([$hubId]);
        $hubStatus = (string) $status->fetchColumn();

        if ($hubStatus !== 'active') {
            throw new RuntimeException('المساحة موقوفة');
        }

        // 4. INV-03, the gate's second caller
        if (!subscriptionOk($hubId)) {
            throw new RuntimeException('اشتراك المساحة منتهي');
        }

        // 5. SPEC-006 emergency closure for this branch and date
        $closed = $pdo->prepare(
            'SELECT 1 FROM emergency_closures WHERE branch_id = ? AND close_date = ? LIMIT 1'
        );
        $closed->execute([$branchId, $date]);
        if ($closed->fetchColumn() !== false) {
            throw new RuntimeException('مغلق طارئ');
        }

        // 6. INV-04 double booking: the lock is what makes this correct
        $held = $pdo->prepare(
            'SELECT id FROM bookings
              WHERE seat_id = ?
                AND booking_date = ?
                AND shift_id = ?
                AND status IN (\'pending\', \'confirmed\')
              FOR UPDATE'
        );
        $held->execute([$seatId, $date, $shiftId]);
        if ($held->fetchColumn() !== false) {
            throw new RuntimeException('المقعد محجوز');
        }

        // 7. insert, hub_id straight from the seat row
        $qr = generateQrCode();
        $insert = $pdo->prepare(
            'INSERT INTO bookings (client_id, hub_id, branch_id, seat_id, shift_id, booking_date, status, qr_code)
             VALUES (?, ?, ?, ?, ?, ?, \'pending\', ?)'
        );
        $insert->execute([$clientId, $hubId, $branchId, $seatId, $shiftId, $date, $qr]);

        $bookingId = (int) $pdo->lastInsertId();
        $pdo->commit();

        return $bookingId;
    } catch (PDOException $ex) {
        // A deadlock (1213) or lock wait timeout (1205) leaves no trace: roll
        // back and let createBooking() retry the whole attempt.
        $pdo->rollBack();

        $code = (int) ($ex->errorInfo[1] ?? 0);
        if ($code === 1213 || $code === 1205) {
            throw $ex;
        }

        // UNIQUE(qr_code) collision: absurdly unlikely, but the client should
        // still see a sentence instead of a driver error.
        if ($ex->getCode() === '23000') {
            throw new RuntimeException('المقعد محجوز');
        }

        throw $ex;
    } catch (Throwable $ex) {
        $pdo->rollBack();

        if ($ex instanceof RuntimeException) {
            throw $ex;
        }
        throw $ex;
    }
}

/**
 * Cancel a booking. Own booking only: the client id is part of the WHERE clause,
 * so another client's booking id matches nothing.
 *
 * @return bool false when the booking does not exist or is not the caller's
 * @throws RuntimeException when the booking was already attended
 */
function cancelBooking(int $bookingId, int $clientId): bool
{
    $stmt = db()->prepare(
        'SELECT id, status FROM bookings WHERE id = ? AND client_id = ? LIMIT 1'
    );
    $stmt->execute([$bookingId, $clientId]);
    $booking = $stmt->fetch();

    if ($booking === false) {
        return false;
    }

    $status = (string) $booking['status'];

    if ($status === 'attended') {
        throw new RuntimeException('لا يمكن إلغاء حضور مؤكد');
    }

    if ($status === 'cancelled') {
        return true;                                  // already cancelled, no-op
    }

    $update = db()->prepare(
        'UPDATE bookings SET status = \'cancelled\' WHERE id = ? AND client_id = ?'
    );
    $update->execute([$bookingId, $clientId]);

    return true;
}

/**
 * The client's own bookings with readable hub/branch/shift columns.
 *
 * @return array<int,array<string,mixed>>
 */
function listMyBookings(int $clientId): array
{
    $stmt = db()->prepare(
        'SELECT b.id, b.status, b.qr_code, b.booking_date,
                h.name AS hub_name, br.city, br.name AS branch_name,
                s.name_ar AS shift_name, s.start_time, s.end_time,
                st.seat_code
           FROM bookings b
           JOIN hubs h     ON h.id = b.hub_id
           JOIN branches br ON br.id = b.branch_id
           JOIN seats st   ON st.id = b.seat_id
           JOIN shifts s   ON s.id = b.shift_id
          WHERE b.client_id = ?
          ORDER BY b.booking_date DESC, b.id DESC'
    );
    $stmt->execute([$clientId]);

    return $stmt->fetchAll();
}

/**
 * Look up a user for the login form. Lives here so no presentation file touches
 * SQL (AGENTS.md three-layer contract).
 *
 * @return array<string,mixed>|null
 */
function findUserForLogin(string $phone): ?array
{
    $stmt = db()->prepare(
        'SELECT id, hub_id, role, name, password_hash, active
           FROM users
          WHERE phone = ?
          LIMIT 1'
    );
    $stmt->execute([trim($phone)]);
    $user = $stmt->fetch();

    return $user === false ? null : $user;
}

/**
 * Hubs a client may see for a date. One query, no N+1.
 *
 * INV-03 inlined exactly as subscriptionOk() reads it: hubs.status='active'
 * AND a subscription row with end_date >= CURDATE(). Expired, pending,
 * rejected and suspended hubs never appear.
 *
 * Closed branches are returned in a separate list rather than dropped, so the
 * page can badge them `مغلق طارئ` (SPEC-003 + SPEC-006 acceptance) while they
 * stay out of the bookable list.
 *
 * @return array{open:array<int,array<string,mixed>>,closed:array<int,array<string,mixed>>}
 * @throws RuntimeException when $date is malformed or in the past
 */
function searchHubs(string $city, string $date, int $shiftId = 0): array
{
    $date = trim($date) === '' ? today() : trim($date);

    if (!isValidDate($date)) {
        throw new RuntimeException('تاريخ غير صالح');
    }
    if ($date < today()) {
        throw new RuntimeException('لا يمكن الحجز في تاريخ سابق');
    }

    $sql = 'SELECT h.id AS hub_id, h.name AS hub_name,
                   b.id AS branch_id, b.city, b.price_per_hour,
                   b.electricity_status, b.internet_strength, b.power_source,
                   (ec.id IS NOT NULL) AS is_closed
              FROM hubs h
              JOIN branches b ON b.hub_id = h.id AND b.active = 1
              LEFT JOIN emergency_closures ec
                     ON ec.branch_id = b.id AND ec.close_date = ?
             WHERE h.status = \'active\'
               AND EXISTS (SELECT 1 FROM hub_subscriptions s
                            WHERE s.hub_id = h.id AND s.end_date >= CURDATE())';

    $params = [$date];

    if ($city !== '') {
        $sql .= ' AND b.city = ?';
        $params[] = $city;
    }

    $sql .= ' ORDER BY b.city, h.name';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $open   = [];
    $closed = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['hub_id']       = (int) $row['hub_id'];
        $row['branch_id']    = (int) $row['branch_id'];
        $row['price_per_hour'] = (float) $row['price_per_hour'];

        if ((int) $row['is_closed'] === 1) {
            $closed[] = $row;
            continue;
        }

        // A shift filter only makes sense for branches that own that shift.
        if ($shiftId > 0 && !branchOwnsShift((int) $row['branch_id'], $shiftId)) {
            continue;
        }

        $row['availability'] = getAvailability((int) $row['branch_id'], $date, $shiftId);
        $open[] = $row;
    }

    return ['open' => $open, 'closed' => $closed];
}

/** True when the branch owns the shift (blocks a cross-hub shift_id). */
function branchOwnsShift(int $branchId, int $shiftId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM shifts WHERE id = ? AND branch_id = ? AND active = 1 LIMIT 1');
    $stmt->execute([$shiftId, $branchId]);

    return $stmt->fetchColumn() !== false;
}

/**
 * Availability for a branch on a date.
 *
 * total     = active seats in the branch (the INV-05 denominator)
 * booked    = pending + confirmed + attended bookings
 * available = total - booked
 *
 * Every status that still holds a seat counts as taken: a pending seat is held
 * for the client, and an attended seat was already used today. This is what
 * SPEC-003's acceptance ("10 seats, 4 booked confirmed/attended -> 6 متاح")
 * requires. INV-05 occupancy stays a separate ratio in PLAN-006 and must not be
 * substituted for this one.
 *
 * @return array{total:int,booked:int,available:int}
 */
function getAvailability(int $branchId, string $date, int $shiftId = 0): array
{
    if (!isValidDate($date)) {
        throw new RuntimeException('تاريخ غير صالح');
    }

    $total = db()->prepare('SELECT COUNT(*) FROM seats WHERE branch_id = ? AND active = 1');
    $total->execute([$branchId]);
    $totalCount = (int) $total->fetchColumn();

    $bookedSql = 'SELECT COUNT(*) FROM bookings
                   WHERE branch_id = ?
                     AND booking_date = ?
                     AND status IN (\'pending\', \'confirmed\', \'attended\')';
    $params = [$branchId, $date];

    if ($shiftId > 0) {
        $bookedSql .= ' AND shift_id = ?';
        $params[] = $shiftId;
    }

    $booked = db()->prepare($bookedSql);
    $booked->execute($params);
    $bookedCount = (int) $booked->fetchColumn();

    return [
        'total'     => $totalCount,
        'booked'    => $bookedCount,
        'available' => max(0, $totalCount - $bookedCount),
    ];
}

// ---------------------------------------------------------------------------
// Branch + seats + shifts (SPEC-002, PLAN-002)
// ---------------------------------------------------------------------------

/** MVP ships exactly 2 shifts per branch (SPEC-002 R3). */
const MIN_SHIFTS_PER_BRANCH = 2;

/**
 * The hub_admin's session hub, as int. Every function below reads the tenant
 * from here, never from GET/POST (INV-01).
 */
function sessionHubId(): int
{
    $user = $_SESSION['user'] ?? null;

    if (!is_array($user) || ($user['hub_id'] ?? null) === null) {
        throw new RuntimeException('لا يوجد حساب مساحة');
    }

    return (int) $user['hub_id'];
}

/**
 * Resolve a branch and prove it belongs to $hubId. Guards every branch-scoped
 * call against a cross-hub id.
 *
 * @return array<string,mixed> the branch row
 * @throws RuntimeException when the branch is missing or belongs to another hub
 */
function ownedBranch(int $branchId, int $hubId): array
{
    $stmt = db()->prepare(
        'SELECT id, hub_id, name, city, address, phone, electricity_status,
                internet_strength, power_source, price_per_hour, active
           FROM branches
          WHERE id = ?
            AND hub_id = ?
          LIMIT 1'
    );
    $stmt->execute([$branchId, $hubId]);
    $branch = $stmt->fetch();

    if ($branch === false) {
        throw new RuntimeException('الفرع غير موجود');
    }

    return $branch;
}

/** The hub's single active branch, or null. */
function getBranchForHub(int $hubId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, hub_id, name, city, address, phone, electricity_status,
                internet_strength, power_source, price_per_hour, active
           FROM branches
          WHERE hub_id = ?
            AND active = 1
          LIMIT 1'
    );
    $stmt->execute([$hubId]);
    $branch = $stmt->fetch();

    return $branch === false ? null : $branch;
}

/**
 * Create the hub's one branch. active = 1 always; a second active branch is
 * refused because MVP supports one (out of scope: multi-branch UI).
 */
function createBranch(int $hubId, string $city, string $address, string $electricityStatus, string $internetStrength, string $powerSource, float $pricePerHour): int
{
    $city  = trim($city);
    $pricePerHour = round($pricePerHour, 2);

    if ($city === '') {
        throw new RuntimeException('اسم المدينة مطلوب');
    }
    if ($pricePerHour < 0) {
        throw new RuntimeException('السعر يجب أن يكون صفراً أو أكثر');
    }

    if (getBranchForHub($hubId) !== null) {
        throw new RuntimeException('تم إنشاء الفرع مسبقاً');
    }

    // branches.name is NOT NULL; with one branch per hub the hub name is the
    // least surprising default and keeps the form to the fields AGENTS.md lists.
    $stmt = db()->prepare('SELECT name FROM hubs WHERE id = ? LIMIT 1');
    $stmt->execute([$hubId]);
    $branchName = (string) ($stmt->fetchColumn() ?: 'الفرع الرئيسي');

    $insert = db()->prepare(
        'INSERT INTO branches (hub_id, name, city, address, electricity_status, internet_strength, power_source, price_per_hour, active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $insert->execute([
        $hubId,
        $branchName,
        $city,
        trim($address) === '' ? null : trim($address),
        $electricityStatus,
        $internetStrength,
        $powerSource,
        $pricePerHour,
    ]);

    return (int) db()->lastInsertId();
}

/** Edit the existing branch. Same fields, no new branch. */
function updateBranch(int $hubId, string $city, string $address, string $electricityStatus, string $internetStrength, string $powerSource, float $pricePerHour): bool
{
    $city = trim($city);
    $pricePerHour = round($pricePerHour, 2);

    if ($city === '') {
        throw new RuntimeException('اسم المدينة مطلوب');
    }
    if ($pricePerHour < 0) {
        throw new RuntimeException('السعر يجب أن يكون صفراً أو أكثر');
    }

    $branch = getBranchForHub($hubId);
    if ($branch === null) {
        throw new RuntimeException('تم إنشاء الفرع مسبقاً');
    }

    $stmt = db()->prepare(
        'UPDATE branches
            SET city = ?, address = ?, electricity_status = ?, internet_strength = ?,
                power_source = ?, price_per_hour = ?
          WHERE id = ?
            AND hub_id = ?'
    );
    $stmt->execute([
        $city,
        trim($address) === '' ? null : trim($address),
        $electricityStatus,
        $internetStrength,
        $powerSource,
        $pricePerHour,
        (int) $branch['id'],
        $hubId,
    ]);

    return $stmt->rowCount() >= 0;
}

/**
 * Bulk-add seats to a branch, one INSERT per code inside one transaction, so
 * 20 seats cost the owner one POST and a partial failure rolls all of them back.
 *
 * @param string[] $codes
 * @return int number of seats inserted
 */
function createSeats(int $hubId, int $branchId, array $codes, float $price): int
{
    ownedBranch($branchId, $hubId);                    // cross-hub guard

    $codes = array_values(array_unique(array_filter(
        array_map(static fn ($c) => trim((string) $c), $codes),
        static fn ($c) => $c !== ''
    )));

    if ($codes === []) {
        throw new RuntimeException('أدخل كود مقعد واحداً على الأقل');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO seats (hub_id, branch_id, seat_code, price, active)
             VALUES (?, ?, ?, ?, 1)'
        );

        foreach ($codes as $code) {
            $stmt->execute([$hubId, $branchId, $code, round($price, 2)]);
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();

        // 1062 = UNIQUE(branch_id, seat_code) from the PLAN-000 schema.
        if ($ex instanceof PDOException && $ex->getCode() === '23000') {
            throw new RuntimeException('كود المقعد مكرر');
        }
        throw $ex;
    }

    return count($codes);
}

/** Active seats of a branch; COUNT is the INV-05 occupancy denominator. */
function listSeats(int $hubId, int $branchId): array
{
    ownedBranch($branchId, $hubId);

    $stmt = db()->prepare(
        'SELECT id, seat_code, price, active, created_at
           FROM seats
          WHERE branch_id = ?
            AND hub_id = ?
          ORDER BY seat_code'
    );
    $stmt->execute([$branchId, $hubId]);

    return $stmt->fetchAll();
}

/**
 * Validate one shift window and refuse overlap with the branch's active shifts.
 * $excludeId is used by updateShift() so a shift never overlaps itself.
 */
function assertShiftWindow(int $hubId, int $branchId, string $startTime, string $endTime, int $excludeId = 0): void
{
    ownedBranch($branchId, $hubId);                    // cross-hub guard

    $start = normalizeTime($startTime);
    $end   = normalizeTime($endTime);

    if ($start === null || $end === null) {
        throw new RuntimeException('وقت غير صالح، استخدم HH:MM');
    }
    if ($start >= $end) {
        throw new RuntimeException('وقت النهاية يجب أن يكون بعد البداية');
    }

    $overlap = db()->prepare(
        'SELECT COUNT(*) FROM shifts
          WHERE branch_id = ?
            AND hub_id = ?
            AND active = 1
            AND id <> ?
            AND start_time < ?
            AND end_time > ?'
    );
    $overlap->execute([$branchId, $hubId, $excludeId, $end, $start]);

    if ((int) $overlap->fetchColumn() > 0) {
        throw new RuntimeException('تداخل في الورديات');
    }
}

/**
 * 'HH:MM' (or 'HH:MM:SS') -> zero-padded 'HH:MM:SS', null when unparseable.
 * The padding matters: shift windows are compared as strings, so '8:00:00'
 * must not sort after '14:00:00'.
 */
function normalizeTime(string $time): ?string
{
    $time = trim($time);

    if (!preg_match('/^([0-9]{1,2}):([0-9]{2})(?::([0-9]{2}))?$/', $time, $m)) {
        return null;
    }

    $hour   = (int) $m[1];
    $minute = (int) $m[2];
    $second = (int) ($m[3] ?? 0);

    if ($hour > 23 || $minute > 59 || $second > 59) {
        return null;
    }

    return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
}

/**
 * The bookable branch behind a hub page: hub id, branch id, city, price and the
 * hub status used by the INV-03 gate. Returns null when the branch is not active
 * or does not belong to the hub (a branch cannot pose as another hub).
 *
 * @return array<string,mixed>|null
 */
function getBookableBranch(int $hubId, int $branchId): ?array
{
    $stmt = db()->prepare(
        'SELECT b.id, b.hub_id, b.city, b.price_per_hour,
                h.name AS hub_name, h.status
           FROM branches b
           JOIN hubs h ON h.id = b.hub_id
          WHERE b.id = ?
            AND b.hub_id = ?
            AND b.active = 1
          LIMIT 1'
    );
    $stmt->execute([$branchId, $hubId]);
    $branch = $stmt->fetch();

    return $branch === false ? null : $branch;
}

/** Active shifts of a branch, for the picker's shift <select>. */
function listBranchShifts(int $branchId): array
{
    $stmt = db()->prepare(
        'SELECT id, name_ar, start_time, end_time
           FROM shifts
          WHERE branch_id = ?
            AND active = 1
          ORDER BY start_time'
    );
    $stmt->execute([$branchId]);

    return $stmt->fetchAll();
}

/** Readable Arabic date for cards and lists, without a locale dependency. */
function formatDateLong(string $date): string
{
    if (!isValidDate($date)) {
        return $date;
    }

    $months = [
        1 => 'كانون الثاني', 2 => 'شباط', 3 => 'آذار', 4 => 'نيسان', 5 => 'أيار', 6 => 'حزيران',
        7 => 'تموز', 8 => 'آب', 9 => 'أيلول', 10 => 'تشرين الأول', 11 => 'تشرين الثاني', 12 => 'كانون الأول',
    ];

    $parts = explode('-', $date);

    return ((int) $parts[2]) . ' ' . ($months[(int) $parts[1]] ?? '') . ' ' . $parts[0];
}

/** Format a TIME column value as HH:MM for display. */
function formatTime(?string $time): string
{
    if ($time === null || $time === '') {
        return '';
    }
    $parts = explode(':', $time);

    return sprintf('%02d:%02d', (int) $parts[0], (int) $parts[1]);
}

/**
 * Add shifts to a branch as one batch. MVP requires MIN_SHIFTS_PER_BRANCH active
 * shifts, so the batch is rejected when fewer than that would be active: a
 * single shift on a fresh branch returns 'مطلوب ورديتان على الأقل' and the form
 * asks for the pair the same way.
 *
 * @param array<int,array{name:string,start:string,end:string}> $shifts
 * @return int[] new shift ids
 */
function createShifts(int $hubId, int $branchId, array $shifts): array
{
    ownedBranch($branchId, $hubId);

    $shifts = array_values(array_filter(
        $shifts,
        static fn ($s) => trim((string) ($s['name'] ?? '')) !== '' && trim((string) ($s['start'] ?? '')) !== ''
    ));

    if (count($shifts) < MIN_SHIFTS_PER_BRANCH) {
        throw new RuntimeException('مطلوب ورديتان على الأقل');
    }

    $existing = (int) db()->query(
        'SELECT COUNT(*) FROM shifts WHERE branch_id = ' . (int) $branchId . ' AND active = 1'
    )->fetchColumn();

    if ($existing + count($shifts) < MIN_SHIFTS_PER_BRANCH) {
        throw new RuntimeException('مطلوب ورديتان على الأقل');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO shifts (hub_id, branch_id, name_ar, start_time, end_time, active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );

        $ids = [];
        foreach ($shifts as $shift) {
            $name  = trim((string) $shift['name']);
            $start = normalizeTime((string) $shift['start']);
            $end   = normalizeTime((string) $shift['end']);

            if ($start === null || $end === null) {
                throw new RuntimeException('وقت غير صالح، استخدم HH:MM');
            }
            if ($start >= $end) {
                throw new RuntimeException('وقت النهاية يجب أن يكون بعد البداية');
            }

            // Overlap against the shifts already in the branch AND against the
            // ones inserted earlier in this same batch.
            $overlap = $pdo->prepare(
                'SELECT COUNT(*) FROM shifts
                  WHERE branch_id = ?
                    AND hub_id = ?
                    AND active = 1
                    AND start_time < ?
                    AND end_time > ?'
            );
            $overlap->execute([$branchId, $hubId, $end, $start]);
            if ((int) $overlap->fetchColumn() > 0) {
                throw new RuntimeException('تداخل في الورديات');
            }

            $insert->execute([$hubId, $branchId, $name, $start, $end]);
            $ids[] = (int) $pdo->lastInsertId();
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();

        if ($ex instanceof PDOException && $ex->getCode() === '23000') {
            throw new RuntimeException('تداخل في الورديات');
        }
        throw $ex;
    }

    return $ids;
}

/**
 * Create exactly one shift. Kept as the single-row entry point; the MVP pair
 * rule lives in createShifts(), so callers that need one shift (seeding, tests)
 * are not blocked by it.
 */
function createShift(int $hubId, int $branchId, string $nameAr, string $startTime, string $endTime): int
{
    assertShiftWindow($hubId, $branchId, $startTime, $endTime);

    $stmt = db()->prepare(
        'INSERT INTO shifts (hub_id, branch_id, name_ar, start_time, end_time, active)
         VALUES (?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([
        $hubId,
        $branchId,
        trim($nameAr) === '' ? 'وردية' : trim($nameAr),
        normalizeTime($startTime),
        normalizeTime($endTime),
    ]);

    return (int) db()->lastInsertId();
}

/** Edit a shift; overlap check excludes the row itself. */
function updateShift(int $hubId, int $shiftId, string $nameAr, string $startTime, string $endTime): bool
{
    $stmt = db()->prepare('SELECT id, branch_id FROM shifts WHERE id = ? AND hub_id = ? LIMIT 1');
    $stmt->execute([$shiftId, $hubId]);
    $shift = $stmt->fetch();

    if ($shift === false) {
        throw new RuntimeException('الوردية غير موجودة');
    }

    assertShiftWindow($hubId, (int) $shift['branch_id'], $startTime, $endTime, $shiftId);

    $update = db()->prepare(
        'UPDATE shifts SET name_ar = ?, start_time = ?, end_time = ? WHERE id = ? AND hub_id = ?'
    );
    $update->execute([
        trim($nameAr) === '' ? 'وردية' : trim($nameAr),
        normalizeTime($startTime),
        normalizeTime($endTime),
        $shiftId,
        $hubId,
    ]);

    return true;
}

/** Active shifts of a branch, ordered by start time. */
function listShifts(int $hubId, int $branchId): array
{
    ownedBranch($branchId, $hubId);

    $stmt = db()->prepare(
        'SELECT id, name_ar, start_time, end_time, active
           FROM shifts
          WHERE branch_id = ?
            AND hub_id = ?
            AND active = 1
          ORDER BY start_time'
    );
    $stmt->execute([$branchId, $hubId]);

    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Hub registration + approval (SPEC-001, PLAN-001)
// ---------------------------------------------------------------------------

/**
 * Length of the subscription granted on approval. Single constant, no tiers
 * (deferred per SPEC-001).
 */
const SUBSCRIPTION_MONTHS = 1;

/**
 * Register a new hub owner. Creates the hub_admin user AND the pending hub in
 * one transaction, then writes hub_id server-side. No subscription row yet, so
 * INV-03 keeps the hub invisible until a super_admin approves it.
 *
 * @return array{hub_id:int,user_id:int}
 * @throws RuntimeException on validation or duplicate phone
 */
function registerHub(string $name, string $city, string $phone, string $password): array
{
    $name    = trim($name);
    $city    = trim($city);
    $phone   = trim($phone);

    if ($name === '') {
        throw new RuntimeException('اسم المساحة مطلوب');
    }
    if ($city === '') {
        throw new RuntimeException('اسم المدينة مطلوب');
    }
    if ($phone === '') {
        throw new RuntimeException('رقم الهاتف مطلوب');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('كلمة المرور 8 أحرف على الأقل');
    }

    $pdo = db();

    $exists = $pdo->prepare('SELECT 1 FROM users WHERE phone = ? LIMIT 1');
    $exists->execute([$phone]);
    if ($exists->fetchColumn() !== false) {
        throw new RuntimeException('مسجل مسبقاً');
    }

    $pdo->beginTransaction();
    try {
        $user = $pdo->prepare(
            'INSERT INTO users (hub_id, role, name, phone, password_hash)
             VALUES (NULL, \'hub_admin\', ?, ?, ?)'
        );
        $user->execute([$name, $phone, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) $pdo->lastInsertId();

        $hub = $pdo->prepare(
            'INSERT INTO hubs (owner_user_id, name, city, phone, status)
             VALUES (?, ?, ?, ?, \'pending\')'
        );
        $hub->execute([$userId, $name, $city, $phone]);
        $hubId = (int) $pdo->lastInsertId();

        // INV-01: hub_id is written server-side only, never taken from the form.
        $bind = $pdo->prepare('UPDATE users SET hub_id = ? WHERE id = ?');
        $bind->execute([$hubId, $userId]);

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    return ['hub_id' => $hubId, 'user_id' => $userId];
}

/**
 * Approve a pending hub and grant its subscription in ONE transaction, so a
 * hub is never 'active' without a valid hub_subscriptions row (INV-03).
 *
 * Only the id coming from the caller is used; no form field can redirect it.
 *
 * @return bool false when the hub is not pending (already approved, or unknown)
 */
function approveHub(int $hubId): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            'UPDATE hubs SET status = \'active\' WHERE id = ? AND status = \'pending\''
        );
        $update->execute([$hubId]);

        if ($update->rowCount() !== 1) {
            $pdo->rollBack();          // already decided: nothing to do
            return false;
        }

        $sub = $pdo->prepare(
            'INSERT INTO hub_subscriptions (hub_id, start_date, end_date)
             VALUES (?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? MONTH))'
        );
        $sub->execute([$hubId, SUBSCRIPTION_MONTHS]);

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    return true;
}

/**
 * Reject or suspend a hub. The subscription row is expired as well, so
 * subscriptionOk() fails closed even if the status is changed back by hand.
 *
 * @param string $status 'rejected' or 'suspended'
 */
function setHubStatus(int $hubId, string $status): bool
{
    if (!in_array($status, ['rejected', 'suspended'], true)) {
        throw new RuntimeException('حالة غير صالحة');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE hubs SET status = ? WHERE id = ?');
        $update->execute([$status, $hubId]);

        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }

        $expire = $pdo->prepare(
            'UPDATE hub_subscriptions SET end_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE hub_id = ?'
        );
        $expire->execute([$hubId]);

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    return true;
}

/**
 * Hubs for the super_admin dashboard, grouped by status with the owner and the
 * current subscription end date. Cross-hub read: super_admin only (R1).
 *
 * @return array<int,array<string,mixed>>
 */
function listHubsForApproval(): array
{
    $stmt = db()->prepare(
        'SELECT h.id, h.name, h.city, h.phone, h.status, h.created_at,
                u.name AS owner_name, u.phone AS owner_phone,
                s.end_date AS subscription_end
           FROM hubs h
           LEFT JOIN users u ON u.id = h.owner_user_id
           LEFT JOIN hub_subscriptions s
                  ON s.hub_id = h.id
                 AND s.end_date = (SELECT MAX(s2.end_date)
                                     FROM hub_subscriptions s2
                                    WHERE s2.hub_id = h.id)
          ORDER BY FIELD(h.status, \'pending\', \'active\', \'suspended\', \'rejected\'),
                   h.created_at DESC'
    );
    $stmt->execute();

    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// INV-03 subscription gate - ONE definition, two callers (PLAN-003 + PLAN-004)
// ---------------------------------------------------------------------------

/**
 * True when the hub is active and its subscription has not expired.
 * Expired / pending / rejected / suspended hubs return false and callers
 * report the exact string 'اشتراك المساحة منتهي'.
 */
function subscriptionOk(int $hubId): bool
{
    $stmt = db()->prepare(
        'SELECT 1
           FROM hub_subscriptions s
           JOIN hubs h ON h.id = s.hub_id
          WHERE s.hub_id = ?
            AND h.status = \'active\'
            AND s.end_date >= CURDATE()
          LIMIT 1'
    );
    $stmt->execute([$hubId]);

    return $stmt->fetchColumn() !== false;
}

// ---------------------------------------------------------------------------
// Flash messages (survive the POST redirect)
// ---------------------------------------------------------------------------

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Read and clear, so a reload never repeats the message. */
function takeFlash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $messages;
}