<?php

use App\Core\View;
use App\Models\FileRecord;
use App\Models\SftpAccount;

View::extend('layouts.app');
View::share('title', (string) $user['name']);

/** @var array $user */
/** @var array $stats */
/** @var array $files */
/** @var list<array> $logins */
/** @var list<array> $activity */
/** @var list<array> $sftp */
/** @var list<array> $roles */

$limit = (int) $user['quota_bytes'];
$pct = $limit > 0 ? min(100, round((int) $user['used_bytes'] / $limit * 100)) : 0;

View::startSection('content');
?>
<nav class="breadcrumbs">
    <a href="<?= e(url('/admin/users')) ?>"><?= icon('users', 'icon icon-sm') ?> Users</a>
    <span class="sep"><?= icon('chevron-right', 'icon icon-sm') ?></span>
    <span class="current"><?= e($user['name']) ?></span>
</nav>

<div class="card mb-4">
    <div class="card__body flex items-center gap-4 flex-wrap">
        <span class="avatar avatar-lg" style="background: <?= e((string) $user['avatar_color']) ?>">
            <?= e(\App\Support\Str::initials((string) $user['name'])) ?>
        </span>
        <div class="flex-1" style="min-width:220px">
            <h1><?= e($user['name']) ?></h1>
            <div class="muted"><?= e($user['email']) ?></div>
            <div class="flex gap-2 mt-2 flex-wrap">
                <span class="badge badge-accent"><?= icon('shield') ?> <?= e((string) $user['role_label']) ?></span>
                <span class="badge <?= $user['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>"><?= e($user['status']) ?></span>
                <span class="badge"><?= icon('clock') ?> joined <?= e(date('d M Y', strtotime((string) $user['created_at']))) ?></span>
                <?php if ((int) $user['two_factor_enabled'] === 1): ?>
                    <span class="badge badge-success"><?= icon('shield-check') ?> 2FA</span>
                <?php endif; ?>
            </div>
        </div>
        <div style="min-width:220px">
            <div class="faint small">Storage</div>
            <div class="strong" style="font-size:18px"><?= e(bytes((int) $user['used_bytes'])) ?></div>
            <div class="progress mt-1"><div class="progress__bar <?= $pct >= 90 ? 'is-danger' : '' ?>" style="width:<?= e((string) $pct) ?>%"></div></div>
            <div class="faint small mt-1"><?= $limit > 0 ? e((string) $pct) . '% of ' . e(bytes($limit)) : 'Unlimited' ?></div>
        </div>
    </div>
</div>

<div class="grid grid-4 mb-4">
    <div class="stat"><div class="stat__icon"><?= icon('file') ?></div><div class="stat__label">Files</div><div class="stat__value tabular"><?= e(number_format($stats['files'])) ?></div></div>
    <div class="stat stat--info"><div class="stat__icon"><?= icon('folder') ?></div><div class="stat__label">Folders</div><div class="stat__value tabular"><?= e(number_format($stats['folders'])) ?></div></div>
    <div class="stat stat--success"><div class="stat__icon"><?= icon('download') ?></div><div class="stat__label">Downloads</div><div class="stat__value tabular"><?= e(number_format($stats['downloads'])) ?></div></div>
    <div class="stat stat--warning"><div class="stat__icon"><?= icon('link') ?></div><div class="stat__label">Share links</div><div class="stat__value tabular"><?= e(number_format($stats['shares'])) ?></div></div>
</div>

