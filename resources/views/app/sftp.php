<?php

use App\Core\View;
use App\Models\SftpAccount;
use App\Models\SftpKey;
use App\Models\SftpSession;

View::extend('layouts.app');
View::share('title', 'FTP / SFTP');

/** @var list<array> $accounts */
/** @var array<int, list<array>> $keys */
/** @var list<array> $sessions */
/** @var string|null $newPassword */
/** @var array{host:string, port:int} $connection */

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('terminal', 'icon icon-lg') ?> FTP / SFTP accounts</h1>
        <div class="page-head__sub">
            Each account gets an isolated home directory. Anything uploaded there is indexed into your file browser.
        </div>
    </div>
    <div class="page-head__actions">
        <button class="btn btn-primary" type="button" data-modal-open="modal-sftp"><?= icon('plus') ?> New account</button>
    </div>
</div>

<?php if ($newPassword !== null): ?>
    <div class="card mb-4" style="border-color: var(--accent)">
        <div class="card__head"><?= icon('check-circle', 'icon text-success') ?><h3>Account password</h3></div>
        <div class="card__body">
            <p class="small muted">Copy it now — it is stored hashed and cannot be shown again.</p>
            <div class="input-copy">
                <input class="input mono" id="new-sftp-pass" readonly value="<?= e($newPassword) ?>">
                <button class="btn btn-primary btn-icon" type="button" data-copy="#new-sftp-pass" data-copy-label="Password"><?= icon('clipboard') ?></button>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <?= icon('info') ?>
    <div class="alert__body">
        <div class="alert__title">Connection details</div>
        Host <span class="code-inline"><?= e($connection['host']) ?></span> ·
        Port <span class="code-inline"><?= e((string) $connection['port']) ?></span> ·
        Protocol <span class="code-inline">SFTP</span>. Use the username and password from the table below.
    </div>
</div>

<div class="card">
    <div class="card__head"><?= icon('server') ?><h2>Your accounts</h2><span class="badge"><?= e((string) count($accounts)) ?></span></div>
    <div class="card__body card__body--flush">
        <?php if ($accounts === []): ?>
            <div class="empty">
                <div class="empty__icon"><?= icon('terminal', 'icon icon-hero') ?></div>
                <div class="empty__title">No FTP/SFTP accounts</div>
                <div class="empty__text">Create one to upload files with FileZilla, WinSCP, rsync or any SFTP client.</div>
                <button class="btn btn-primary" type="button" data-modal-open="modal-sftp"><?= icon('plus') ?> Create account</button>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th>Username</th><th>Protocol</th><th>Access</th><th>Usage</th><th>SSH keys</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($accounts as $account): ?>
                        <?php
                        $public = SftpAccount::publicArray($account);
                        $accountKeys = $keys[(int) $account['id']] ?? [];
                        $usagePercent = $public['quota_bytes'] > 0
                            ? min(100, round($public['used_bytes'] / $public['quota_bytes'] * 100))
                            : 0;
                        ?>
                        <tr>
                            <td>
                                <span class="strong mono"><?= e($public['username']) ?></span>
                                <div class="faint small truncate" style="max-width:260px"><?= e($public['home_dir']) ?></div>
                            </td>
                            <td><span class="badge badge-accent"><?= e(strtoupper($public['protocol'])) ?></span></td>
                            <td>
                                <span class="badge <?= $public['permission'] === 'rw' ? 'badge-success' : 'badge-warning' ?>">
                                    <?= icon($public['permission'] === 'rw' ? 'edit' : 'eye') ?>
                                    <?= $public['permission'] === 'rw' ? 'read/write' : 'read only' ?>
                                </span>
                            </td>
                            <td style="min-width:140px">
                                <div class="small tabular"><?= e(bytes($public['used_bytes'])) ?> / <?= e(bytes($public['quota_bytes'])) ?></div>
                                <div class="progress mt-1"><div class="progress__bar <?= $usagePercent >= 90 ? 'is-danger' : '' ?>" style="width: <?= e((string) $usagePercent) ?>%"></div></div>
                            </td>
                            <td>
                                <span class="badge"><?= icon('key') ?> <?= e((string) count($accountKeys)) ?></span>
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
                                                data-modal-open="modal-sftp-edit"
                                                data-modal-action="<?= e(url('/settings/sftp/' . $account['id'] . '/update')) ?>"><?= icon('edit') ?> Edit</button>

                                        <button class="dropdown__item" type="button"
                                                data-modal-open="modal-sftp-key"
                                                data-modal-action="<?= e(url('/settings/sftp/' . $account['id'] . '/keys')) ?>"><?= icon('key') ?> Add SSH key</button>

                                        <form method="post" action="<?= e(url('/settings/sftp/' . $account['id'] . '/password')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="dropdown__item" type="submit"><?= icon('refresh') ?> Reset password</button>
                                        </form>

                                        <form method="post" action="<?= e(url('/settings/sftp/' . $account['id'] . '/sync')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="dropdown__item" type="submit"><?= icon('refresh') ?> Sync files now</button>
                                        </form>

                                        <div class="dropdown__divider"></div>
                                        <form method="post" action="<?= e(url('/settings/sftp/' . $account['id'] . '/delete')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="dropdown__item is-danger" type="submit"
                                                    data-confirm="Delete this account? Its files stay on disk unless you also remove them."><?= icon('trash') ?> Delete account</button>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>

                        <?php if ($accountKeys !== []): ?>
                            <tr>
                                <td colspan="7" style="background: var(--surface-2)">
                                    <div class="label">SSH keys for <?= e($public['username']) ?></div>
                                    <?php foreach ($accountKeys as $key): ?>
                                        <?php $keyData = SftpKey::publicArray($key); ?>
                                        <div class="flex items-center gap-2 mb-1">
                                            <?= icon('key', 'icon icon-sm text-accent') ?>
                                            <span class="strong small"><?= e($keyData['name']) ?></span>
                                            <span class="badge"><?= e($keyData['key_type']) ?></span>
                                            <span class="mono faint small truncate flex-1"><?= e($keyData['fingerprint']) ?></span>
                                            <form method="post" action="<?= e(url('/settings/sftp/' . $account['id'] . '/keys/' . $key['id'] . '/delete')) ?>">
                                                <?= csrf_field() ?>
                                                <button class="btn btn-sm btn-ghost btn-icon" type="submit" data-confirm="Remove this SSH key?"><?= icon('x') ?></button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card__head"><?= icon('activity') ?><h2>Active transfer sessions</h2></div>
    <div class="card__body card__body--flush">
        <?php if ($sessions === []): ?>
            <div class="card__body"><p class="faint small">No live sessions.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Account</th><th>IP</th><th>Transferred</th><th class="nowrap">Started</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($sessions as $session): ?>
                        <?php $data = SftpSession::publicArray($session); ?>
                        <tr>
                            <td class="mono"><?= e((string) $data['username']) ?></td>
                            <td class="mono small"><?= e((string) $data['ip']) ?></td>
                            <td class="small tabular">
                                <?= icon('upload', 'icon icon-sm') ?> <?= e(bytes($data['bytes_in'])) ?>
                                <?= icon('download', 'icon icon-sm') ?> <?= e(bytes($data['bytes_out'])) ?>
                            </td>
                            <td class="nowrap faint"><?= e(time_ago($data['started_at'])) ?></td>
                            <td class="right">
                                <form method="post" action="<?= e(url('/settings/sftp/sessions/' . $session['id'] . '/close')) ?>">
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

