<?php

use App\Core\View;

/** @var int $status */
/** @var string $message */
/** @var string $title */
/** @var \Throwable|null $debug */

$iconName = match ($status) {
    403     => 'lock',
    404     => 'search',
    419     => 'clock',
    429     => 'pause',
    default => 'alert',
};
?><!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($status . ' — ' . $title) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<?= View::include('partials.icons') ?>

<div class="auth">
    <div class="auth__card" style="max-width: <?= $debug !== null ? '860px' : '460px' ?>">
        <div class="auth__box text-center">
            <div class="empty__icon"><?= icon($iconName, 'icon icon-hero') ?></div>
            <div style="font-size:44px; font-weight:700; letter-spacing:-0.03em; line-height:1"><?= e((string) $status) ?></div>
            <h1 class="mt-2"><?= e($title) ?></h1>
            <p class="muted mt-2"><?= e($message) ?></p>

            <div class="form-actions" style="justify-content:center">
                <a class="btn btn-primary" href="<?= e(url('/dashboard')) ?>"><?= icon('home') ?> Go to dashboard</a>
                <a class="btn" href="javascript:history.back()"><?= icon('arrow-left') ?> Go back</a>
            </div>

            <?php if ($debug !== null): ?>
                <div class="text-left mt-5">
                    <div class="label">Exception</div>
                    <pre class="code-block"><?= e($debug::class . ': ' . $debug->getMessage()) ?>

<?= e($debug->getFile() . ':' . $debug->getLine()) ?>

<?= e($debug->getTraceAsString()) ?></pre>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