<div class="grid grid-main">
    <div>
        <div class="card">
            <div class="card__head"><?= icon('file') ?><h2>Recent files</h2></div>
            <div class="card__body card__body--flush">
                <?php if ($files['data'] === []): ?>
                    <div class="card__body"><p class="faint small">This user has not uploaded anything yet.</p></div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Name</th><th class="nowrap">Size</th><th class="nowrap">Uploaded</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($files['data'] as $file): ?>
                                <?php $kind = FileRecord::kind($file); ?>
                                <tr>
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <?= icon(match ($kind) {
                                                'image' => 'image', 'video' => 'video', 'audio' => 'music',
                                                'pdf', 'text' => 'file-text', 'archive' => 'archive', default => 'file',
                                            }, 'icon kind-' . $kind) ?>
                                            <span class="truncate" style="max-width:300px"><?= e($file['name']) ?></span>
                                            <?php if ($file['deleted_at'] !== null): ?><span class="badge badge-danger">trashed</span><?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="nowrap tabular"><?= e(bytes((int) $file['size'])) ?></td>
                                    <td class="nowrap faint"><?= e(time_ago($file['created_at'])) ?></td>
                                    <td class="right">
                                        <form method="post" action="<?= e(url('/admin/files/' . $file['uuid'] . '/delete')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-ghost btn-icon" type="submit"
                                                    data-confirm="Permanently delete this file?" title="Delete"><?= icon('trash') ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card__head"><?= icon('activity') ?><h2>Activity timeline</h2></div>
            <div class="card__body card__body--flush">
                <table class="table">
                    <tbody>
                    <?php foreach ($activity as $entry): ?>
                        <tr>
                            <td>
                                <span class="badge <?= $entry['status'] === 'failed' ? 'badge-danger' : '' ?>"><?= e($entry['action']) ?></span>
                                <div class="small muted mt-1"><?= e((string) $entry['description']) ?></div>
                            </td>
                            <td class="right faint small nowrap"><?= e(time_ago($entry['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card__head"><?= icon('log-in') ?><h2>Sign-in attempts</h2></div>
            <div class="card__body card__body--flush">
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Result</th><th>IP</th><th>Client</th><th class="nowrap">When</th></tr></thead>
                        <tbody>
                        <?php foreach ($logins as $attempt): ?>
                            <tr>
                                <td>
                                    <?php if ((int) $attempt['success'] === 1): ?>
                                        <span class="badge badge-success"><?= icon('check') ?> success</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger"><?= e((string) ($attempt['reason'] ?: 'failed')) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="mono small"><?= e((string) $attempt['ip']) ?></td>
                                <td class="faint small truncate" style="max-width:240px"><?= e((string) $attempt['user_agent']) ?></td>
                                <td class="nowrap faint"><?= e(time_ago($attempt['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card__head"><?= icon('edit') ?><h3>Edit account</h3></div>
            <div class="card__body">
                <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/update')) ?>">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="label">Name</label>
                        <input class="input" name="name" value="<?= e($user['name']) ?>">
                    </div>
                    <div class="field">
                        <label class="label">Email</label>
                        <input class="input" name="email" type="email" value="<?= e($user['email']) ?>">
                    </div>
                    <div class="field">
                        <label class="label">Role</label>
                        <select class="select" name="role">
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= e($role['name']) ?>" <?= $role['name'] === $user['role_name'] ? 'selected' : '' ?>><?= e($role['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label">Status</label>
                        <select class="select" name="status">
                            <?php foreach (['active', 'suspended', 'pending'] as $option): ?>
                                <option value="<?= e($option) ?>" <?= $user['status'] === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label">Quota (GB)</label>
                        <input class="input" name="quota_gb" type="number" min="0" value="<?= e((string) (int) ($limit / 1073741824)) ?>">
                        <div class="hint">0 means unlimited.</div>
                    </div>
                    <div class="field">
                        <label class="label">Set a new password</label>
                        <input class="input" name="password" type="text" placeholder="Leave blank to keep current" autocomplete="new-password">
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary btn-block" type="submit"><?= icon('save') ?> Save changes</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card__head"><?= icon('terminal') ?><h3>FTP / SFTP accounts</h3></div>
            <div class="card__body card__body--flush">
                <?php if ($sftp === []): ?>
                    <div class="card__body"><p class="faint small">No transfer accounts.</p></div>
                <?php else: ?>
                    <table class="table">
                        <tbody>
                        <?php foreach ($sftp as $account): ?>
                            <?php $public = SftpAccount::publicArray($account); ?>
                            <tr>
                                <td>
                                    <span class="mono strong"><?= e($public['username']) ?></span>
                                    <div class="faint small"><?= e(bytes($public['used_bytes'])) ?> used</div>
                                </td>
                                <td class="right">
                                    <span class="badge <?= $public['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>"><?= e($public['status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <div class="card__foot">
                <a class="btn btn-sm btn-block" href="<?= e(url('/admin/sftp')) ?>"><?= icon('server') ?> Manage transfer accounts</a>
            </div>
        </div>

        <div class="card">
            <div class="card__head"><?= icon('alert', 'icon text-danger') ?><h3>Danger zone</h3></div>
            <div class="card__body">
                <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/delete')) ?>">
                    <?= csrf_field() ?>
                    <label class="check">
                        <input type="checkbox" name="purge_files" value="1">
                        <span>
                            <span class="check__text">Also delete all their files</span>
                            <span class="check__desc">Removes every file and frees the disk space. This cannot be undone.</span>
                        </span>
                    </label>
                    <button class="btn btn-danger btn-block mt-2" type="submit"
                            data-confirm="Delete this user account?"><?= icon('trash') ?> Delete user</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
View::endSection();
