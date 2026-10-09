<?php
/**
 * Presentation Layer - the single confirm POST (SPEC-004 R1, step 3 of 3).
 * requireLogin() + CSRF, then createBooking(). No SQL here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$me = requireLogin();

$seatId  = (int) ($_POST['seat_id'] ?? 0);
$date    = trim((string) ($_POST['booking_date'] ?? ''));
$shiftId = (int) ($_POST['shift_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('public/search.php'));
}

if (!csrfCheck()) {
    setFlash('danger', 'انتهت صلاحية الجلسة، أعد المحاولة');
    redirect(url('public/search.php'));
}

try {
    if ($seatId <= 0 || $shiftId <= 0) {
        throw new RuntimeException('بيانات الحجز غير مكتملة');
    }

    // hub_id is derived from the seat row inside createBooking(), never posted.
    createBooking((int) $me['id'], $seatId, $date, $shiftId);

    setFlash('success', 'تم تأكيد الحجز');
    redirect(url('public/my-bookings.php?success=1'));
} catch (RuntimeException $ex) {
    setFlash('danger', $ex->getMessage());

    $back = url('public/search.php');
    if ($seatId > 0) {
        $back = url('public/hub.php?seat_id=' . $seatId);
    }
    redirect($back);
}