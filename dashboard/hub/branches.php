<?php
/**
 * Presentation Layer - hub_admin branch setup (SPEC-002 R1).
 * MVP keeps ONE branch, so the same page creates it and then edits it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/layout.php';

$me     = requireRole(['hub_admin']);
$hubId  = sessionHubId();

$options = [
    'electricity_status' => ['none' => 'غير متوفر', 'grid' => 'كهرباء شبكة', 'generator' => 'مولد كهرباء', 'solar' => 'طاقة شمسية'],
    'internet_strength'  => ['none' => 'غير متوفر', 'weak' => 'ضعيف', 'good' => 'جيد', 'excellent' => 'ممتاز'],
    'power_source'       => ['none' => 'غير متوفر', 'generator' => 'مولد كهرباء', 'solar' => 'طاقة شمسية'],
];

$branch = getBranchForHub($hubId);
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $city        = trim((string) ($_POST['city'] ?? ''));
    $address     = trim((string) ($_POST['address'] ?? ''));
    $electricity = (string) ($_POST['electricity_status'] ?? 'none');
    $internet    = (string) ($_POST['internet_strength'] ?? 'none');
    $power       = (string) ($_POST['power_source'] ?? 'none');
    $price       = (float) str_replace(',', '', (string) ($_POST['price_per_hour'] ?? '0'));

    if (!csrfCheck()) {
        $error = 'انتهت صلاحية الجلسة، أعد المحاولة';
    } elseif (!isset($options['electricity_status'][$electricity])
        || !isset($options['internet_strength'][$internet])
        || !isset($options['power_source'][$power])) {
        $error = 'قيمة غير صالحة';
    } else {
        try {
            if ($branch === null) {
                createBranch($hubId, $city, $address, $electricity, $internet, $power, $price);
                setFlash('success', 'تم إنشاء الفرع');
            } else {
                updateBranch($hubId, $city, $address, $electricity, $internet, $power, $price);
                setFlash('success', 'تم تحديث الفرع');
            }
            redirect(url('dashboard/hub/branches.php'));
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
            $branch = getBranchForHub($hubId);
        }
    }
}

$branch = getBranchForHub($hubId);

pageStart('الفرع');

?>
<h1 class="h5 mb-3">بيانات الفرع</h1>

<p class="text-muted small">
    هذه البيانات تظهر في بطاقة المساحة لزبائن البحث.
</p>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>

            <div class="mb-3">
                <label class="form-label" for="city">المدينة</label>
                <input class="form-control" type="text" id="city" name="city" required
                       value="<?= e($branch['city'] ?? $_POST['city'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label" for="address">العنوان</label>
                <input class="form-control" type="text" id="address" name="address"
                       value="<?= e($branch['address'] ?? $_POST['address'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label" for="price_per_hour">السعر للساعة</label>
                <input class="form-control" type="number" id="price_per_hour" name="price_per_hour"
                       step="0.5" min="0" inputmode="decimal"
                       value="<?= e($branch['price_per_hour'] ?? $_POST['price_per_hour'] ?? '0') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label" for="electricity_status">حالة الكهرباء</label>
                <select class="form-select" id="electricity_status" name="electricity_status">
                    <?php $cur = (string) ($branch['electricity_status'] ?? $_POST['electricity_status'] ?? 'none'); ?>
                    <?php foreach ($options['electricity_status'] as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $cur === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label" for="internet_strength">قوة الإنترنت</label>
                <select class="form-select" id="internet_strength" name="internet_strength">
                    <?php $cur = (string) ($branch['internet_strength'] ?? $_POST['internet_strength'] ?? 'none'); ?>
                    <?php foreach ($options['internet_strength'] as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $cur === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label" for="power_source">مولد / طاقة شمسية</label>
                <select class="form-select" id="power_source" name="power_source">
                    <?php $cur = (string) ($branch['power_source'] ?? $_POST['power_source'] ?? 'none'); ?>
                    <?php foreach ($options['power_source'] as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $cur === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit"><?= $branch === null ? 'إنشاء الفرع' : 'حفظ التعديل' ?></button>
                <?php if ($branch !== null): ?>
                    <a class="btn btn-outline-secondary" href="<?= e(url('dashboard/hub/seats.php')) ?>">المقاعد</a>
                    <a class="btn btn-outline-secondary" href="<?= e(url('dashboard/hub/shifts.php')) ?>">الورديات</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php

pageEnd();