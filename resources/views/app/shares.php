<?php

use App\Core\View;
use App\Models\Share;

View::extend('layouts.app');
View::share('title', 'Share links');

/** @var array $shares */

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('link', 'icon icon-lg') ?> Share links</h1>
        <div class="page-head__sub">Permanent and expiring links you have created, with their access counts.</div>
    </div>
</div>

<div class="card">
    <div class="card__body card__body--flush">
        <?php if ($shares['data'] === []): ?>
            <div class="empty">
                <div class="empty__icon"><?= icon('link', 'icon icon-hero') ?></div>
                <div class="empty__title">No share links yet</div>
                <div class="empty__text">Open a file and choose “Share link” to create one.</div>
                <a class="btn btn-primary" href="<?= e(url('/files')) ?>"><?= icon('folder') ?> Browse files</a>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Item</th><th>Link</th><th>Protection</th>
                        <th class="nowrap">Downloads</th><th class="nowrap">Expires</th><th>Status</th><th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($shares['data'] as $share): ?>
                        <?php $public = Share::publicArray($share); ?>
                        <tr>
                            <td>
                                <div class="flex items-center gap-2">
                                    <?= icon($share['file_id'] !== null ? 'file' : 'folder', 'icon ' . ($share['file_id'] !== null ? 'kind-file' : 'text-accent')) ?>
                                    <span class="truncate" style="max-width:220px">
                                        <?= e((string) ($share['file_name'] ?? $share['folder_name'] ?? 'Removed item')) ?>
                                    </span>
                                </div>
                                <?php if ($share['file_size'] !== null): ?>
                                    <div class="faint small"><?= e(bytes((int) $share['file_size'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="input-copy" style="max-width:280px">
                                    <input class="input" readonly value="<?= e($public['url']) ?>" id="link-<?= e((string) $share['id']) ?>">
                                    <button class="btn btn-icon" type="button" data-copy="#link-<?= e((string) $share['id']) ?>" data-copy-label="Link"><?= icon('clipboard') ?></button>
                                    <a class="btn btn-icon" href="<?= e($public['url']) ?>" target="_blank" rel="noopener" title="Open"><?= icon('external') ?></a>
                                </div>
                            </td>
                            <td>
                                <?php if ($public['password_protected']): ?>
                                    <span class="badge badge-warning"><?= icon('lock') ?> password</span>
                                <?php else: ?>
                                    <span class="badge"><?= icon('globe') ?> public</span>
                                <?php endif; ?>
                            </td>
                            <td class="tabular">
                                <?= e((string) $public['download_count']) ?><?= $public['max_downloads'] !== null ? ' / ' . e((string) $public['max_downloads']) : '' ?>
                            </td>
                            <td class="nowrap faint">
                                <?= $public['expires_at'] === null ? 'Never' : e(date('d M Y H:i', strtotime((string) $public['expires_at']))) ?>
                            </td>
                            <td>
                                <?php if ($public['usable']): ?>
                                    <span class="badge badge-success"><span class="dot dot-success"></span> active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><?= icon('x') ?> inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="right">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-ghost btn-icon" type="button" data-dropdown><?= icon('more') ?></button>
                                    <div class="dropdown__menu">
                                        <button class="dropdown__item" type="button"
                                                data-modal-open="modal-share-edit"
                                                data-modal-action="<?= e(url('/shares/' . $share['id'] . '/update')) ?>"
                                                data-fill-max_downloads="<?= e((string) ($public['max_downloads'] ?? '')) ?>"
                                                data-fill-expires_at="<?= e($public['expires_at'] === null ? '' : date('Y-m-d\TH:i', strtotime((string) $public['expires_at']))) ?>">
                                            <?= icon('edit') ?> Edit link
                                        </button>
                                        <form method="post" action="<?= e(url('/shares/' . $share['id'] . '/update')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="is_active" value="<?= $public['is_active'] ? '0' : '1' ?>">
                                            <button class="dropdown__item" type="submit">
                                                <?= icon($public['is_active'] ? 'pause' : 'play') ?>
                                                <?= $public['is_active'] ? 'Disable' : 'Enable' ?>
                                            </button>
                                        </form>
                                        <div class="dropdown__divider"></div>
                                        <form method="post" action="<?= e(url('/shares/' . $share['id'] . '/revoke')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="dropdown__item is-danger" type="submit" data-confirm="Revoke this link permanently?">
                                                <?= icon('link-off') ?> Revoke
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
        <?php endif; ?>
    </div>
</div>

<?= View::include('partials.pagination', ['result' => $shares]) ?>

<div class="modal-backdrop hidden" id="modal-share-edit">
    <div class="modal">
        <form method="post" action="">
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('edit') ?><h3>Edit share link</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="field">
                    <label class="label">Expires at</label>
                    <input class="input" type="datetime-local" name="expires_at">
                    <div class="hint">Clear the field to make the link permanent.</div>
                </div>
                <div class="field">
                    <label class="label">Download limit</label>
                    <input class="input" type="number" name="max_downloads" min="0" placeholder="Unlimited">
                </div>
                <div class="field">
                    <label class="label">New password</label>
                    <input class="input" type="text" name="password" autocomplete="off" placeholder="Leave blank to remove">
                </div>
                <label class="check">
                    <input type="checkbox" name="allow_preview" value="1" checked>
                    <span class="check__text">Allow in-browser preview</span>
                </label>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('save') ?> Save changes</button>
            </div>
        </form>
    </div>
</div>
<?php
View::endSection();