<!-- Modals -->
<div class="modal-backdrop hidden" id="modal-sftp">
    <div class="modal">
        <form method="post" action="<?= e(url('/settings/sftp')) ?>">
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('terminal') ?><h3>New FTP/SFTP account</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="field">
                    <label class="label" for="sftp-user">Username <span class="req">*</span></label>
                    <input class="input mono" id="sftp-user" name="username" required minlength="3" maxlength="32"
                           pattern="[a-zA-Z0-9._\-]+" placeholder="backup-agent">
                    <div class="hint">3–32 characters: letters, digits, dot, dash or underscore.</div>
                </div>
                <div class="field">
                    <label class="label" for="sftp-pass">Password <span class="req">*</span></label>
                    <input class="input" id="sftp-pass" name="password" type="text" required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-row">
                    <div class="field">
                        <label class="label">Protocol</label>
                        <select class="select" name="protocol">
                            <option value="sftp">SFTP (SSH)</option>
                            <option value="ftps">FTPS (TLS)</option>
                            <option value="ftp">FTP (plain)</option>
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
                <div class="field">
                    <label class="label" for="sftp-quota">Quota (bytes)</label>
                    <input class="input" id="sftp-quota" name="quota_bytes" type="number" value="1073741824" min="0">
                    <div class="hint">1073741824 = 1 GB. Use 0 for unlimited.</div>
                </div>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Create account</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop hidden" id="modal-sftp-edit">
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
                            <option value="sftp">SFTP (SSH)</option>
                            <option value="ftps">FTPS (TLS)</option>
                            <option value="ftp">FTP (plain)</option>
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
                        <label class="label">Quota (bytes)</label>
                        <input class="input" name="quota_bytes" type="number" min="0">
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

<div class="modal-backdrop hidden" id="modal-sftp-key">
    <div class="modal modal--wide">
        <form method="post" action="">
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('key') ?><h3>Add SSH public key</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="field">
                    <label class="label">Key name <span class="req">*</span></label>
                    <input class="input" name="name" required maxlength="120" placeholder="laptop">
                </div>
                <div class="field">
                    <label class="label">Public key <span class="req">*</span></label>
                    <textarea class="textarea" name="public_key" rows="4" required
                              placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA... user@host"></textarea>
                    <div class="hint">Paste the contents of your .pub file. RSA, Ed25519 and ECDSA are supported.</div>
                </div>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Add key</button>
            </div>
        </form>
    </div>
</div>
<?php
View::endSection();
