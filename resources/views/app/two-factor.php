<?php

use App\Core\View;

View::extend('layouts.app');
View::share('title', 'Enable two-factor');
View::share('contentClass', 'content--narrow');

/** @var string $secret */
/** @var string $uri */

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('shield', 'icon icon-lg') ?> Enable two-factor authentication</h1>
        <div class="page-head__sub">Three steps: add the secret to your app, enter the code, save your recovery codes.</div>
    </div>
</div>

<div class="card">
    <div class="card__body">
        <h3 class="mb-2">1. Add this secret to your authenticator</h3>
        <p class="muted small">Most apps let you type a setup key manually. Use the value below.</p>

        <div class="input-copy mb-3" style="max-width:460px">
            <input class="input mono" id="totp-secret" readonly value="<?= e($secret) ?>">
            <button class="btn btn-icon" type="button" data-copy="#totp-secret" data-copy-label="Secret"><?= icon('clipboard') ?></button>
        </div>

        <div class="label">Or paste this otpauth URI</div>
        <div class="input-copy mb-4">
            <input class="input mono" id="totp-uri" readonly value="<?= e($uri) ?>">
            <button class="btn btn-icon" type="button" data-copy="#totp-uri" data-copy-label="URI"><?= icon('clipboard') ?></button>
        </div>

        <hr>

        <h3 class="mb-2">2. Confirm the six-digit code</h3>
        <form method="post" action="<?= e(url('/settings/two-factor')) ?>">
            <?= csrf_field() ?>
            <div class="field" style="max-width:220px">
                <label class="label" for="code">Authentication code</label>
                <input class="input mono" id="code" name="code" required inputmode="numeric"
                       pattern="[0-9]{6}" maxlength="6" placeholder="123456" data-autofocus
                       style="font-size:20px; letter-spacing:0.25em; text-align:center">
            </div>
            <div class="form-actions">
                <a class="btn" href="<?= e(url('/settings')) ?>">Cancel</a>
                <button class="btn btn-primary" type="submit"><?= icon('shield-check') ?> Enable 2FA</button>
            </div>
        </form>
    </div>
</div>
<?php
View::endSection();
