<?php

use App\Core\View;

View::extend('layouts.auth');
View::share('title', 'Sign in');

/** @var bool $allowRegistration */
/** @var string|null $twoFactorPending */

View::startSection('content');
?>
<h1 class="auth__title">Welcome back</h1>
<p class="auth__sub">Sign in to reach your storage, share links and API keys.</p>

<form method="post" action="<?= e(url('/login')) ?>" autocomplete="on">
    <?= csrf_field() ?>

    <div class="field">
        <label class="label" for="email">Email address</label>
        <input class="input" type="email" id="email" name="email" required data-autofocus
               autocomplete="username" placeholder="you@example.com"
               value="<?= e(old('email')) ?>">
    </div>

    <div class="field">
        <label class="label" for="password">Password</label>
        <input class="input" type="password" id="password" name="password" required
               autocomplete="current-password" placeholder="••••••••">
    </div>

    <?php if ($twoFactorPending !== null): ?>
        <div class="field">
            <label class="label" for="otp">Authentication code</label>
            <input class="input mono" type="text" id="otp" name="otp" inputmode="numeric"
                   pattern="[0-9]*" maxlength="6" placeholder="123456" autocomplete="one-time-code" autofocus>
            <div class="hint">Open your authenticator app, or use a recovery code.</div>
        </div>
    <?php endif; ?>

    <label class="check">
        <input type="checkbox" name="remember" value="1">
        <span class="check__text">Keep me signed in</span>
    </label>

    <button class="btn btn-primary btn-block btn-lg mt-3" type="submit">
        <?= icon('log-in') ?> Sign in
    </button>
</form>

<?php if ($allowRegistration): ?>
    <div class="divider-label">or</div>
    <a class="btn btn-block" href="<?= e(url('/register')) ?>"><?= icon('user-plus') ?> Create an account</a>
<?php endif; ?>
<?php
View::endSection();
