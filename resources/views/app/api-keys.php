<?php

use App\Core\View;
use App\Models\ApiKey;

View::extend('layouts.app');
View::share('title', 'API keys');

/** @var list<array> $keys */
/** @var array<string,string> $scopes */
/** @var string|null $plainKey */

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('key', 'icon icon-lg') ?> API keys</h1>
        <div class="page-head__sub">Scoped machine credentials for scripts, servers and mobile apps.</div>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/api-docs')) ?>"><?= icon('book') ?> API reference</a>
        <button class="btn btn-primary" type="button" data-modal-open="modal-key"><?= icon('plus') ?> New API key</button>
    </div>
</div>

<?php if ($plainKey !== null): ?>
    <div class="card mb-4" style="border-color: var(--accent)">
        <div class="card__head"><?= icon('check-circle', 'icon text-success') ?><h3>Your new API key</h3></div>
        <div class="card__body">
            <p class="small muted">Copy it now — for security it is stored hashed and cannot be shown again.</p>
            <div class="input-copy">
                <input class="input mono" id="new-key" readonly value="<?= e($plainKey) ?>">
                <button class="btn btn-primary btn-icon" type="button" data-copy="#new-key" data-copy-label="API key"><?= icon('clipboard') ?></button>
            </div>
            <div class="mt-3">
                <div class="label">Example request</div>
                <pre class="code-block">curl -H "X-Api-Key: <?= e($plainKey) ?>" \
     <?= e(url('/api/v1/files')) ?></pre>
            </div>
        </div>
        <div class="card__foot">
            <form method="post" action="<?= e(url('/settings/api-keys/dismiss')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-sm" type="submit"><?= icon('check') ?> I have copied it</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card__body card__body--flush">
        <?php if ($keys === []): ?>
            <div class="empty">
                <div class="empty__icon"><?= icon('key', 'icon icon-hero') ?></div>
                <div class="empty__title">No API keys yet</div>
                <div class="empty__text">Create a scoped key to drive the platform from your own code.</div>
                <button class="btn btn-primary" type="button" data-modal-open="modal-key"><?= icon('plus') ?> Create your first key</button>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th>Name</th><th>Prefix</th><th>Scopes</th><th class="nowrap">Last used</th><th class="nowrap">Calls</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($keys as $key): ?>
                        <?php $public = ApiKey::publicArray($key); ?>
                        <tr>
                            <td>
                                <span class="strong"><?= e($public['name']) ?></span>
                                <div class="faint small">Created <?= e(time_ago($public['created_at'])) ?></div>
                            </td>
                            <td class="mono small">s3k_<?= e($public['prefix']) ?>…</td>
                            <td>
                                <div class="flex gap-1 flex-wrap" style="max-width:280px">
                                    <?php foreach ($public['scopes'] as $scope): ?>
                                        <span class="badge"><?= e((string) $scope) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td class="nowrap faint"><?= e(time_ago($public['last_used_at'])) ?></td>
                            <td class="tabular"><?= e(number_format($public['usage_count'])) ?></td>
                            <td>
                                <?php if ($public['active']): ?>
                                    <span class="badge badge-success"><span class="dot dot-success"></span> active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">revoked</span>
                                <?php endif; ?>
                            </td>
                            <td class="right">
                                <div class="actions">
                                    <?php if ($public['active']): ?>
                                        <form method="post" action="<?= e(url('/settings/api-keys/' . $key['id'] . '/revoke')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm" type="submit" data-confirm="Revoke this key? Any client using it will stop working."><?= icon('lock') ?> Revoke</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e(url('/settings/api-keys/' . $key['id'] . '/delete')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-danger btn-icon" type="submit" data-confirm="Delete this key permanently?" title="Delete"><?= icon('trash') ?></button>
                                    </form>
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

<div class="modal-backdrop hidden" id="modal-key">
    <div class="modal modal--wide">
        <form method="post" action="<?= e(url('/settings/api-keys')) ?>">
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('key') ?><h3>New API key</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="field">
                    <label class="label" for="key-name">Name <span class="req">*</span></label>
                    <input class="input" id="key-name" name="name" required maxlength="120" placeholder="Backup server">
                </div>

                <div class="field">
                    <label class="label">Scopes</label>
                    <div class="grid grid-2" style="gap:2px 16px">
                        <?php foreach ($scopes as $scope => $description): ?>
                            <label class="check">
                                <input type="checkbox" name="scopes[]" value="<?= e($scope) ?>" <?= $scope === 'files:read' ? 'checked' : '' ?>>
                                <span>
                                    <span class="check__text mono"><?= e($scope) ?></span>
                                    <span class="check__desc"><?= e($description) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label class="label" for="key-expires">Expires at <span class="faint">(optional)</span></label>
                        <input class="input" id="key-expires" name="expires_at" type="date">
                    </div>
                    <div class="field">
                        <label class="label" for="key-ips">IP allowlist <span class="faint">(optional)</span></label>
                        <textarea class="textarea" id="key-ips" name="ip_allowlist" rows="3"
                                  placeholder="203.0.113.4&#10;10.0.0.0/8"></textarea>
                        <div class="hint">One IP or CIDR per line. Leave empty to allow any address.</div>
                    </div>
                </div>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('key') ?> Create key</button>
            </div>
        </form>
    </div>
</div>
<?php
View::endSection();
