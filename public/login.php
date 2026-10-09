<?php
/**
 * Presentation Layer - one login form for all 4 roles (single users table).
 * Spec: SPEC-000-shared.md R2.
 * No SQL here: the lookup goes through the Data Layer PDO singleton.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';   // pulls auth.php + functions.php with it

if (isLoggedIn()) {
    redirect(homeFor(currentUser()['role']));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $error = 'انتهت صلاحية الجلسة، أعد المحاولة';
    } else {
        $phone    = trim((string) ($_POST['phone'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $user = findUserForLogin($phone);

        if ($user !== null && (int) $user['active'] === 1 && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);          // new id on privilege change

            $_SESSION['user'] = [
                'id'     => (int) $user['id'],
                'name'   => $user['name'],
                'role'   => $user['role'],
                'hub_id' => $user['hub_id'] === null ? null : (int) $user['hub_id'],   // INV-01: from DB, never POST
            ];

            redirect(homeFor($user['role']));
        }

        $error = 'رقم الهاتف أو كلمة المرور غير صحيحة';
    }
}

pageStart('تسجيل الدخول');

?>
<div class="card login-card">
    <div class="card-body">
        <h1 class="h5 mb-3">تسجيل الدخول</h1>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('public/login.php')) ?>">
            <?= csrfField() ?>

            <div class="mb-3">
                <label class="form-label" for="phone">رقم الهاتف</label>
                <input class="form-control" type="tel" id="phone" name="phone"
                       inputmode="tel" autocomplete="username" required
                       value="<?= e($_POST['phone'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label" for="password">كلمة المرور</label>
                <input class="form-control" type="password" id="password" name="password"
                       autocomplete="current-password" required>
            </div>

            <button class="btn btn-primary w-100" type="submit">دخول</button>
        </form>
    </div>
</div>
<?php

pageEnd();