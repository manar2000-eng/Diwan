<?php
/**
 * Presentation Layer - hub_admin emergency closure (SPEC-006 R2).
 *
 * Closing a date does NOT touch existing bookings: they stay and stay flagged.
 * PLAN-003 hides the branch from search and PLAN-004 refuses new bookings for
 * that date. No SQL here.
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
    $action = (string) ($_POST['action'] ?? 'add');

    if (!csrfCheck()) {
        $error = 'انتهت صلاحية الجلسة، أعد المحاولة';
    } else {
        try {
            if ($action === 'remove') {
                removeEmergencyClose($hubId, (int) ($_POST['closure_id'] ?? 0));
                setFlash('success', 'تم إزالة الإغلاق');
            } else {
                $date = trim((string) ($_POST['close_date'] ?? ''));
                addEmergencyClose(
                    $hubId,
                    $branchId,
                    $date,
                    (string) ($_POST['reason'] ?? '')
                );
                setFlash('success', 'تم تسجيل الإغلاق الطارئ');
            }
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        }
    }

    if ($error !== '') {
        setFlash('danger', $error);
    }
    redirect(url('dashboard/hub/emergency.php'));
}

$closures = listClosures($hubId);

pageStart('الإغلاق الطارئ');

?>
<h1 class="h5 mb-3">الإغلاق الطارئ</h1>

<p class="text-muted small">
    يمنع البحث والحجز في التاريخ المحدد. الحجوزات الموجودة تبقى كما هي.
</p>

<div class="card mb-3">
    <div class="card-body">
        <form method="post" action="<?= e(url('dashboard/hub/emergency.php')) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">

            <div class="mb-3">
                <label class="form-label" for="close_date">تاريخ الإغلاق</label>
                <input class="form-control" type="date" id="close_date" name="close_date"
                       min="<?= e(today()) ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label" for="reason">السبب</label>
                <input class="form-control" type="text" id="reason" name="reason"
                       maxlength="200" placeholder="عاصفة / انقطاع الكهرباء" required>
            </div>

            <button class="btn btn-warning w-100" type="submit">تسجيل الإغلاق</button>
        </form>
    </div>
</div>

<h2 class="h6 mb-2">الإغلاقات المسجلة</h2>

<?php if ($closures === []): ?>
    <p class="text-muted">لا توجد إغلاقات مسجلة.</p>
<?php else: ?>
    <div class="d-flex flex-column gap-2">
        <?php foreach ($closures as $closure): ?>
            <?php $past = (string) $closure['close_date'] < today(); ?>
            <div class="card">
                <div class="card-body py-2 d-flex justify-content-between align-items-center gap-2">
                    <div>
                        <div><?= e(formatDateLong((string) $closure['close_date'])) ?></div>
                        <div class="text-muted small">
                            <?= e($closure['branch_name']) ?> · <?= e($closure['reason']) ?>
                        </div>
                    </div>
                    <?php if ($past): ?>
                        <span class="badge text-bg-secondary">منتهٍ</span>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('dashboard/hub/emergency.php')) ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="closure_id" value="<?= e($closure['id']) ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit">إزالة</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mt-4">
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('dashboard/hub/reports.php')) ?>">التقرير اليومي</a>
</div>
<?php

pageEnd();