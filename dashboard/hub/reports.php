<?php
/**
 * Presentation Layer - hub_admin daily report (SPEC-006 R1).
 *
 * requireRole(['hub_admin']) is where AGENTS.md's "staff cannot access finance
 * reports" rule is actually enforced. No chart library and no export: the report
 * is a small table of numbers, and a chart would be the heaviest asset on the
 * lowest-bandwidth target. No SQL here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/layout.php';

$me    = requireRole(['hub_admin']);
$hubId = sessionHubId();

$date = trim((string) ($_GET['date'] ?? ''));
$date = $date === '' ? today() : $date;

$error  = '';
$report = null;

if (!isValidDate($date)) {
    $error = 'تاريخ غير صالح';
} else {
    try {
        // hub_id comes from the session; a ?hub_id= in the URL is ignored.
        $report = getDailyReport($hubId, $date);
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}

$closures = listClosures($hubId);
$closedToday = [];
foreach ($closures as $closure) {
    if ((string) $closure['close_date'] === $date) {
        $closedToday[] = $closure;
    }
}

pageStart('التقرير اليومي');

?>
<h1 class="h5 mb-3">التقرير اليومي</h1>

<form method="get" action="<?= e(url('dashboard/hub/reports.php')) ?>" class="card mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-12 col-sm-8">
                <label class="form-label" for="date">التاريخ</label>
                <input class="form-control" type="date" id="date" name="date" value="<?= e($date) ?>">
            </div>
            <div class="col-12 col-sm-4 d-flex align-items-end">
                <button class="btn btn-primary w-100" type="submit">عرض</button>
            </div>
        </div>
    </div>
</form>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php else: ?>
    <?php foreach ($closedToday as $closure): ?>
        <div class="alert alert-warning">
            إغلاق طارئ: <?= e($closure['branch_name']) ?> — <?= e($closure['reason']) ?>
        </div>
    <?php endforeach; ?>

    <div class="row g-2 mb-3">
        <?php
        $cards = [
            'إجمالي الحجوزات' => $report['totals']['total'],
            'الحضور'         => $report['totals']['attended'],
            'مؤكدة'          => $report['totals']['confirmed'],
            'قيد الانتظار'    => $report['totals']['pending'],
            'الإلغاءات'       => $report['totals']['cancelled'],
            'النقد المحصل'    => number_format($report['cash_total'], 2),
        ];
        foreach ($cards as $label => $value): ?>
            <div class="col-6 col-md-4">
                <div class="card h-100">
                    <div class="card-body py-2">
                        <div class="text-muted small"><?= e($label) ?></div>
                        <div class="fs-5"><?= e($value) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <h2 class="h6 mb-2">نسبة الإشغال</h2>

    <?php if ($report['branches'] === []): ?>
        <p class="text-muted">لا توجد فروع أو ورديات مسجلة.</p>
    <?php else: ?>
        <?php foreach ($report['branches'] as $entry): ?>
            <?php
            $branch = $entry['branch'];
            $first  = $entry['shifts'][0]['occupancy'] ?? ['denominator' => 0, 'occupancy' => 0.0];
            ?>
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h3 class="h6 mb-0"><?= e($branch['name']) ?></h3>
                        <span class="badge text-bg-secondary">
                            <?= e($first['denominator']) ?> مقعد فعّال
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">الوردية</th>
                                    <th scope="col">التوقيت</th>
                                    <th scope="col">المحجوز</th>
                                    <th scope="col">الإشغال</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entry['shifts'] as $row): ?>
                                    <?php $occ = $row['occupancy']; ?>
                                    <tr>
                                        <td><?= e($row['shift']['name_ar']) ?></td>
                                        <td class="text-nowrap">
                                            <?= e(formatTime((string) $row['shift']['start_time'])) ?>
                                            –<?= e(formatTime((string) $row['shift']['end_time'])) ?>
                                        </td>
                                        <td><?= e($occ['numerator'] . ' / ' . $occ['denominator']) ?></td>
                                        <td><?= e(number_format($occ['occupancy'] * 100, 0)) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mt-4">
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('dashboard/hub/emergency.php')) ?>">الإغلاق الطارئ</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('dashboard/hub/attendance.php')) ?>">الحضور والتحصيل</a>
</div>
<?php

pageEnd();