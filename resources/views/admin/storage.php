<?php

use App\Core\View;

View::extend('layouts.app');
View::share('title', 'Storage backends');

/** @var list<array> $backends */
/** @var array<string, array{files:int, versions:int, bytes:int}> $usage */
/** @var array<string, string> $drivers */
/** @var string $defaultSlug */
/** @var array{ftp:bool, sftp:bool} $capabilities */

$driverIcons = [
    'local' => 'hard-drive',
    'ftp'   => 'globe',
    'ftps'  => 'lock',
    'sftp'  => 'terminal',
    's3'    => 'cloud',
];

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('hard-drive', 'icon icon-lg') ?> Storage backends</h1>
        <div class="page-head__sub">
            Keep files on this server or push them to your own FTP, FTPS, SFTP or S3 storage.
            New uploads go to whichever backend is marked as the default.
        </div>
    </div>
    <div class="page-head__actions">
        <button class="btn btn-primary" type="button" data-modal-open="modal-storage-new"><?= icon('plus') ?> Add backend</button>
    </div>
</div>

<div class="alert alert-info">
    <?= icon('info') ?>
    <div class="alert__body">
        <div class="alert__title">How routing works</div>
        Every file remembers the backend it was written to, so switching the default never breaks existing
        downloads. To move older files across, use <strong>Migrate files</strong> — it runs as a background
        job and only deletes the source copy once the destination has verified the bytes.
        API clients can override the destination per upload with a
        <span class="code-inline">storage</span> field or an <span class="code-inline">X-Storage-Backend</span> header.
    </div>
</div>

