<?php

use App\Core\View;

View::extend('layouts.auth');
View::share('title', 'Create account');

View::startSection('content');
?>
<h1 class="auth__title">Create your account</h1>
<p class="auth__sub">You will get your own isolated storage space.</p>

<form method="post" action="<?= e(url('/register')) ?>">
    <?= csrf_field() ?>

    <div class="field">
        <label class="label" for="name">Full name</label>
        <input class="input" type="text" id="name" name="name" required data-autofocus
               autocomplete="name" value="<?= e(old('name')) ?>">
    </div>

    <div class="field">
        <label class="label" for="email">Email address</label>
        <input class="input" type="email" id="email" name="email" required
               autocomplete="username" value="<?= e(old('email')) ?>">
    </div>

    <div class="field">
        <label class="label" for="password">Password</label>
        <input class="input" type="password" id="password" name="password" required autocomplete="new-password">
        <div class="hint">At least 8 characters, with letters and numbers.</div>
    </div>

    <div class="field">
        <label class="label" for="password_confirmation">Confirm password</label>
        <input class="input" type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
    </div>

    <button class="btn btn-primary btn-block btn-lg mt-3" type="submit">
        <?= icon('user-plus') ?> Create account
    </button>
</form>

<div class="auth__foot">
    Already have an account? <a href="<?= e(url('/login')) ?>">Sign in</a>
</div>
<?php
View::endSection();
