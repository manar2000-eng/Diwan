<?php
/**
 * Presentation Layer - public hub search (SPEC-003).
 * Plain GET form: city, date, shift. No JS, no AJAX, no login. No SQL here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$labels = searchOptionLabels();

$city    = trim((string) ($_GET['city'] ?? ''));
$dateIn  = trim((string) ($_GET['date'] ?? ''));
$shiftId = (int) ($_GET['shift_id'] ?? 0);

$date    = $dateIn === '' ? today() : $dateIn;
$error   = '';
$results = ['open' => [], 'closed' => []];

try {
    $results = searchHubs($city, $date, $shiftId);
} catch (RuntimeException $ex) {
    $error = $ex->getMessage();
}

$cities = listCities();
$shifts = listSearchShifts($error === '' ? $city : '');

pageStart('ابحث عن مساحة');

?>
<h1 class="h5 mb-3">ابحث عن مساحة عمل</h1>

<form method="get" action="<?= e(url('public/search.php')) ?>" class="card mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-12 col-md-4">
                <label class="form-label" for="city">المدينة</label>
                <select class="form-select" id="city" name="city">
                    <option value="">كل المدن</option>
                    <?php foreach ($cities as $option): ?>
                        <option value="<?= e($option) ?>"<?= $city === $option ? ' selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-md-4">
                <label class="form-label" for="date">التاريخ</label>
                <input class="form-control" type="date" id="date" name="date"
                       min="<?= e(today()) ?>" value="<?= e($date) ?>">
            </div>

            <div class="col-12 col-md-4">
                <label class="form-label" for="shift_id">الوردية</label>
                <select class="form-select" id="shift_id" name="shift_id">
                    <option value="">كل الورديات</option>
                    <?php foreach ($shifts as $shift): ?>
                        <option value="<?= e($shift['id']) ?>"<?= $shiftId === (int) $shift['id'] ? ' selected' : '' ?>>
                            <?= e($shift['city'] . ' - ' . $shift['name_ar'] . ' ' . formatTime((string) $shift['start_time']) . '-' . formatTime((string) $shift['end_time'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button class="btn btn-primary mt-3" type="submit">بحث</button>
    </div>
</form>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($error === ''): ?>
    <?php if ($results['open'] === [] && $results['closed'] === []): ?>
        <p class="text-muted">لا توجد مساحات مطابقة.</p>
    <?php endif; ?>

    <div class="d-flex flex-column gap-3">
        <?php foreach ($results['open'] as $hub): ?>
            <?php $availability = $hub['availability']; ?>
            <div class="card">
                <div class="card-body">
                    <div class="hub-photo-placeholder" aria-hidden="true"></div>
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <h2 class="h6 mb-1"><?= e($hub['hub_name']) ?></h2>
                            <div class="text-muted small"><?= e($hub['city']) ?></div>
                        </div>
                        <span class="badge text-bg-<?= $availability['available'] > 0 ? 'success' : 'secondary' ?>">
                            <?= $availability['available'] > 0
                                ? e($availability['available'] . ' متاح')
                                : 'مكتمل' ?>
                        </span>
                    </div>

                    <ul class="list-unstyled hub-card-meta text-muted mt-2 mb-0">
                        <li>كهرباء: <?= e($labels['electricity_status'][$hub['electricity_status']] ?? $hub['electricity_status']) ?></li>
                        <li>إنترنت: <?= e($labels['internet_strength'][$hub['internet_strength']] ?? $hub['internet_strength']) ?></li>
                        <li>مولد/شمس: <?= e($labels['power_source'][$hub['power_source']] ?? $hub['power_source']) ?></li>
                        <li>السعر: <?= e(number_format($hub['price_per_hour'], 2)) ?> / ساعة</li>
                    </ul>

                    <?php if ($availability['available'] > 0): ?>
                        <a class="btn btn-primary btn-sm mt-3"
                           href="<?= e(url('public/hub.php?hub_id=' . $hub['hub_id'] . '&branch_id=' . $hub['branch_id'] . '&date=' . $date . '&shift_id=' . $shiftId)) ?>">احجز</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php foreach ($results['closed'] as $hub): ?>
            <div class="card border-warning">
                <div class="card-body">
                    <div class="hub-photo-placeholder" aria-hidden="true"></div>
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <h2 class="h6 mb-1"><?= e($hub['hub_name']) ?></h2>
                            <div class="text-muted small"><?= e($hub['city']) ?></div>
                        </div>
                        <span class="badge text-bg-warning">مغلق طارئ</span>
                    </div>
                    <div class="text-muted small mt-2">لا يمكن الحجز في هذا اليوم.</div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<p class="text-muted small mt-4">
    للمشاركة بالكلمة <a href="<?= e(url('public/register-hub.php')) ?>">سجّل مساحتك</a>
    أو <a href="<?= e(url('public/login.php')) ?>">سجّل الدخول</a>.
</p>
<?php

pageEnd();