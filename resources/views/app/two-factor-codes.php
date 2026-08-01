<?php

use App\Core\View;

View::extend('layouts.app');
View::share('title', 'Recovery codes');
View::share('contentClass', 'content--narrow');

/** @var list<string> $codes */

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('shield-check', 'icon icon-lg') ?> Two-factor authentication is on</h1>
        <div class="page-head__sub">Store these recovery codes somewhere safe.</div>
    </div>
</div>

<div class="alert alert-warning">
    <?= icon('alert') ?>
    <div class="alert__body">
        <div class="alert__title">This is the only time these codes are shown</div>
        Each code works once, and lets you sign in if you lose your authenticator device.
    </div>
</div>

<div class="card">
    <div class="card__body">
        <pre class="code-block" id="recovery-codes" style="font-size:14px; line-height:2"><?php foreach ($codes as $code): ?><?= e($code) ?>
<?php endforeach; ?></pre>

        <div class="form-actions">
            <button class="btn" type="button" data-copy="#recovery-codes" data-copy-label="Recovery codes"><?= icon('clipboard') ?> Copy all</button>
            <button class="btn" type="button" onclick="window.print()"><?= icon('file-text') ?> Print</button>
            <a class="btn btn-primary" href="<?= e(url('/settings')) ?>"><?= icon('check') ?> I have saved them</a>
        </div>
    </div>
</div>
<?php
View::endSection();
