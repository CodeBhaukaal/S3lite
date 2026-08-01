<?php

use App\Core\View;
?><!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? 'Sign in') . ' — ' . ($siteName ?? 'S3 Lite')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <style>:root { --accent: <?= e($accentColor ?? '#4f7cff') ?>; }</style>
</head>
<body>
<?= View::include('partials.icons') ?>

<div class="auth">
    <div class="auth__card">
        <div class="auth__brand">
            <span class="auth__brand-logo"><?= icon('cloud', 'icon icon-lg') ?></span>
            <span class="auth__brand-name"><?= e($siteName ?? 'S3 Lite') ?></span>
        </div>

        <div class="auth__box">
            <?= View::include('partials.flash', ['flash' => $flash ?? [], 'errors' => $errors ?? []]) ?>
            <?= View::section('content') ?>
        </div>

        <div class="auth__foot">
            <?= View::section('footer', 'Self-hosted object storage · v' . e($appVersion ?? '1.0.0')) ?>
        </div>
    </div>
</div>

<div class="toasts"></div>
<script>window.S3_CONFIG = <?= json_encode(['baseUrl' => url('')], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?= View::section('scripts') ?>
</body>
</html>
