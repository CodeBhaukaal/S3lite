<?php

use App\Core\View;
use App\Models\SftpAccount;
use App\Models\SftpSession;

View::extend('layouts.app');
View::share('title', 'FTP / SFTP administration');

/** @var array $accounts */
/** @var list<array> $sessions */
/** @var list<array> $users */
/** @var array $services */
/** @var string|null $newPassword */
/** @var array $connection */

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('server', 'icon icon-lg') ?> FTP / FTPS / SFTP</h1>
        <div class="page-head__sub">
            Accounts, isolated home directories, quotas, SSH keys and live sessions across every user.
        </div>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/admin/sftp/openssh-config')) ?>"><?= icon('download') ?> sshd_config snippet</a>
        <form method="post" action="<?= e(url('/admin/sftp/sync-all')) ?>">
            <?= csrf_field() ?>
            <button class="btn" type="submit"><?= icon('refresh') ?> Sync all</button>
        </form>
        <button class="btn btn-primary" type="button" data-modal-open="modal-admin-sftp"><?= icon('plus') ?> New account</button>
    </div>
</div>

<?php if ($newPassword !== null): ?>
    <div class="card mb-4" style="border-color: var(--accent)">
        <div class="card__head"><?= icon('check-circle', 'icon text-success') ?><h3>Account password</h3></div>
        <div class="card__body">
            <div class="input-copy">
                <input class="input mono" id="admin-sftp-pass" readonly value="<?= e($newPassword) ?>">
                <button class="btn btn-primary btn-icon" type="button" data-copy="#admin-sftp-pass" data-copy-label="Password"><?= icon('clipboard') ?></button>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-3 mb-4">
    <?php foreach ([
        'sftp' => ['SFTP', 'terminal', 'SSH file transfer on port ' . $connection['port']],
        'ftps' => ['FTPS', 'lock', 'FTP over explicit TLS'],
        'ftp'  => ['FTP', 'globe', 'Plain FTP — unencrypted'],
    ] as $key => [$label, $ic, $description]): ?>
        <div class="stat <?= $services[$key] ? 'stat--success' : '' ?>">
            <div class="stat__icon"><?= icon($ic) ?></div>
            <div class="stat__label"><?= e($label) ?></div>
            <div class="stat__value" style="font-size:17px">
                <?php if ($services[$key]): ?>
                    <span class="badge badge-success"><span class="dot dot-success"></span> enabled</span>
                <?php else: ?>
                    <span class="badge">disabled</span>
                <?php endif; ?>
            </div>
            <div class="stat__meta"><?= e($description) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="alert alert-info">
    <?= icon('info') ?>
    <div class="alert__body">
        <div class="alert__title">How the integration works</div>
        This platform owns the account database, the isolated home directories, the quotas and the activity log.
        Point your SFTP daemon (OpenSSH, FileZilla Server, …) at the generated
        <span class="code-inline">sshd_config</span> snippet, or have it call
        <span class="code-inline">POST /api/v1/sftp/authenticate</span> with an <span class="code-inline">sftp:write</span> key.
        Uploads are indexed into the web panel by the <span class="code-inline">sftp.sync</span> job.
    </div>
</div>

<form class="filters" method="get">
    <div class="field">
        <select class="select" name="status" data-auto-submit>
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="disabled">Disabled</option>
        </select>
    </div>
    <button class="btn" type="submit"><?= icon('filter') ?> Filter</button>
</form>

<div class="card">
    <div class="card__head"><?= icon('users') ?><h2>Accounts</h2><span class="badge"><?= e((string) $accounts['total']) ?></span></div>
    <div class="card__body card__body--flush">
        <?php if ($accounts['data'] === []): ?>
            <div class="empty">
                <div class="empty__icon"><?= icon('server', 'icon icon-hero') ?></div>
                <div class="empty__title">No transfer accounts</div>
                <div class="empty__text">Create one and assign it to a user.</div>
                <button class="btn btn-primary" type="button" data-modal-open="modal-admin-sftp"><?= icon('plus') ?> New account</button>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th>Username</th><th>Owner</th><th>Protocol</th><th>Access</th><th style="min-width:160px">Usage</th><th>Sessions</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($accounts['data'] as $account): ?>
                        <?php
                        $public = SftpAccount::publicArray($account);
                        $pct = $public['quota_bytes'] > 0 ? min(100, round($public['used_bytes'] / $public['quota_bytes'] * 100)) : 0;
                        ?>
                        <tr>
                            <td>
                                <span class="mono strong"><?= e($public['username']) ?></span>
                                <div class="faint small truncate" style="max-width:240px"><?= e($public['home_dir']) ?></div>
                            </td>
                            <td>
                                <a href="<?= e(url('/admin/users/' . $account['user_id'])) ?>"><?= e((string) $account['owner_name']) ?></a>
                                <div class="faint small"><?= e((string) $account['owner_email']) ?></div>
                            </td>
                            <td><span class="badge badge-accent"><?= e(strtoupper($public['protocol'])) ?></span></td>
                            <td>
                                <span class="badge <?= $public['permission'] === 'rw' ? 'badge-success' : 'badge-warning' ?>">
                                    <?= $public['permission'] === 'rw' ? 'read/write' : 'read only' ?>
                                </span>
                            </td>
                            <td>
                                <div class="small tabular"><?= e(bytes($public['used_bytes'])) ?> / <?= e(bytes($public['quota_bytes'])) ?></div>
                                <div class="progress mt-1"><div class="progress__bar <?= $pct >= 90 ? 'is-danger' : '' ?>" style="width:<?= e((string) $pct) ?>%"></div></div>
                            </td>
                            <td>
                                <span class="badge <?= (int) ($account['active_sessions'] ?? 0) > 0 ? 'badge-success' : '' ?>">
                                    <?= icon('activity') ?> <?= e((string) ($account['active_sessions'] ?? 0)) ?>
                                </span>
                                <span class="badge"><?= icon('key') ?> <?= e((string) ($account['key_count'] ?? 0)) ?></span>
                            </td>
                            <td>
                                <?php if ($public['status'] === 'active'): ?>
                                    <span class="badge badge-success"><span class="dot dot-success"></span> active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">disabled</span>
                                <?php endif; ?>
                            </td>
                            <td class="right">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-ghost btn-icon" type="button" data-dropdown><?= icon('more') ?></button>
                                    <div class="dropdown__menu">
                                        <button class="dropdown__item" type="button"
                                                data-modal-open="modal-admin-sftp-edit"
                                                data-modal-action="<?= e(url('/admin/sftp/' . $account['id'] . '/update')) ?>"><?= icon('edit') ?> Edit</button>

                                        <form method="post" action="<?= e(url('/admin/sftp/' . $account['id'] . '/password')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="dropdown__item" type="submit"><?= icon('refresh') ?> Reset password</button>
                                        </form>

                                        <form method="post" action="<?= e(url('/admin/sftp/' . $account['id'] . '/sync')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="dropdown__item" type="submit"><?= icon('refresh') ?> Sync files</button>
                                        </form>

                                        <div class="dropdown__divider"></div>
                                        <form method="post" action="<?= e(url('/admin/sftp/' . $account['id'] . '/delete')) ?>">
                                            <?= csrf_field() ?>
                                            <label class="dropdown__item" style="cursor:pointer">
                                                <input type="checkbox" name="remove_files" value="1"> Also delete files
                                            </label>
                                            <button class="dropdown__item is-danger" type="submit"
                                                    data-confirm="Delete this transfer account?"><?= icon('trash') ?> Delete account</button>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?= View::include('partials.pagination', ['result' => $accounts]) ?>

