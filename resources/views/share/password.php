<?php

use App\Core\View;

View::extend('layouts.public');
View::share('title', 'Protected link');

/** @var string $token */
/** @var string|null $error */

View::startSection('content');
?>
<div class="card" style="max-width:440px; margin:0 auto">
    <div class="card__body text-center">
        <div class="empty__icon"><?= icon('lock', 'icon icon-hero') ?></div>
        <h1>This link is password protected</h1>
        <p class="muted mt-2">Enter the password you were given to view the file.</p>

        <?php if ($error !== null): ?>
            <div class="alert alert-danger mt-3 text-left">
                <?= icon('x-circle') ?>
                <div class="alert__body"><?= e($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/s/' . $token . '/unlock')) ?>" class="mt-4 text-left">
            <?= csrf_field() ?>
            <div class="field">
                <label class="label" for="password">Password</label>
                <input class="input" id="password" name="password" type="password" required data-autofocus autocomplete="off">
            </div>
            <button class="btn btn-primary btn-block btn-lg" type="submit"><?= icon('unlock') ?> Unlock</button>
        </form>
    </div>
</div>
<?php
View::endSection();
