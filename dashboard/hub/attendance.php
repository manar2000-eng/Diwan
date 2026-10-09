<?php
/**
 * Presentation Layer - staff check-in + cash record (SPEC-005).
 *
 * Both entry points (typed QR code, per-row button) call the same
 * confirmAndPay(). The amount is never typed here; it comes from the branch price
 * inside the Logic Layer. No QR library, no scanner JS: staff read the 12-char
 * string off the phone, or the OS camera reads the plain text.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/layout.php';

$me     = requireRole(['hub_admin', 'staff']);
$hubId  = sessionHubId();
$staffId = (int) $me['id'];

$date  = trim((string) ($_GET['date'] ?? ''));
$date  = $date === '' ? today() : $date;

if (!isValidDate($date) || $date > today()) {
    setFlash('danger', 'تاريخ غير صالح');
    redirect(url('dashboard/hub/attendance.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qrCode = (string) ($_POST['qr_code'] ?? '');

    if (!csrfCheck()) {
        setFlash('danger', 'انتهت صلاحية الجلسة، أعد المحاولة');
    } else {
        try {
            // A posted amount, if any, is ignored on purpose: the Logic Layer
            // takes the price from branches.price_per_hour.
            $result = confirmAndPay($hubId, $qrCode, $staffId);

            setFlash('success', 'تم تسجيل الحضور والدفعة ' . number_format($result['amount'], 2));
        } catch (RuntimeException $ex) {
            setFlash('danger', $ex->getMessage());
        }
    }

    redirect(url('dashboard/hub/attendance.php?date=' . urlencode($date)));
}

$bookings = listTodaysBookings($hubId, $date);

$statusLabels = [
    'pending'   => ['بانتظار التأكيد', 'secondary'],
    'confirmed' => ['مؤكد', 'info'],
    'attended'  => ['تم الحضور', 'success'],
    'cancelled' => ['ملغي', 'dark'],
];

$totals = ['count' => 0, 'attended' => 0, 'cash' => 0.0, 'unpaid' => 0];
foreach ($bookings as $booking) {
    if ($booking['status'] === 'cancelled') {
        continue;
    }
    $totals['count']++;
    if ($booking['status'] === 'attended') {
        $totals['attended']++;
    }
    if ($booking['payment_id'] !== null) {
        $totals['cash'] += (float) $booking['paid_amount'];
    } else {
        $totals['unpaid']++;
    }
}

pageStart('الحضور والتحصيل');

?>
<h1 class="h5 mb-3">الحضور والتحصيل</h1>

<p class="text-muted small">
    <?= e(formatDateLong($date)) ?> —
    <?= e($me['role'] === 'staff' ? 'موظف استقبال' : 'مدير المساحة') ?>
</p>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card"><div class="card-body py-2">
            <div class="text-muted small">حجوزات</div>
            <div class="fs-5"><?= e($totals['count']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card"><div class="card-body py-2">
            <div class="text-muted small">حضور</div>
            <div class="fs-5"><?= e($totals['attended']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card"><div class="card-body py-2">
            <div class="text-muted small">نقد محصّل</div>
            <div class="fs-5"><?= e(number_format($totals['cash'], 2)) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card"><div class="card-body py-2">
            <div class="text-muted small">لم يُحصّل</div>
            <div class="fs-5"><?= e($totals['unpaid']) ?></div>
        </div></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h6 mb-3">تسجيل برمز الحضور</h2>
        <form method="post" action="<?= e(url('dashboard/hub/attendance.php?date=' . $date)) ?>">
            <?= csrfField() ?>
            <div class="mb-3">
                <label class="form-label" for="qr_code">رمز الحضور</label>
                <input class="form-control" type="text" id="qr_code" name="qr_code"
                       autocomplete="off" autocapitalize="characters" spellcheck="false"
                       maxlength="<?= e(QR_LENGTH) ?>" placeholder="XXXXXXXXXXXX" required>
                <div class="form-text">اكتب الرمز الظاهر على شاشة الزبون، أو امسحه بكاميرا الهاتف.</div>
            </div>
            <button class="btn btn-primary w-100" type="submit">تسجيل الحضور</button>
        </form>
    </div>
</div>

<?php if ($bookings === []): ?>
    <p class="text-muted">لا توجد حجوزات لهذا اليوم.</p>
<?php else: ?>
    <div class="d-flex flex-column gap-3">
        <?php foreach ($bookings as $booking): ?>
            <?php [$label, $badge] = $statusLabels[$booking['status']] ?? [$booking['status'], 'secondary']; ?>
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <h3 class="h6 mb-1">
                                مقعد <?= e($booking['seat_code']) ?>
                                · <?= e($booking['shift_name']) ?>
                            </h3>
                            <div class="text-muted small">
                                <?= e($booking['client_name']) ?> · <?= e($booking['client_phone']) ?>
                            </div>
                            <div class="text-muted small">
                                <?= e(formatTime((string) $booking['start_time'])) ?>
                                –<?= e(formatTime((string) $booking['end_time'])) ?>
                                · <?= e(number_format((float) $booking['price_per_hour'], 2)) ?> / ساعة
                            </div>
                            <div class="mt-1">
                                <code class="fs-6"><?= e($booking['qr_code']) ?></code>
                            </div>
                        </div>
                        <span class="badge text-bg-<?= e($badge) ?>"><?= e($label) ?></span>
                    </div>

                    <div class="d-flex align-items-center justify-content-between gap-2 mt-3">
                        <?php if ($booking['payment_id'] !== null): ?>
                            <span class="text-muted small">
                                دفعة مسجلة: <?= e(number_format((float) $booking['paid_amount'], 2)) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted small">
                                المبلغ: <?= e(number_format((float) $booking['price_per_hour'], 2)) ?>
                            </span>
                        <?php endif; ?>

                        <?php if ($booking['status'] === 'pending' || $booking['status'] === 'confirmed'): ?>
                            <form method="post" action="<?= e(url('dashboard/hub/attendance.php?date=' . $date)) ?>">
                                <?= csrfField() ?>
                                <input type="hidden" name="qr_code" value="<?= e($booking['qr_code']) ?>">
                                <button class="btn btn-sm btn-success" type="submit">تسجيل الحضور</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<p class="text-muted small mt-4">
    <a href="<?= e(url('dashboard/hub/branches.php')) ?>">الفرع</a> ·
    <a href="<?= e(url('dashboard/hub/shifts.php')) ?>">الورديات</a>
</p>
<?php

pageEnd();