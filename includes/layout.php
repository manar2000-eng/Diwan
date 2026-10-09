<?php
/**
 * Presentation Layer - the one shared page shell (INV-06).
 * Emits <html lang="ar" dir="rtl"> + Bootstrap 5 RTL CDN + assets/css/style.css
 * once, so no later page repeats the RTL markup.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function pageStart(string $title, string $bodyClass = ''): void
{
    $user  = currentUser();
    $flash = takeFlash();

    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | ديوان</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="diwan-body <?= e($bodyClass) ?>">

<header class="diwan-header bg-white border-bottom">
    <div class="container d-flex align-items-center justify-content-between py-2">
        <a class="diwan-brand" href="<?= e(url('public/search.php')) ?>">ديوان</a>
        <nav class="d-flex align-items-center gap-2">
            <?php if ($user !== null): ?>
                <?php if ($user['role'] === 'super_admin'): ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('dashboard/super/hubs.php')) ?>">إدارة المساحات</a>
                <?php endif; ?>
                <span class="text-muted small"><?= e($user['name']) ?></span>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('public/logout.php')) ?>">خروج</a>
            <?php else: ?>
                <a class="btn btn-sm btn-primary" href="<?= e(url('public/login.php')) ?>">دخول</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="container py-3">
    <?php foreach ($flash as $message): ?>
        <div class="alert alert-<?= e($message['type']) ?>"><?= e($message['message']) ?></div>
    <?php endforeach; ?>
    <?php
}

function pageEnd(): void
{
    ?>
</main>

<footer class="diwan-footer text-center text-muted small py-3">ديوان · مساحات عمل مشتركة</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" referrerpolicy="no-referrer"></script>
</body>
</html>
    <?php
}