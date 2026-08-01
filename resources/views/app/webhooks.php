<?php

use App\Core\View;
use App\Models\Webhook;

View::extend('layouts.app');
View::share('title', 'Webhooks');

/** @var list<array> $webhooks */
/** @var list<string> $events */

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('webhook', 'icon icon-lg') ?> Webhooks</h1>
        <div class="page-head__sub">Get an HTTP callback whenever something happens in your storage.</div>
    </div>
    <div class="page-head__actions">
        <button class="btn btn-primary" type="button" data-modal-open="modal-webhook"><?= icon('plus') ?> New webhook</button>
    </div>
</div>

<div class="alert alert-info">
    <?= icon('info') ?>
    <div class="alert__body">
        Every delivery is signed. Verify the <span class="code-inline">X-S3Lite-Signature</span> header:
        <span class="code-inline">sha256=HMAC_SHA256(body, secret)</span>.
    </div>
</div>

<div class="card">
    <div class="card__body card__body--flush">
        <?php if ($webhooks === []): ?>
            <div class="empty">
                <div class="empty__icon"><?= icon('webhook', 'icon icon-hero') ?></div>
                <div class="empty__title">No webhooks configured</div>
                <div class="empty__text">Subscribe an endpoint to upload, download, share and login events.</div>
                <button class="btn btn-primary" type="button" data-modal-open="modal-webhook"><?= icon('plus') ?> Add a webhook</button>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Name</th><th>Endpoint</th><th>Events</th><th class="nowrap">Last fired</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($webhooks as $hook): ?>
                        <?php $public = Webhook::publicArray($hook); ?>
                        <tr>
                            <td class="strong"><?= e($public['name']) ?></td>
                            <td class="mono small truncate" style="max-width:260px"><?= e($public['url']) ?></td>
                            <td>
                                <div class="flex gap-1 flex-wrap" style="max-width:260px">
                                    <?php foreach ($public['events'] as $event): ?>
                                        <span class="badge"><?= e((string) $event) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td class="nowrap faint"><?= e(time_ago($public['last_fired_at'])) ?></td>
                            <td>
                                <?php if ($public['is_active']): ?>
                                    <span class="badge badge-success"><span class="dot dot-success"></span> active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">disabled</span>
                                <?php endif; ?>
                                <?php if ($public['failures'] > 0): ?>
                                    <span class="badge badge-warning"><?= e((string) $public['failures']) ?> failures</span>
                                <?php endif; ?>
                            </td>
                            <td class="right">
                                <div class="actions">
                                    <form method="post" action="<?= e(url('/settings/webhooks/' . $hook['id'] . '/test')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm" type="submit"><?= icon('send') ?> Test</button>
                                    </form>
                                    <form method="post" action="<?= e(url('/settings/webhooks/' . $hook['id'] . '/delete')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-danger btn-icon" type="submit" data-confirm="Delete this webhook?" title="Delete"><?= icon('trash') ?></button>
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

<div class="modal-backdrop hidden" id="modal-webhook">
    <div class="modal modal--wide">
        <form method="post" action="<?= e(url('/settings/webhooks')) ?>">
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('webhook') ?><h3>New webhook</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="field">
                    <label class="label" for="hook-name">Name <span class="req">*</span></label>
                    <input class="input" id="hook-name" name="name" required maxlength="120" placeholder="Slack notifier">
                </div>
                <div class="field">
                    <label class="label" for="hook-url">Endpoint URL <span class="req">*</span></label>
                    <input class="input" id="hook-url" name="url" type="url" required placeholder="https://example.com/hooks/s3lite">
                </div>
                <div class="field">
                    <label class="label">Events</label>
                    <div class="grid grid-2" style="gap:2px 16px">
                        <?php foreach ($events as $event): ?>
                            <label class="check">
                                <input type="checkbox" name="events[]" value="<?= e($event) ?>">
                                <span class="check__text mono"><?= e($event) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Create webhook</button>
            </div>
        </form>
    </div>
</div>
<?php
View::endSection();