<?php if (!$capabilities['ftp'] || !$capabilities['sftp']): ?>
    <div class="alert alert-warning">
        <?= icon('alert') ?>
        <div class="alert__body">
            <div class="alert__title">Not every protocol is available on this server</div>
            <?php if (!$capabilities['ftp']): ?>
                FTP and FTPS need the PHP <span class="code-inline">ftp</span> extension, which is not loaded.<br>
            <?php endif; ?>
            <?php if (!$capabilities['sftp']): ?>
                SFTP needs a cURL build with SSH support; this one has none.
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-3 mb-4">
    <?php foreach ($backends as $backend): ?>
        <?php
        $stats = $usage[$backend['slug']] ?? ['files' => 0, 'versions' => 0, 'bytes' => 0];
        $isDefault = $backend['slug'] === $defaultSlug;
        ?>
        <div class="stat <?= $backend['status'] === 'ok' ? 'stat--success' : '' ?>">
            <div class="stat__icon"><?= icon($driverIcons[$backend['driver']] ?? 'database') ?></div>
            <div class="stat__label">
                <?= e($backend['name']) ?>
                <?php if ($isDefault): ?><span class="badge badge-accent">default</span><?php endif; ?>
            </div>
            <div class="stat__value" style="font-size:17px"><?= e(bytes($stats['bytes'])) ?></div>
            <div class="stat__meta">
                <?= e((string) $stats['files']) ?> file<?= $stats['files'] === 1 ? '' : 's' ?>
                <?php if ($stats['versions'] > 0): ?>
                    + <?= e((string) $stats['versions']) ?> version<?= $stats['versions'] === 1 ? '' : 's' ?>
                <?php endif; ?>
                · <span class="mono"><?= e($backend['slug']) ?></span>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card__head"><?= icon('database') ?><h2>Backends</h2><span class="badge"><?= e((string) count($backends)) ?></span></div>
    <div class="card__body card__body--flush">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Name</th><th>Type</th><th>Target</th><th>Stored</th><th>Last check</th><th>State</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($backends as $backend): ?>
                    <?php
                    $stats = $usage[$backend['slug']] ?? ['files' => 0, 'versions' => 0, 'bytes' => 0];
                    $others = array_values(array_filter(
                        $backends,
                        static fn (array $b): bool => $b['slug'] !== $backend['slug'] && $b['is_active']
                    ));
                    ?>
                    <tr>
                        <td>
                            <span class="strong"><?= e($backend['name']) ?></span>
                            <?php if ($backend['is_default']): ?>
                                <span class="badge badge-accent">default</span>
                            <?php endif; ?>
                            <div class="faint small mono"><?= e($backend['slug']) ?></div>
                        </td>
                        <td>
                            <span class="badge">
                                <?= icon($driverIcons[$backend['driver']] ?? 'database') ?>
                                <?= e(strtoupper($backend['driver'])) ?>
                            </span>
                        </td>
                        <td class="small">
                            <span class="mono truncate" style="max-width:260px; display:inline-block">
                                <?= e(\App\Models\StorageBackend::endpointLabel([
                                    'driver'    => $backend['driver'],
                                    'host'      => $backend['host'],
                                    'port'      => $backend['port'],
                                    'root_path' => $backend['root_path'],
                                    'options'   => $backend['options'],
                                ])) ?>
                            </span>
                            <?php if ($backend['username'] !== null && $backend['username'] !== ''): ?>
                                <div class="faint small">as <?= e($backend['username']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small tabular">
                            <?= e(bytes($stats['bytes'])) ?>
                            <div class="faint"><?= e((string) ($stats['files'] + $stats['versions'])) ?> object(s)</div>
                        </td>
                        <td class="small nowrap faint">
                            <?= $backend['last_checked_at'] === null ? 'never' : e(time_ago($backend['last_checked_at'])) ?>
                            <?php if ($backend['status'] === 'error' && $backend['last_error'] !== null): ?>
                                <div class="text-danger truncate" style="max-width:220px"><?= e($backend['last_error']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$backend['is_active']): ?>
                                <span class="badge">disabled</span>
                            <?php elseif ($backend['status'] === 'ok'): ?>
                                <span class="badge badge-success"><span class="dot dot-success"></span> connected</span>
                            <?php elseif ($backend['status'] === 'error'): ?>
                                <span class="badge badge-danger">failing</span>
                            <?php else: ?>
                                <span class="badge">untested</span>
                            <?php endif; ?>
                        </td>
                        <td class="right">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-ghost btn-icon" type="button" data-dropdown><?= icon('more') ?></button>
                                <div class="dropdown__menu">
                                    <form method="post" action="<?= e(url('/admin/storage/' . $backend['id'] . '/test')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="dropdown__item" type="submit"><?= icon('activity') ?> Test connection</button>
                                    </form>

                                    <button class="dropdown__item" type="button"
                                            data-modal-open="modal-storage-edit"
                                            data-modal-action="<?= e(url('/admin/storage/' . $backend['id'] . '/update')) ?>"
                                            data-modal-title="Edit <?= e($backend['name']) ?>"
                                            data-fill-driver="<?= e($backend['driver']) ?>"
                                            data-fill-driver_label="<?= e(strtoupper($backend['driver'])) ?>"
                                            <?php // Checkboxes are set by our own script: data-fill-* only assigns values. ?>
                                            data-check-passive="<?= ($backend['options']['passive'] ?? true) ? '1' : '0' ?>"
                                            data-check-use_pasv_address="<?= ($backend['options']['use_pasv_address'] ?? true) ? '1' : '0' ?>"
                                            data-check-is_active="<?= $backend['is_active'] ? '1' : '0' ?>"
                                            data-check-is_default="<?= $backend['is_default'] ? '1' : '0' ?>"
                                            data-fill-name="<?= e($backend['name']) ?>"
                                            data-fill-host="<?= e((string) $backend['host']) ?>"
                                            data-fill-port="<?= e((string) $backend['port']) ?>"
                                            data-fill-username="<?= e((string) $backend['username']) ?>"
                                            data-fill-root_path="<?= e($backend['root_path']) ?>"
                                            data-fill-endpoint="<?= e((string) ($backend['options']['endpoint'] ?? '')) ?>"
                                            data-fill-region="<?= e((string) ($backend['options']['region'] ?? '')) ?>"
                                            data-fill-bucket="<?= e((string) ($backend['options']['bucket'] ?? '')) ?>"
                                            data-fill-access_key="<?= e((string) ($backend['options']['access_key'] ?? '')) ?>"
                                            data-fill-host_fingerprint="<?= e((string) ($backend['options']['host_fingerprint'] ?? '')) ?>"
                                            data-fill-timeout="<?= e((string) ($backend['options']['timeout'] ?? 30)) ?>"><?= icon('edit') ?> Edit</button>

                                    <?php if (!$backend['is_default'] && $backend['is_active']): ?>
                                        <form method="post" action="<?= e(url('/admin/storage/' . $backend['id'] . '/default')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="dropdown__item" type="submit"><?= icon('check-circle') ?> Make default</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($others !== [] && ($stats['files'] + $stats['versions']) > 0): ?>
                                        <form method="post" action="<?= e(url('/admin/storage/' . $backend['id'] . '/migrate')) ?>">
                                            <?= csrf_field() ?>
                                            <div class="dropdown__item" style="gap:6px">
                                                <?= icon('move') ?>
                                                <select class="select" name="to" style="height:28px; font-size:12px">
                                                    <?php foreach ($others as $other): ?>
                                                        <option value="<?= e($other['slug']) ?>"><?= e($other['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <button class="dropdown__item" type="submit"
                                                    data-confirm="Queue a background job to move every file off this backend?"><?= icon('zap') ?> Migrate files</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (!$backend['is_default']): ?>
                                        <div class="dropdown__divider"></div>
                                        <form method="post" action="<?= e(url('/admin/storage/' . $backend['id'] . '/delete')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="dropdown__item is-danger" type="submit"
                                                    data-confirm="Delete this backend? Its files must be migrated first."><?= icon('trash') ?> Delete</button>
                                        </form>
                                    <?php endif; ?>
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

<div class="modal-backdrop hidden" id="modal-storage-new">
    <div class="modal">
        <form method="post" action="<?= e(url('/admin/storage')) ?>" data-storage-form>
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('hard-drive') ?><h3>Add a storage backend</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="form-row">
                    <div class="field">
                        <label class="label">Name <span class="req">*</span></label>
                        <input class="input" name="name" required maxlength="100" placeholder="Backup FTP box">
                    </div>
                    <div class="field">
                        <label class="label">Type <span class="req">*</span></label>
                        <select class="select" name="driver" data-storage-driver required>
                            <?php foreach ($drivers as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $key === 'ftp' ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div data-storage-fields="ftp ftps sftp">
                    <div class="form-row">
                        <div class="field">
                            <label class="label">Host <span class="req">*</span></label>
                            <input class="input mono" name="host" placeholder="ftp.example.com">
                        </div>
                        <div class="field">
                            <label class="label">Port</label>
                            <input class="input" name="port" type="number" min="1" max="65535" placeholder="21">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="field">
                            <label class="label">Username <span class="req">*</span></label>
                            <input class="input mono" name="username" autocomplete="off">
                        </div>
                        <div class="field">
                            <label class="label">Password</label>
                            <input class="input" name="password" type="password" autocomplete="new-password">
                        </div>
                    </div>
                </div>

                <div data-storage-fields="sftp">
                    <div class="field">
                        <label class="label">Private key (optional, instead of a password)</label>
                        <textarea class="input mono" name="private_key" rows="4" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="field">
                            <label class="label">Key passphrase</label>
                            <input class="input" name="passphrase" type="password" autocomplete="new-password">
                        </div>
                        <div class="field">
                            <label class="label">Host fingerprint</label>
                            <input class="input mono" name="host_fingerprint" placeholder="MD5 hex or base64 SHA-256">
                        </div>
                    </div>
                </div>

                <?php // Spacing sits on the wrapper: p:last-child zeroes a margin on the hint itself. ?>
                <div class="mb-4" data-storage-fields="ftp ftps">
                    <div class="form-row">
                        <label class="check">
                            <input type="hidden" name="passive" value="0">
                            <input type="checkbox" name="passive" value="1" checked> Passive mode
                        </label>
                        <label class="check">
                            <input type="hidden" name="use_pasv_address" value="0">
                            <input type="checkbox" name="use_pasv_address" value="1" checked> Trust the address in PASV replies
                        </label>
                    </div>
                    <p class="faint small">Turn the second one off when the server sits behind NAT and transfers hang.</p>
                </div>

                <div data-storage-fields="s3">
                    <div class="form-row">
                        <div class="field">
                            <label class="label">Endpoint</label>
                            <input class="input mono" name="endpoint" placeholder="https://s3.eu-central-1.amazonaws.com">
                        </div>
                        <div class="field">
                            <label class="label">Region</label>
                            <input class="input" name="region" placeholder="us-east-1">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="field">
                            <label class="label">Bucket</label>
                            <input class="input mono" name="bucket">
                        </div>
                        <div class="field">
                            <label class="label">Access key</label>
                            <input class="input mono" name="access_key" autocomplete="off">
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Secret key</label>
                        <input class="input" name="secret_key" type="password" autocomplete="new-password">
                    </div>
                </div>

                <div class="form-row" data-storage-fields="ftp ftps sftp s3">
                    <div class="field">
                        <label class="label">Base directory</label>
                        <input class="input mono" name="root_path" data-storage-root placeholder="s3lite">
                    </div>
                    <div class="field">
                        <label class="label">Timeout (seconds)</label>
                        <input class="input" name="timeout" type="number" min="5" max="600" value="30">
                    </div>
                </div>

                <div class="field" data-storage-fields="local">
                    <label class="label">Storage directory</label>
                    <input class="input mono" name="root_path" data-storage-root placeholder="leave blank for storage/files">
                </div>

                <div class="form-row mt-1">
                    <label class="check">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" checked> Enabled
                    </label>
                    <label class="check">
                        <input type="hidden" name="is_default" value="0">
                        <input type="checkbox" name="is_default" value="1"> Send new uploads here
                    </label>
                </div>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Add and test</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop hidden" id="modal-storage-edit">
    <div class="modal">
        <form method="post" action="" data-storage-form>
            <?= csrf_field() ?>
            <?php // The driver cannot change after creation; it only steers which fields show. ?>
            <input type="hidden" name="driver" value="local" data-storage-driver>
            <div class="modal__head"><?= icon('edit') ?><h3 data-modal-heading>Edit backend</h3>
                <span class="badge" data-fill-target="driver_label">LOCAL</span>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="field">
                    <label class="label">Name <span class="req">*</span></label>
                    <input class="input" name="name" required maxlength="100">
                </div>

                <div data-storage-fields="ftp ftps sftp">
                    <div class="form-row">
                        <div class="field">
                            <label class="label">Host</label>
                            <input class="input mono" name="host">
                        </div>
                        <div class="field">
                            <label class="label">Port</label>
                            <input class="input" name="port" type="number" min="1" max="65535">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="field">
                            <label class="label">Username</label>
                            <input class="input mono" name="username" autocomplete="off">
                        </div>
                        <div class="field">
                            <label class="label">Password</label>
                            <input class="input" name="password" type="password" autocomplete="new-password" placeholder="unchanged">
                        </div>
                    </div>
                </div>

                <div data-storage-fields="sftp">
                    <div class="field">
                        <label class="label">Private key</label>
                        <textarea class="input mono" name="private_key" rows="3" placeholder="unchanged"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="field">
                            <label class="label">Key passphrase</label>
                            <input class="input" name="passphrase" type="password" autocomplete="new-password" placeholder="unchanged">
                        </div>
                        <div class="field">
                            <label class="label">Host fingerprint</label>
                            <input class="input mono" name="host_fingerprint">
                        </div>
                    </div>
                </div>

                <?php // Spacing sits on the wrapper: p:last-child zeroes a margin on the hint itself. ?>
                <div class="mb-4" data-storage-fields="ftp ftps">
                    <div class="form-row">
                        <label class="check">
                            <input type="hidden" name="passive" value="0">
                            <input type="checkbox" name="passive" value="1" checked> Passive mode
                        </label>
                        <label class="check">
                            <input type="hidden" name="use_pasv_address" value="0">
                            <input type="checkbox" name="use_pasv_address" value="1" checked> Trust the address in PASV replies
                        </label>
                    </div>
                </div>

                <div data-storage-fields="s3">
                    <div class="form-row">
                        <div class="field">
                            <label class="label">Endpoint</label>
                            <input class="input mono" name="endpoint">
                        </div>
                        <div class="field">
                            <label class="label">Region</label>
                            <input class="input" name="region">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="field">
                            <label class="label">Bucket</label>
                            <input class="input mono" name="bucket">
                        </div>
                        <div class="field">
                            <label class="label">Access key</label>
                            <input class="input mono" name="access_key" autocomplete="off">
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Secret key</label>
                        <input class="input" name="secret_key" type="password" autocomplete="new-password" placeholder="unchanged">
                    </div>
                </div>

                <div class="form-row" data-storage-fields="ftp ftps sftp s3">
                    <div class="field">
                        <label class="label">Base directory</label>
                        <input class="input mono" name="root_path" data-storage-root>
                    </div>
                    <div class="field">
                        <label class="label">Timeout (seconds)</label>
                        <input class="input" name="timeout" type="number" min="5" max="600">
                    </div>
                </div>

                <div class="field" data-storage-fields="local">
                    <label class="label">Storage directory</label>
                    <input class="input mono" name="root_path" data-storage-root placeholder="leave blank for storage/files">
                </div>

                <div class="form-row mt-1">
                    <label class="check">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" checked> Enabled
                    </label>
                    <label class="check">
                        <input type="hidden" name="is_default" value="0">
                        <input type="checkbox" name="is_default" value="1"> Send new uploads here
                    </label>
                </div>

                <p class="faint small mt-2" data-storage-fields="ftp ftps sftp s3">
                    Leave the credential fields blank to keep what is already stored.
                </p>
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

View::startSection('scripts');
?>
<script>
// A backend only needs a handful of its fields: an FTP box has no bucket, the
// local disk has no password. Show what applies and disable the rest, so
// nothing irrelevant is posted either.
(function () {
    const forms = document.querySelectorAll('[data-storage-form]');
    if (!forms.length) return;

    function apply(form) {
        const source = form.querySelector('[data-storage-driver]');
        if (!source) return;

        const driver = source.value;
        const remote = ['ftp', 'ftps', 'sftp'].indexOf(driver) !== -1;

        form.querySelectorAll('[data-storage-fields]').forEach(function (group) {
            const show = group.getAttribute('data-storage-fields').split(' ').indexOf(driver) !== -1;

            // The class carries !important; the bare `hidden` attribute loses
            // to any explicit display rule, and .form-row sets display: grid.
            group.classList.toggle('hidden', !show);
            group.hidden = !show;

            group.querySelectorAll('input, textarea, select').forEach(function (field) {
                field.disabled = !show;
            });
        });

        const host = form.querySelector('[name="host"]');
        const username = form.querySelector('[name="username"]');
        if (host) host.required = remote;
        if (username) username.required = remote;

        const port = form.querySelector('[name="port"]');
        if (port) port.placeholder = driver === 'sftp' ? '22' : '21';
    }

    forms.forEach(function (form) {
        const source = form.querySelector('[data-storage-driver]');
        if (source && source.tagName === 'SELECT') {
            source.addEventListener('change', function () { apply(form); });
        }
        apply(form);
    });

    // The edit modal is filled in by the shared data-fill-* handler when it
    // opens, so re-apply afterwards with the driver it just received. Checkbox
    // states come from data-check-* — data-fill-* only assigns values, which
    // would leave a box visually unticked but still posting "1".
    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-modal-open="modal-storage-edit"]');
        if (!trigger) return;

        const form = document.querySelector('#modal-storage-edit [data-storage-form]');
        if (!form) return;

        Array.from(trigger.attributes).forEach(function (attr) {
            if (!attr.name.startsWith('data-check-')) return;
            const box = form.querySelector('input[type="checkbox"][name="' + attr.name.slice(11) + '"]');
            if (box) box.checked = attr.value === '1';
        });

        apply(form);
    });
})();
</script>
<?php
View::endSection();
