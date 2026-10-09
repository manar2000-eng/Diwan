<?php
/**
 * Presentation Layer - seat picker for one branch (SPEC-004 R1, step 2 of 3).
 * GET only. One form, one <select> of free seats. No JS, no SQL here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$hubId   = (int) ($_GET['hub_id'] ?? 0);
$branchId = (int) ($_GET['branch_id'] ?? 0);
$shiftId = (int) ($_GET['shift_id'] ?? 0);
$date    = trim((string) ($_GET['date'] ?? ''));

$date = $date === '' ? today() : $date;

$error = '';

if ($hubId <= 0 || $branchId <= 0) {
    $error = 'مساحة غير صالحة';
} elseif (!isValidDate($date)) {
    $error = 'تاريخ غير صالح';
} elseif ($date < today()) {
    $error = 'تاريخ غير صالح';
}

// hub_id must agree with the branch, so one branch cannot pose as another hub.
if ($error === '') {
    $branch = getBookableBranch($hubId, $branchId);

    if ($branch === null) {
        $error = 'الفرع غير موجود';
    }
}

if ($error === '') {
    // INV-03 gate on the booking page, not only on the card
    if ((string) $branch['status'] !== 'active' || !subscriptionOk($hubId)) {
        $error = 'اشتراك المساحة منتهي';
    }
}

$shifts = [];
$seats  = [];
if ($error === '') {
    $shifts = listBranchShifts($branchId);

    if ($shiftId <= 0 && $shifts !== []) {
        $shiftId = (int) $shifts[0]['id'];
    }

    if ($shiftId > 0 && !branchOwnsShift($branchId, $shiftId)) {
        $error = 'الوردية غير متاحة';
    } else {
        $seats = getFreeSeats($branchId, $date, $shiftId);
    }
}

pageStart('اختيار المقعد');

if ($error !== ''): ?>
    <h1 class="h5 mb-3"><?= e($error) ?></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('public/search.php')) ?>">رجوع للبحث</a>
    <?php
pageEnd();
    exit;
endif;

$selectedShift = null;
foreach ($shifts as $s) {
    if ((int) $s['id'] === $shiftId) {
        $selectedShift = $s;
        break;
    }
}

?>
<h1 class="h5 mb-3"><?= e($branch['hub_name']) ?></h1>
<p class="text-muted small">
    <?= e($branch['city']) ?> · <?= e(formatTime((string) $selectedShift['start_time'])) ?>
    – <?= e(formatTime((string) $selectedShift['end_time'])) ?>
    · <?= e($selectedShift['name_ar']) ?>
    · <?= e(formatDateLong($date)) ?>
</p>

<form method="get" action="<?= e(url('public/hub.php')) ?>" class="card mb-3">
    <input type="hidden" name="hub_id" value="<?= e($hubId) ?>">
    <input type="hidden" name="branch_id" value="<?= e($branchId) ?>">

    <div class="card-body">
        <div class="row g-2">
            <div class="col-12 col-sm-6">
                <label class="form-label" for="date">التاريخ</label>
                <input class="form-control" type="date" id="date" name="date"
                       min="<?= e(today()) ?>" value="<?= e($date) ?>">
            </div>
            <div class="col-12 col-sm-6">
                <label class="form-label" for="shift_id">الوردية</label>
                <select class="form-select" id="shift_id" name="shift_id">
                    <?php foreach ($shifts as $s): ?>
                        <option value="<?= e($s['id']) ?>"<?= $shiftId === (int) $s['id'] ? ' selected' : '' ?>>
                            <?= e($s['name_ar'] . ' ' . formatTime((string) $s['start_time']) . '-' . formatTime((string) $s['end_time'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button class="btn btn-outline-primary btn-sm mt-3" type="submit">تحديث</button>
    </div>
</form>

<?php if ($seats === []): ?>
    <p class="text-muted">لا توجد مقاعد متاحة في هذه الوردية.</p>
<?php else: ?>
    <form method="post" action="<?= e(url('public/booking.php')) ?>">
        <?= csrfField() ?>
        <input type="hidden" name="booking_date" value="<?= e($date) ?>">
        <input type="hidden" name="shift_id" value="<?= e($shiftId) ?>">

        <div class="card mb-3">
            <div class="card-body">
                <label class="form-label" for="seat_id">المقعد</label>
                <select class="form-select" id="seat_id" name="seat_id" required>
                    <option value="">اختر مقعداً</option>
                    <?php foreach ($seats as $seat): ?>
                        <option value="<?= e($seat['id']) ?>">
                            <?= e($seat['seat_code'] . ' · ' . $seat['price']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button class="btn btn-primary w-100" type="submit">تأكيد الحجز</button>
    </form>
<?php endif; ?>

<p class="text-muted small mt-4">
    <a href="<?= e(url('public/search.php')) ?>">رجوع للبحث</a>
</p>
<?php

pageEnd();