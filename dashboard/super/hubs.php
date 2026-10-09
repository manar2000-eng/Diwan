<?php
/**
 * Presentation Layer - super_admin hub approval queue (SPEC-001 R2/R3).
 * Approve / Reject / Suspend are POST + CSRF; the row acted on comes from the
 * POST id and is re-validated server-side, never trusted blind.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/layout.php';

$me = requireRole(['super_admin']);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $hubId  = (int) ($_POST['hub_id'] ?? 0);

    if (!csrfCheck()) {
        $error = 'انتهت صلاحية الجلسة، أعد المحاولة';
    } elseif ($hubId <= 0) {
        $error = 'مساحة غير صالحة';
    } else {
        try {
            switch ($action) {
                case 'approve':
                    $error = approveHub($hubId)
                        ? ''
                        : 'المساحة غير موجودة أو تمت مراجعتها مسبقاً';
                    if ($error === '') {
                        setFlash('success', 'تمت الموافقة على المساحة وتفعيل الاشتراك');
                    }
                    break;

                case 'reject':
                    setHubStatus($hubId, 'rejected');
                    setFlash('success', 'تم رفض المساحة');
                    break;

                case 'suspend':
                    setHubStatus($hubId, 'suspended');
                    setFlash('success', 'تم إيقاف المساحة');
                    break;

                default:
                    $error = 'إجراء غير معروف';
            }
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        }
    }

    // POST-redirect-GET so a refresh never re-approves.
    if ($error !== '') {
        setFlash('danger', $error);
    }
    redirect(url('dashboard/super/hubs.php'));
}

$hubs = listHubsForApproval();

$statusLabels = [
    'pending'   => ['بانتظار الموافقة', 'secondary'],
    'active'    => ['نشط', 'success'],
    'rejected'  => ['مرفوض', 'dark'],
    'suspended' => ['موقوف', 'warning'],
];

pageStart('إدارة المساحات');

?>
<h1 class="h5 mb-3">إدارة المساحات</h1>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('dashboard/super/index.php')) ?>">رجوع</a>
</div>

<?php if ($hubs === []): ?>
    <p class="text-muted">لا توجد مساحات مسجلة بعد.</p>
<?php endif; ?>

<div class="d-flex flex-column gap-3">
    <?php foreach ($hubs as $hub): ?>
        <?php [$label, $badge] = $statusLabels[$hub['status']] ?? [$hub['status'], 'secondary']; ?>
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <h2 class="h6 mb-1"><?= e($hub['name']) ?></h2>
                        <div class="text-muted small">
                            <?= e($hub['city']) ?> · <?= e($hub['phone']) ?>
                        </div>
                        <div class="text-muted small">
                            المالك: <?= e($hub['owner_name'] ?? '-') ?>
                            (<?= e($hub['owner_phone'] ?? '-') ?>)
                        </div>
                        <div class="text-muted small">
                            سجّل: <?= e(substr((string) $hub['created_at'], 0, 10)) ?>
                            · اشتراك حتى:
                            <?= $hub['subscription_end'] === null ? '-' : e($hub['subscription_end']) ?>
                        </div>
                    </div>
                    <span class="badge text-bg-<?= e($badge) ?>"><?= e($label) ?></span>
                </div>

                <form method="post" class="d-flex flex-wrap gap-2 mt-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="hub_id" value="<?= e($hub['id']) ?>">

                    <?php if ($hub['status'] === 'pending'): ?>
                        <button class="btn btn-sm btn-success" type="submit" name="action" value="approve">موافقة</button>
                        <button class="btn btn-sm btn-outline-dark" type="submit" name="action" value="reject">رفض</button>
                    <?php elseif ($hub['status'] === 'active'): ?>
                        <button class="btn btn-sm btn-outline-warning" type="submit" name="action" value="suspend">إيقاف</button>
                    <?php else: ?>
                        <span class="text-muted small align-self-center">لا إجراء متاح</span>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php

pageEnd();