<?php

use App\Core\View;

View::extend('layouts.app');
View::share('title', 'Profile');
View::share('contentClass', 'content--narrow');

/** @var array $user */
/** @var array $stats */
/** @var list<array> $logins */

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('user', 'icon icon-lg') ?> Profile &amp; security</h1>
        <div class="page-head__sub">Manage your account details, password and two-factor authentication.</div>
    </div>
</div>

<div class="card">
    <div class="card__body flex items-center gap-4">
        <span class="avatar avatar-lg" style="background: <?= e($user['avatar_color']) ?>">
            <?= e(\App\Support\Str::initials((string) $user['name'])) ?>
        </span>
        <div class="flex-1">
            <h2><?= e($user['name']) ?></h2>
            <div class="muted small"><?= e($user['email']) ?></div>
            <div class="flex gap-2 mt-2 flex-wrap">
                <span class="badge badge-accent"><?= icon('shield') ?> <?= e((string) ($user['role_label'] ?? $user['role_name'])) ?></span>
                <span class="badge <?= $user['status'] === 'active' ? 'badge-success' : 'badge-warning' ?>"><?= e($user['status']) ?></span>
                <?php if ((int) $user['two_factor_enabled'] === 1): ?>
                    <span class="badge badge-success"><?= icon('shield-check') ?> 2FA on</span>
                <?php else: ?>
                    <span class="badge badge-warning"><?= icon('alert') ?> 2FA off</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="text-right">
            <div class="faint small">Storage used</div>
            <div class="strong" style="font-size:18px"><?= e(bytes($stats['bytes'])) ?></div>
            <div class="faint small">
                <?= $stats['quota']['limit'] > 0 ? e((string) $stats['quota']['percent']) . '% of ' . e(bytes($stats['quota']['limit'])) : 'Unlimited' ?>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card__head"><?= icon('user') ?><h3>Account details</h3></div>
        <div class="card__body">
            <form method="post" action="<?= e(url('/settings/profile')) ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label class="label" for="name">Full name</label>
                    <input class="input" id="name" name="name" required value="<?= e($user['name']) ?>">
                </div>
                <div class="field">
                    <label class="label" for="email">Email address</label>
                    <input class="input" id="email" name="email" type="email" required value="<?= e($user['email']) ?>">
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= icon('save') ?> Save changes</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card__head"><?= icon('lock') ?><h3>Change password</h3></div>
        <div class="card__body">
            <form method="post" action="<?= e(url('/settings/password')) ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label class="label" for="current_password">Current password</label>
                    <input class="input" id="current_password" name="current_password" type="password" required autocomplete="current-password">
                </div>
                <div class="field">
                    <label class="label" for="new_password">New password</label>
                    <input class="input" id="new_password" name="password" type="password" required autocomplete="new-password">
                    <div class="hint">At least 8 characters, with letters and numbers.</div>
                </div>
                <div class="field">
                    <label class="label" for="password_confirmation">Confirm new password</label>
                    <input class="input" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= icon('key') ?> Update password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card__head"><?= icon('shield-check') ?><h3>Two-factor authentication</h3></div>
    <div class="card__body">
        <?php if ((int) $user['two_factor_enabled'] === 1): ?>
            <div class="alert alert-success">
                <?= icon('shield-check') ?>
                <div class="alert__body">
                    <div class="alert__title">Two-factor authentication is active</div>
                    Sign-in requires a code from your authenticator app.
                </div>
            </div>
            <form method="post" action="<?= e(url('/settings/two-factor/disable')) ?>">
                <?= csrf_field() ?>
                <div class="field" style="max-width:320px">
                    <label class="label" for="disable_password">Confirm with your password</label>
                    <input class="input" id="disable_password" name="password" type="password" required>
                </div>
                <button class="btn btn-danger" type="submit" data-confirm="Disable two-factor authentication?">
                    <?= icon('unlock') ?> Disable 2FA
                </button>
            </form>
        <?php else: ?>
            <p class="muted">Add a second layer of protection with a TOTP authenticator app such as Google Authenticator, 1Password or Authy.</p>
            <a class="btn btn-primary" href="<?= e(url('/settings/two-factor')) ?>"><?= icon('shield') ?> Enable 2FA</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card__head"><?= icon('history') ?><h3>Recent sign-in activity</h3></div>
    <div class="card__body card__body--flush">
        <?php if ($logins === []): ?>
            <div class="card__body"><p class="faint small">No sign-in history yet.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Result</th><th>IP address</th><th>Client</th><th class="nowrap">When</th></tr></thead>
                    <tbody>
                    <?php foreach ($logins as $attempt): ?>
                        <tr>
                            <td>
                                <?php if ((int) $attempt['success'] === 1): ?>
                                    <span class="badge badge-success"><?= icon('check') ?> success</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><?= icon('x') ?> <?= e((string) ($attempt['reason'] ?: 'failed')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="mono small"><?= e((string) $attempt['ip']) ?></td>
                            <td class="faint small truncate" style="max-width:280px"><?= e((string) $attempt['user_agent']) ?></td>
                            <td class="nowrap faint"><?= e(time_ago($attempt['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
View::endSection();