<div class="card">
    <div class="card__head"><?= icon('activity') ?><h2>Active sessions</h2><span class="badge"><?= e((string) count($sessions)) ?></span></div>
    <div class="card__body card__body--flush">
        <?php if ($sessions === []): ?>
            <div class="card__body"><p class="faint small">No live transfer sessions.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Account</th><th>Owner</th><th>Protocol</th><th>IP</th><th>Traffic</th><th class="nowrap">Idle</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($sessions as $session): ?>
                        <?php $data = SftpSession::publicArray($session); ?>
                        <tr>
                            <td class="mono"><?= e((string) $data['username']) ?></td>
                            <td><?= e((string) ($session['owner_name'] ?? '')) ?></td>
                            <td><span class="badge"><?= e(strtoupper((string) $data['protocol'])) ?></span></td>
                            <td class="mono small"><?= e((string) $data['ip']) ?></td>
                            <td class="small tabular"><?= e(bytes($data['bytes_in'])) ?> in / <?= e(bytes($data['bytes_out'])) ?> out</td>
                            <td class="nowrap faint"><?= e(time_ago($data['last_activity_at'])) ?></td>
                            <td class="right">
                                <form method="post" action="<?= e(url('/admin/sftp/sessions/' . $session['id'] . '/close')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-danger" type="submit"><?= icon('power') ?> Disconnect</button>
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

<div class="modal-backdrop hidden" id="modal-admin-sftp">
    <div class="modal">
        <form method="post" action="<?= e(url('/admin/sftp')) ?>">
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('server') ?><h3>New transfer account</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="field">
                    <label class="label">Owner <span class="req">*</span></label>
                    <select class="select" name="user_id" required>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= e((string) $user['id']) ?>"><?= e($user['name']) ?> — <?= e($user['email']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="label">Username <span class="req">*</span></label>
                    <input class="input mono" name="username" required minlength="3" maxlength="32" pattern="[a-zA-Z0-9._\-]+">
                </div>
                <div class="field">
                    <label class="label">Password <span class="req">*</span></label>
                    <input class="input" name="password" type="text" required minlength="8">
                </div>
                <div class="form-row">
                    <div class="field">
                        <label class="label">Protocol</label>
                        <select class="select" name="protocol">
                            <option value="sftp">SFTP</option>
                            <option value="ftps">FTPS</option>
                            <option value="ftp">FTP</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label">Access</label>
                        <select class="select" name="permission">
                            <option value="rw">Read / write</option>
                            <option value="ro">Read only</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label">Quota (GB)</label>
                        <input class="input" name="quota_gb" type="number" min="0" value="1">
                    </div>
                </div>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Create account</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop hidden" id="modal-admin-sftp-edit">
    <div class="modal">
        <form method="post" action="">
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('edit') ?><h3>Edit account</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="form-row">
                    <div class="field">
                        <label class="label">Protocol</label>
                        <select class="select" name="protocol">
                            <option value="sftp">SFTP</option>
                            <option value="ftps">FTPS</option>
                            <option value="ftp">FTP</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label">Access</label>
                        <select class="select" name="permission">
                            <option value="rw">Read / write</option>
                            <option value="ro">Read only</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label class="label">Status</label>
                        <select class="select" name="status">
                            <option value="active">Active</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label">Quota (GB)</label>
                        <input class="input" name="quota_gb" type="number" min="0" value="1">
                    </div>
                </div>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('save') ?> Save</button>
            </div>
        </form>
    </div>
</div>
<?php
View::endSection();
