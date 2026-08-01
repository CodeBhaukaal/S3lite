<?php

use App\Core\View;

View::extend('layouts.app');
View::share('title', 'Users');

/** @var array $users */
/** @var list<array> $roles */
/** @var string $query */
/** @var string $status */

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('users', 'icon icon-lg') ?> Users</h1>
        <div class="page-head__sub"><?= e(number_format((int) $users['total'])) ?> accounts · <?= e((string) count($roles)) ?> roles</div>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/admin/roles')) ?>"><?= icon('shield') ?> Roles &amp; permissions</a>
        <button class="btn btn-primary" type="button" data-modal-open="modal-user"><?= icon('user-plus') ?> New user</button>
    </div>
</div>

<form class="filters" method="get">
    <div class="field flex-1" style="max-width:340px">
        <div class="search">
            <?= icon('search') ?>
            <input class="input" type="search" name="q" placeholder="Search by name or email…" value="<?= e($query) ?>" data-search-input>
        </div>
    </div>
    <div class="field">
        <select class="select" name="status" data-auto-submit>
            <option value="">All statuses</option>
            <?php foreach (['active', 'suspended', 'pending'] as $option): ?>
                <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn" type="submit"><?= icon('filter') ?> Filter</button>
</form>

<div class="card">
    <div class="card__body card__body--flush">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>User</th><th>Role</th><th>Status</th><th style="min-width:180px">Storage</th><th class="nowrap">Last login</th><th>2FA</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($users['data'] as $user): ?>
                    <?php
                    $limit = (int) $user['quota_bytes'];
                    $used = (int) $user['used_bytes'];
                    $pct = $limit > 0 ? min(100, round($used / $limit * 100)) : 0;
                    ?>
                    <tr>
                        <td>
                            <div class="flex items-center gap-2">
                                <span class="avatar avatar-sm" style="background: <?= e((string) $user['avatar_color']) ?>">
                                    <?= e(\App\Support\Str::initials((string) $user['name'])) ?>
                                </span>
                                <div>
                                    <a href="<?= e(url('/admin/users/' . $user['id'])) ?>" class="strong"><?= e($user['name']) ?></a>
                                    <div class="faint small"><?= e($user['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge badge-accent"><?= e((string) $user['role_label']) ?></span></td>
                        <td>
                            <?php if ($user['status'] === 'active'): ?>
                                <span class="badge badge-success"><span class="dot dot-success"></span> active</span>
                            <?php elseif ($user['status'] === 'suspended'): ?>
                                <span class="badge badge-danger">suspended</span>
                            <?php else: ?>
                                <span class="badge badge-warning">pending</span>
                            <?php endif; ?>
                            <?php if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()): ?>
                                <span class="badge badge-warning"><?= icon('lock') ?> locked</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="progress"><div class="progress__bar <?= $pct >= 90 ? 'is-danger' : ($pct >= 75 ? 'is-warning' : '') ?>" style="width:<?= e((string) $pct) ?>%"></div></div>
                            <div class="faint small mt-1 tabular">
                                <?= e(bytes($used)) ?> <?= $limit > 0 ? '/ ' . e(bytes($limit)) : '/ unlimited' ?>
                            </div>
                        </td>
                        <td class="nowrap faint"><?= e(time_ago($user['last_login_at'])) ?></td>
                        <td>
                            <?php if ((int) $user['two_factor_enabled'] === 1): ?>
                                <span class="badge badge-success"><?= icon('shield-check') ?></span>
                            <?php else: ?>
                                <span class="badge">off</span>
                            <?php endif; ?>
                        </td>
                        <td class="right">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-ghost btn-icon" type="button" data-dropdown><?= icon('more') ?></button>
                                <div class="dropdown__menu">
                                    <a class="dropdown__item" href="<?= e(url('/admin/users/' . $user['id'])) ?>"><?= icon('eye') ?> View details</a>

                                    <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/update')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="status" value="<?= $user['status'] === 'active' ? 'suspended' : 'active' ?>">
                                        <button class="dropdown__item" type="submit">
                                            <?= icon($user['status'] === 'active' ? 'pause' : 'play') ?>
                                            <?= $user['status'] === 'active' ? 'Suspend' : 'Activate' ?>
                                        </button>
                                    </form>

                                    <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/update')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="unlock" value="1">
                                        <button class="dropdown__item" type="submit"><?= icon('unlock') ?> Clear lockout</button>
                                    </form>

                                    <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/recalculate')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="dropdown__item" type="submit"><?= icon('refresh') ?> Recalculate storage</button>
                                    </form>

                                    <div class="dropdown__divider"></div>
                                    <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/delete')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="dropdown__item is-danger" type="submit"
                                                data-confirm="Delete this user? Their files are kept unless you purge them separately.">
                                            <?= icon('trash') ?> Delete user
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?= View::include('partials.pagination', ['result' => $users]) ?>

<div class="modal-backdrop hidden" id="modal-user">
    <div class="modal">
        <form method="post" action="<?= e(url('/admin/users')) ?>">
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('user-plus') ?><h3>New user</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="field">
                    <label class="label" for="u-name">Full name <span class="req">*</span></label>
                    <input class="input" id="u-name" name="name" required maxlength="120">
                </div>
                <div class="field">
                    <label class="label" for="u-email">Email <span class="req">*</span></label>
                    <input class="input" id="u-email" name="email" type="email" required>
                </div>
                <div class="field">
                    <label class="label" for="u-pass">Password <span class="req">*</span></label>
                    <input class="input" id="u-pass" name="password" type="text" required minlength="8">
                    <div class="hint">At least 8 characters, with letters and numbers.</div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label class="label">Role</label>
                        <select class="select" name="role">
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= e($role['name']) ?>" <?= $role['name'] === 'user' ? 'selected' : '' ?>><?= e($role['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label">Quota (GB)</label>
                        <input class="input" name="quota_gb" type="number" min="0" value="10">
                        <div class="hint">0 means unlimited.</div>
                    </div>
                </div>
                <div class="field">
                    <label class="label">Status</label>
                    <select class="select" name="status">
                        <option value="active">Active</option>
                        <option value="pending">Pending approval</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Create user</button>
            </div>
        </form>
    </div>
</div>
<?php
View::endSection();
