<?php

use App\Core\View;
?><!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? 'Shared file') . ' — ' . ($siteName ?? 'S3 Lite')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <style>:root { --accent: <?= e($accentColor ?? '#4f7cff') ?>; }</style>
</head>
<body>
<?= View::include('partials.icons') ?>

<div class="public">
    <header class="public__bar">
        <span class="sidebar__logo"><?= icon('cloud') ?></span>
        <span class="strong"><?= e($siteName ?? 'S3 Lite') ?></span>
        <span class="topbar__spacer"></span>
        <button class="btn btn-ghost btn-icon" type="button" data-theme-toggle aria-label="Toggle theme">
            <span data-theme-icon><?= icon('sun') ?></span>
        </button>
    </header>

    <main class="public__main">
        <div class="public__card">
            <?= View::include('partials.flash', ['flash' => $flash ?? [], 'errors' => $errors ?? []]) ?>
            <?= View::section('content') ?>
        </div>
    </main>

    <footer class="public__foot">
        Shared securely via <?= e($siteName ?? 'S3 Lite') ?>
    </footer>
</div>

<div class="toasts"></div>
<script>window.S3_CONFIG = <?= json_encode(['baseUrl' => url('')], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?= View::section('scripts') ?>
</body>
</html>
