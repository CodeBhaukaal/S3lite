<?php

use App\Core\View;

View::extend('layouts.app');
View::share('title', 'IP rules');

/** @var list<array> $rules */

$allowCount = count(array_filter($rules, static fn (array $r): bool => $r['type'] === 'allow'));

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('shield-check', 'icon icon-lg') ?> IP allow &amp; block rules</h1>
        <div class="page-head__sub">Applied to every request before authentication. Cached for 60 seconds.</div>
    </div>
    <div class="page-head__actions">
        <button class="btn btn-primary" type="button" data-modal-open="modal-ip"><?= icon('plus') ?> New rule</button>
    </div>
</div>

<?php if ($allowCount > 0): ?>
    <div class="alert alert-warning">
        <?= icon('alert') ?>
        <div class="alert__body">
            <div class="alert__title">An allowlist is active</div>
            Because at least one <span class="code-inline">allow</span> rule exists, every address that does not match one is refused.
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card__body card__body--flush">
        <?php if ($rules === []): ?>
            <div class="empty">
                <div class="empty__icon"><?= icon('globe', 'icon icon-hero') ?></div>
                <div class="empty__title">No IP restrictions</div>
                <div class="empty__text">Every address can reach the platform. Add a rule to restrict access.</div>
                <button class="btn btn-primary" type="button" data-modal-open="modal-ip"><?= icon('plus') ?> Add a rule</button>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Type</th><th>CIDR / pattern</th><th>Scope</th><th>Note</th><th class="nowrap">Added</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rules as $rule): ?>
                        <tr>
                            <td>
                                <span class="badge <?= $rule['type'] === 'block' ? 'badge-danger' : 'badge-success' ?>">
                                    <?= icon($rule['type'] === 'block' ? 'x-circle' : 'check-circle') ?> <?= e($rule['type']) ?>
                                </span>
                            </td>
                            <td class="mono"><?= e($rule['cidr']) ?></td>
                            <td><span class="badge"><?= e($rule['scope']) ?></span></td>
                            <td class="muted"><?= e((string) $rule['note']) ?></td>
                            <td class="nowrap faint"><?= e(time_ago($rule['created_at'])) ?></td>
                            <td class="right">
                                <form method="post" action="<?= e(url('/admin/ip-rules/' . $rule['id'] . '/delete')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-ghost btn-icon" type="submit" data-confirm="Remove this rule?" title="Remove"><?= icon('trash') ?></button>
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

<div class="modal-backdrop hidden" id="modal-ip">
    <div class="modal">
        <form method="post" action="<?= e(url('/admin/ip-rules')) ?>">
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('shield') ?><h3>New IP rule</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="field">
                    <label class="label">Rule type</label>
                    <select class="select" name="type">
                        <option value="block">Block — refuse this address</option>
                        <option value="allow">Allow — add to the allowlist</option>
                    </select>
                </div>
                <div class="field">
                    <label class="label">Address, CIDR or wildcard <span class="req">*</span></label>
                    <input class="input mono" name="cidr" required placeholder="203.0.113.0/24">
                    <div class="hint">Examples: <span class="code-inline">203.0.113.4</span>, <span class="code-inline">10.0.0.0/8</span>, <span class="code-inline">192.168.1.*</span></div>
                </div>
                <div class="field">
                    <label class="label">Scope</label>
                    <select class="select" name="scope">
                        <option value="global">Global — the whole platform</option>
                        <option value="api">API only</option>
                        <option value="panel">Web panel only</option>
                    </select>
                </div>
                <div class="field">
                    <label class="label">Note</label>
                    <input class="input" name="note" maxlength="255" placeholder="Why this rule exists">
                </div>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Add rule</button>
            </div>
        </form>
    </div>
</div>
<?php
View::endSection();
