<?php
/**
 * Presentation Layer - hub_admin seats (SPEC-002 R2).
 * Bulk add: one POST, many seat codes. No SQL here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/layout.php';

$me    = requireRole(['hub_admin']);
$hubId = sessionHubId();

$branch = getBranchForHub($hubId);

if ($branch === null) {
    setFlash('danger', 'أنشئ الفرع أولاً');
    redirect(url('dashboard/hub/branches.php'));
}

$branchId = (int) $branch['id'];
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Codes may arrive as one textarea (one per line) or comma/space separated.
    $raw  = (string) ($_POST['seat_codes'] ?? '');
    $codes = preg_split('/[\r\n,]+|\s+/u', trim($raw)) ?: [];
    $price = (float) str_replace(',', '', (string) ($_POST['price'] ?? '0'));

    if (!csrfCheck()) {
        $error = 'انتهت صلاحية الجلسة، أعد المحاولة';
    } else {
        try {
            $added = createSeats($hubId, $branchId, $codes, $price);
            setFlash('success', 'تمت إضافة ' . $added . ' مقعد');
            redirect(url('dashboard/hub/seats.php'));
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        }
    }
}

$seats     = listSeats($hubId, $branchId);
$activeCount = 0;
foreach ($seats as $seat) {
    if ((int) $seat['active'] === 1) {
        $activeCount++;
    }
}

pageStart('المقاعد');

?>
<h1 class="h5 mb-3">المقاعد</h1>

<p class="text-muted small">
    المقاعد الفعّالة هي أساس نسبة الإشغال التي تظهر للزبائن.
</p>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('dashboard/hub/branches.php')) ?>">الفرع</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('dashboard/hub/shifts.php')) ?>">الورديات</a>
    <span class="btn btn-sm btn-light">مقاعد فعّالة: <?= e($activeCount) ?></span>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h6 mb-3">إضافة مقاعد</h2>
        <form method="post">
            <?= csrfField() ?>

            <div class="mb-3">
                <label class="form-label" for="seat_codes">أكواد المقاعد</label>
                <textarea class="form-control" id="seat_codes" name="seat_codes" rows="5"
                          placeholder="A-01&#10;A-02&#10;A-03"></textarea>
                <div class="form-text">كود لكل سطر (أو مفصول بمسافة/فاصلة).</div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="price">سعر المقعد للساعة</label>
                <input class="form-control" type="number" id="price" name="price" step="0.5" min="0"
                       inputmode="decimal" value="<?= e($branch['price_per_hour']) ?>">
            </div>

            <button class="btn btn-primary" type="submit">إضافة</button>
        </form>
    </div>
</div>

<?php if ($seats === []): ?>
    <p class="text-muted">لا توجد مقاعد بعد.</p>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <ul class="list-group list-group-flush">
                <?php foreach ($seats as $seat): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?= e($seat['seat_code']) ?></span>
                        <span class="text-muted small">
                            <?= e($seat['price']) ?>
                            <?= (int) $seat['active'] === 1 ? '' : '· معطّل' ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>
<?php

pageEnd();