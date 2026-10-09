<?php
/**
 * Presentation Layer - the client's own bookings (SPEC-004 R2).
 * List + QR string + Cancel (POST + CSRF). No SQL here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$me = requireLogin();
$clientId = (int) $me['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingId = (int) ($_POST['booking_id'] ?? 0);

    if (!csrfCheck()) {
        setFlash('danger', 'انتهت صلاحية الجلسة، أعد المحاولة');
    } else {
        try {
            // Own booking only: client_id is part of the WHERE clause, so another
            // client's id matches nothing and the page reports 403.
            if (!cancelBooking($bookingId, $clientId)) {
                http_response_code(403);
                setFlash('danger', 'الحجز غير موجود');
            } else {
                setFlash('success', 'تم إلغاء الحجز');
            }
        } catch (RuntimeException $ex) {
            setFlash('danger', $ex->getMessage());   // 'لا يمكن إلغاء حضور مؤكد'
        }
    }

    redirect(url('public/my-bookings.php'));
}

$bookings = listMyBookings($clientId);

$statusLabels = [
    'pending'   => 'بانتظار التأكيد',
    'confirmed' => 'مؤكد',
    'attended'  => 'تم الحضور',
    'cancelled' => 'ملغي',
];

pageStart('حجوزاتي');

?>
<h1 class="h5 mb-3">حجوزاتي</h1>

<?php if ($bookings === []): ?>
    <p class="text-muted">لا توجد حجوزات بعد.</p>
    <a class="btn btn-primary" href="<?= e(url('public/search.php')) ?>">ابحث عن مساحة</a>
<?php else: ?>
    <div class="d-flex flex-column gap-3">
        <?php foreach ($bookings as $booking): ?>
            <?php $label = $statusLabels[$booking['status']] ?? $booking['status']; ?>
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <h2 class="h6 mb-1"><?= e($booking['hub_name']) ?></h2>
                            <div class="text-muted small">
                                <?= e($booking['city']) ?> · مقعد <?= e($booking['seat_code']) ?>
                                · <?= e($booking['shift_name']) ?>
                                (<?= e(formatTime((string) $booking['start_time'])) ?>
                                –<?= e(formatTime((string) $booking['end_time'])) ?>)
                            </div>
                            <div class="text-muted small"><?= e(formatDateLong((string) $booking['booking_date'])) ?></div>
                        </div>
                        <span class="badge text-bg-<?= $booking['status'] === 'cancelled' ? 'secondary' : 'success' ?>">
                            <?= e($label) ?>
                        </span>
                    </div>

                    <div class="mt-2">
                        <span class="text-muted small">رمز الحضور: </span>
                        <code class="fs-5"><?= e($booking['qr_code']) ?></code>
                    </div>

                    <?php if ($booking['status'] === 'pending' || $booking['status'] === 'confirmed'): ?>
                        <form method="post" class="mt-3">
                            <?= csrfField() ?>
                            <input type="hidden" name="booking_id" value="<?= e($booking['id']) ?>">
                            <button class="btn btn-outline-danger btn-sm" type="submit">إلغاء الحجز</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<p class="text-muted small mt-4">
    <a href="<?= e(url('public/search.php')) ?>">ابحث عن مساحة أخرى</a>
</p>
<?php

pageEnd();