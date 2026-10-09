<?php
/**
 * Presentation Layer - public hub registration (SPEC-001 R1).
 * POST + CSRF -> registerHub(). No SQL here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$error = '';
$name  = '';
$city  = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim((string) ($_POST['name'] ?? ''));
    $city    = trim((string) ($_POST['city'] ?? ''));
    $phone   = trim((string) ($_POST['phone'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if (!csrfCheck()) {
        $error = 'انتهت صلاحية الجلسة، أعد المحاولة';
    } elseif ($password !== $confirm) {
        $error = 'كلمتا المرور غير متطابقتين';
    } else {
        try {
            $hub = registerHub($name, $city, $phone, $password);
            setFlash('success', 'تم تسجيل المساحة، بانتظار موافقة الإدارة');
            redirect(url('public/login.php'));
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();       // validation + 'مسجل مسبقاً'
        }
    }
}

pageStart('تسجيل مساحة عمل');

?>
<div class="card login-card">
    <div class="card-body">
        <h1 class="h5 mb-1">تسجيل مساحة عمل</h1>
        <p class="text-muted small mb-3">تُحجز المساحة بعد موافقة الإدارة.</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('public/register-hub.php')) ?>">
            <?= csrfField() ?>

            <div class="mb-3">
                <label class="form-label" for="name">اسم المساحة</label>
                <input class="form-control" type="text" id="name" name="name" required
                       value="<?= e($name) ?>">
            </div>

            <div class="mb-3">
                <label class="form-label" for="city">المدينة</label>
                <input class="form-control" type="text" id="city" name="city" required
                       value="<?= e($city) ?>">
            </div>

            <div class="mb-3">
                <label class="form-label" for="phone">رقم الهاتف</label>
                <input class="form-control" type="tel" id="phone" name="phone" required
                       inputmode="tel" value="<?= e($phone) ?>">
            </div>

            <div class="mb-3">
                <label class="form-label" for="password">كلمة المرور</label>
                <input class="form-control" type="password" id="password" name="password"
                       autocomplete="new-password" minlength="8" required>
            </div>

            <div class="mb-3">
                <label class="form-label" for="password_confirm">تأكيد كلمة المرور</label>
                <input class="form-control" type="password" id="password_confirm" name="password_confirm"
                       autocomplete="new-password" minlength="8" required>
            </div>

            <button class="btn btn-primary w-100" type="submit">تسجيل</button>
        </form>
    </div>
</div>
<?php

pageEnd();