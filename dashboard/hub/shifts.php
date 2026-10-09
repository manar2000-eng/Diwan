<?php
/**
 * Presentation Layer - hub_admin shifts (SPEC-002 R3).
 * MVP adds the pair together (>= 2 active shifts per branch). No SQL here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/layout.php';

$me     = requireRole(['hub_admin']);
$hubId  = sessionHubId();

$branch = getBranchForHub($hubId);

if ($branch === null) {
    setFlash('danger', 'أنشئ الفرع أولاً');
    redirect(url('dashboard/hub/branches.php'));
}

$branchId = (int) $branch['id'];
$error    = '';
$editing  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');

    if (!csrfCheck()) {
        $error = 'انتهت صلاحية الجلسة، أعد المحاولة';
    } else {
        try {
            if ($action === 'update') {
                updateShift(
                    $hubId,
                    (int) ($_POST['shift_id'] ?? 0),
                    trim((string) ($_POST['name_ar'] ?? '')),
                    (string) ($_POST['start_time'] ?? ''),
                    (string) ($_POST['end_time'] ?? '')
                );
                setFlash('success', 'تم تحديث الوردية');
                redirect(url('dashboard/hub/shifts.php'));
            }

            $added = createShifts($hubId, $branchId, [
                ['name' => (string) ($_POST['name_1'] ?? ''), 'start' => (string) ($_POST['start_1'] ?? ''), 'end' => (string) ($_POST['end_1'] ?? '')],
                ['name' => (string) ($_POST['name_2'] ?? ''), 'start' => (string) ($_POST['start_2'] ?? ''), 'end' => (string) ($_POST['end_2'] ?? '')],
            ]);
            setFlash('success', 'تمت إضافة ' . count($added) . ' ورديات');
            redirect(url('dashboard/hub/shifts.php'));
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        }
    }
}

if (isset($_GET['edit']) && !isset($_POST['action'])) {
    $candidate = (int) $_GET['edit'];
    foreach (listShifts($hubId, $branchId) as $shift) {
        if ((int) $shift['id'] === $candidate) {
            $editing = $shift;
            break;
        }
    }
}

$shifts = listShifts($hubId, $branchId);

pageStart('الورديات');

?>
<h1 class="h5 mb-3">الورديات</h1>

<p class="text-muted small">
    يحتاج كل فرع ورديتين على الأقل، ولا تتداخل الورديات في نفس الفرع.
</p>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('dashboard/hub/branches.php')) ?>">الفرع</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('dashboard/hub/seats.php')) ?>">المقاعد</a>
    <span class="btn btn-sm btn-light">ورديات فعّالة: <?= e(count($shifts)) ?></span>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h6 mb-3"><?= $editing === null ? 'إضافة ورديتين' : 'تعديل الوردية' ?></h2>
        <form method="post" action="<?= e(url('dashboard/hub/shifts.php')) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editing === null ? 'create' : 'update' ?>">

            <?php if ($editing !== null): ?>
                <input type="hidden" name="shift_id" value="<?= e($editing['id']) ?>">
                <div class="mb-3">
                    <label class="form-label" for="name_ar">اسم الوردية</label>
                    <input class="form-control" type="text" id="name_ar" name="name_ar" required
                           value="<?= e($editing['name_ar']) ?>">
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label" for="start_time">من</label>
                        <input class="form-control" type="time" id="start_time" name="start_time" required
                               value="<?= e(formatTime((string) $editing['start_time'])) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="end_time">إلى</label>
                        <input class="form-control" type="time" id="end_time" name="end_time" required
                               value="<?= e(formatTime((string) $editing['end_time'])) ?>">
                    </div>
                </div>
            <?php else: ?>
                <?php for ($i = 1; $i <= 2; $i++): ?>
                    <fieldset class="mb-3">
                        <legend class="h6">الوردية <?= e($i) ?></legend>
                        <div class="mb-2">
                            <label class="form-label" for="name_<?= e($i) ?>">الاسم</label>
                            <input class="form-control" type="text" id="name_<?= e($i) ?>" name="name_<?= e($i) ?>">
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label" for="start_<?= e($i) ?>">من</label>
                                <input class="form-control" type="time" id="start_<?= e($i) ?>" name="start_<?= e($i) ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="end_<?= e($i) ?>">إلى</label>
                                <input class="form-control" type="time" id="end_<?= e($i) ?>" name="end_<?= e($i) ?>" required>
                            </div>
                        </div>
                    </fieldset>
                <?php endfor; ?>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit"><?= $editing === null ? 'إضافة' : 'حفظ' ?></button>
                <?php if ($editing !== null): ?>
                    <a class="btn btn-outline-secondary" href="<?= e(url('dashboard/hub/shifts.php')) ?>">إلغاء</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($shifts === []): ?>
    <p class="text-muted">لا توجد ورديات بعد.</p>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <ul class="list-group list-group-flush">
                <?php foreach ($shifts as $shift): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span>
                            <?= e($shift['name_ar']) ?>
                            <span class="text-muted small">
                                <?= e(formatTime((string) $shift['start_time'])) ?>
                                –
                                <?= e(formatTime((string) $shift['end_time'])) ?>
                            </span>
                        </span>
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= e(url('dashboard/hub/shifts.php?edit=' . $shift['id'])) ?>">تعديل</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>
<?php

pageEnd();