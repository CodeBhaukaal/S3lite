<?php

use App\Core\View;
use App\Models\FileRecord;

View::extend('layouts.app');
View::share('title', 'Trash');

/** @var array $files */
/** @var list<array> $folders */
/** @var int $retention */
/** @var string $query */

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('trash', 'icon icon-lg') ?> Trash</h1>
        <div class="page-head__sub">
            Deleted items are kept for <?= e((string) $retention) ?> days, then removed automatically.
            They still count towards your quota until purged.
        </div>
    </div>
    <div class="page-head__actions">
        <form method="post" action="<?= e(url('/trash/empty')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-danger" type="submit"
                    data-confirm="Permanently delete everything in the trash? This cannot be undone.">
                <?= icon('trash') ?> Empty trash
            </button>
        </form>
    </div>
</div>

<form class="filters" method="get">
    <div class="field flex-1" style="max-width:340px">
        <div class="search">
            <?= icon('search') ?>
            <input class="input" type="search" name="q" placeholder="Search trash…" value="<?= e($query) ?>" data-search-input>
        </div>
    </div>
    <button class="btn" type="submit"><?= icon('filter') ?> Search</button>
</form>

<?php if ($folders !== []): ?>
    <div class="card mb-4">
        <div class="card__head"><?= icon('folder') ?><h2>Folders</h2><span class="badge"><?= e((string) count($folders)) ?></span></div>
        <div class="card__body card__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Name</th><th>Path</th><th class="nowrap">Deleted</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($folders as $folder): ?>
                        <tr>
                            <td><div class="flex items-center gap-2"><?= icon('folder', 'icon text-accent') ?><?= e($folder['name']) ?></div></td>
                            <td class="faint small"><?= e($folder['path']) ?></td>
                            <td class="nowrap faint"><?= e(time_ago($folder['deleted_at'])) ?></td>
                            <td class="right">
                                <div class="actions">
                                    <form method="post" action="<?= e(url('/trash/folders/' . $folder['id'] . '/restore')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm" type="submit"><?= icon('restore') ?> Restore</button>
                                    </form>
                                    <form method="post" action="<?= e(url('/trash/folders/' . $folder['id'] . '/purge')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-danger" type="submit"
                                                data-confirm="Permanently delete this folder and all files inside it?"><?= icon('trash') ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card__head"><?= icon('file') ?><h2>Files</h2><span class="badge"><?= e((string) $files['total']) ?></span></div>
    <div class="card__body card__body--flush">
        <?php if ($files['data'] === []): ?>
            <div class="empty">
                <div class="empty__icon"><?= icon('check-circle', 'icon icon-hero') ?></div>
                <div class="empty__title">Trash is empty</div>
                <div class="empty__text">Nothing here needs your attention.</div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Name</th><th class="nowrap">Size</th><th class="nowrap">Deleted</th><th class="nowrap">Auto-purge</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($files['data'] as $file): ?>
                        <?php
                        $kind = FileRecord::kind($file);
                        $purgeAt = strtotime((string) $file['deleted_at']) + ($retention * 86400);
                        ?>
                        <tr>
                            <td>
                                <div class="flex items-center gap-2">
                                    <?= icon(match ($kind) {
                                        'image' => 'image', 'video' => 'video', 'audio' => 'music',
                                        'pdf', 'text' => 'file-text', 'archive' => 'archive', default => 'file',
                                    }, 'icon kind-' . $kind) ?>
                                    <span class="truncate" style="max-width:320px"><?= e($file['name']) ?></span>
                                </div>
                            </td>
                            <td class="nowrap tabular"><?= e(bytes((int) $file['size'])) ?></td>
                            <td class="nowrap faint"><?= e(time_ago($file['deleted_at'])) ?></td>
                            <td class="nowrap faint"><?= e(date('d M Y', $purgeAt)) ?></td>
                            <td class="right">
                                <div class="actions">
                                    <form method="post" action="<?= e(url('/trash/files/' . $file['uuid'] . '/restore')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm" type="submit"><?= icon('restore') ?> Restore</button>
                                    </form>
                                    <form method="post" action="<?= e(url('/trash/files/' . $file['uuid'] . '/purge')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-danger btn-icon" type="submit"
                                                data-confirm="Permanently delete this file?" title="Delete permanently"><?= icon('trash') ?></button>
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

<?= View::include('partials.pagination', ['result' => $files]) ?>
<?php
View::endSection();
