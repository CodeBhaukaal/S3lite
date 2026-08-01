<?php

use App\Core\View;
use App\Models\FileRecord;

View::extend('layouts.app');
View::share('title', 'Files');

/** @var array|null $folder */
/** @var list<array> $folders */
/** @var array $files */
/** @var list<array> $breadcrumbs */
/** @var array $filters */
/** @var string $sort */
/** @var string $direction */
/** @var string $viewMode */
/** @var array $tags */
/** @var list<array> $folderTree */
/** @var int $maxUpload */
/** @var int $chunkSize */

$currentFolderId = $folder === null ? '' : (string) $folder['id'];

$kindIcons = [
    'image' => 'image', 'video' => 'video', 'audio' => 'music',
    'pdf' => 'file-text', 'text' => 'file-text', 'archive' => 'archive', 'file' => 'file',
];

$searching = ($filters['q'] ?? '') !== '' || ($filters['tag'] ?? '') !== '' || ($filters['mime'] ?? '') !== '';

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('folder', 'icon icon-lg') ?> <?= e($folder === null ? 'My files' : $folder['name']) ?></h1>
        <div class="page-head__sub">
            <?= e(number_format((int) $files['total'])) ?> file<?= (int) $files['total'] === 1 ? '' : 's' ?>
            <?php if ($folders !== []): ?> · <?= e((string) count($folders)) ?> folder<?= count($folders) === 1 ? '' : 's' ?><?php endif; ?>
        </div>
    </div>
    <div class="page-head__actions">
        <div class="btn-group">
            <a class="btn btn-sm <?= $viewMode === 'grid' ? 'is-active' : '' ?>" href="?<?= e(http_build_query(array_merge($_GET, ['view' => 'grid']))) ?>" title="Grid view"><?= icon('grid') ?></a>
            <a class="btn btn-sm <?= $viewMode === 'list' ? 'is-active' : '' ?>" href="?<?= e(http_build_query(array_merge($_GET, ['view' => 'list']))) ?>" title="List view"><?= icon('list') ?></a>
        </div>
        <button class="btn" type="button" data-modal-open="modal-folder"><?= icon('folder-plus') ?> New folder</button>
        <button class="btn btn-primary" type="button" onclick="document.getElementById('file-input').click()"><?= icon('upload') ?> Upload</button>
    </div>
</div>

<nav class="breadcrumbs">
    <a href="<?= e(url('/files')) ?>"><?= icon('home', 'icon icon-sm') ?> Home</a>
    <?php foreach ($breadcrumbs as $crumb): ?>
        <span class="sep"><?= icon('chevron-right', 'icon icon-sm') ?></span>
        <?php if ((int) $crumb['id'] === (int) ($folder['id'] ?? 0)): ?>
            <span class="current"><?= e($crumb['name']) ?></span>
        <?php else: ?>
            <a href="<?= e(url('/files?folder=' . $crumb['id'])) ?>"><?= e($crumb['name']) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<!-- Upload -->
<div class="dropzone mb-4" id="dropzone">
    <div class="dropzone__icon"><?= icon('upload-cloud', 'icon icon-hero') ?></div>
    <div class="dropzone__title">Drop files here, or click to browse</div>
    <div class="dropzone__hint">
        Up to <?= e(bytes($maxUpload)) ?> per file · files above <?= e(bytes($chunkSize)) ?> upload in resumable chunks
    </div>
</div>
<input type="file" id="file-input" multiple class="hidden">
<div class="upload-list" id="upload-list"></div>

<!-- Filters -->
<form class="filters" method="get" action="<?= e(url('/files')) ?>">
    <input type="hidden" name="folder" value="<?= e($currentFolderId) ?>">
    <input type="hidden" name="view" value="<?= e($viewMode) ?>">

    <div class="field flex-1" style="max-width:340px">
        <div class="search">
            <?= icon('search') ?>
            <input class="input" type="search" name="q" placeholder="Search files…  (press /)"
                   value="<?= e($filters['q']) ?>" data-search-input>
        </div>
    </div>

    <div class="field">
        <select class="select" name="type" data-auto-submit>
            <option value="">All types</option>
            <?php foreach (['image/' => 'Images', 'video/' => 'Video', 'audio/' => 'Audio', 'application/pdf' => 'PDF', 'text/' => 'Text'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $filters['mime'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($tags !== []): ?>
        <div class="field">
            <select class="select" name="tag" data-auto-submit>
                <option value="">All tags</option>
                <?php foreach ($tags as $tag => $count): ?>
                    <option value="<?= e((string) $tag) ?>" <?= $filters['tag'] === (string) $tag ? 'selected' : '' ?>>
                        <?= e((string) $tag) ?> (<?= e((string) $count) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <div class="field">
        <select class="select" name="sort" data-auto-submit>
            <?php foreach (['created_at' => 'Newest', 'name' => 'Name', 'size' => 'Size', 'download_count' => 'Downloads'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <select class="select" name="dir" data-auto-submit>
            <option value="desc" <?= $direction === 'desc' ? 'selected' : '' ?>>Descending</option>
            <option value="asc" <?= $direction === 'asc' ? 'selected' : '' ?>>Ascending</option>
        </select>
    </div>

    <button class="btn" type="submit"><?= icon('filter') ?> Apply</button>
    <?php if ($searching): ?>
        <a class="btn btn-ghost" href="<?= e(url('/files' . ($currentFolderId !== '' ? '?folder=' . $currentFolderId : ''))) ?>"><?= icon('x') ?> Clear</a>
    <?php endif; ?>
</form>

<!-- Bulk actions -->
<form method="post" action="<?= e(url('/files/bulk')) ?>" data-bulk-form>
    <?= csrf_field() ?>
    <div class="card mb-4 hidden" data-bulk-bar>
        <div class="card__body flex items-center gap-3 flex-wrap">
            <span class="badge badge-accent"><span data-bulk-count>0</span> selected</span>
            <span class="topbar__spacer"></span>
            <button class="btn btn-sm" name="action" value="copy" type="submit"><?= icon('copy') ?> Copy</button>
            <select class="select" name="folder_id" style="max-width:200px">
                <option value="root">Move to: Home</option>
                <?php foreach ($folderTree as $node): ?>
                    <option value="<?= e((string) $node['id']) ?>"><?= e(str_repeat('— ', $node['depth']) . $node['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm" name="action" value="move" type="submit"><?= icon('move') ?> Move</button>
            <button class="btn btn-sm btn-danger" name="action" value="trash" type="submit"
                    data-confirm="Move the selected files to trash?"><?= icon('trash') ?> Trash</button>
        </div>
    </div>
</form>

<!-- Folders -->
<?php if ($folders !== []): ?>
    <div class="grid grid-4 mb-4">
        <?php foreach ($folders as $child): ?>
            <div class="folder-card">
                <a class="folder-card__icon" href="<?= e(url('/files?folder=' . $child['id'])) ?>"><?= icon('folder', 'icon icon-lg') ?></a>
                <div class="folder-card__body">
                    <a class="folder-card__name" href="<?= e(url('/files?folder=' . $child['id'])) ?>"><?= e($child['name']) ?></a>
                    <div class="folder-card__meta">
                        <?= e((string) $child['file_count']) ?> files · <?= e((string) $child['folder_count']) ?> folders
                    </div>
                </div>
                <div class="dropdown">
                    <button class="btn btn-ghost btn-icon btn-sm" type="button" data-dropdown aria-label="Folder actions"><?= icon('more') ?></button>
                    <div class="dropdown__menu">
                        <button class="dropdown__item" type="button"
                                data-modal-open="modal-folder-rename"
                                data-modal-action="<?= e(url('/folders/' . $child['id'] . '/rename')) ?>"
                                data-fill-name="<?= e($child['name']) ?>"><?= icon('edit') ?> Rename</button>
                        <button class="dropdown__item" type="button"
                                data-modal-open="modal-folder-move"
                                data-modal-action="<?= e(url('/folders/' . $child['id'] . '/move')) ?>"><?= icon('move') ?> Move</button>
                        <button class="dropdown__item" type="button"
                                data-modal-open="modal-share"
                                data-fill-folder_id="<?= e((string) $child['id']) ?>"
                                data-fill-file_id=""><?= icon('share') ?> Share folder</button>
                        <div class="dropdown__divider"></div>
                        <form method="post" action="<?= e(url('/folders/' . $child['id'] . '/trash')) ?>">
                            <?= csrf_field() ?>
                            <button class="dropdown__item is-danger" type="submit"
                                    data-confirm="Move this folder and everything inside it to trash?"><?= icon('trash') ?> Move to trash</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Files -->
<?php if ($files['data'] === []): ?>
    <div class="card">
        <div class="empty">
            <div class="empty__icon"><?= icon($searching ? 'search' : 'inbox', 'icon icon-hero') ?></div>
            <div class="empty__title"><?= $searching ? 'No matching files' : 'This folder is empty' ?></div>
            <div class="empty__text">
                <?= $searching ? 'Try a different search term or clear the filters.' : 'Drop files above to upload them here.' ?>
            </div>
        </div>
    </div>
<?php elseif ($viewMode === 'list'): ?>
    <div class="card">
        <div class="card__body card__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th style="width:36px"><input type="checkbox" data-select-all aria-label="Select all"></th>
                        <th>Name</th>
                        <th class="nowrap">Size</th>
                        <th class="nowrap">Type</th>
                        <th class="nowrap">Downloads</th>
                        <th class="nowrap">Modified</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($files['data'] as $file): ?>
                        <?php $kind = FileRecord::kind($file); ?>
                        <tr>
                            <td><input type="checkbox" data-select-item value="<?= e($file['uuid']) ?>" aria-label="Select file"></td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <?= icon($kindIcons[$kind] ?? 'file', 'icon kind-' . $kind) ?>
                                    <a class="truncate" style="max-width:340px; display:inline-block" href="<?= e(url('/files/' . $file['uuid'])) ?>"><?= e($file['name']) ?></a>
                                    <?php if ((int) $file['is_public'] === 1): ?><span class="badge badge-accent"><?= icon('link') ?></span><?php endif; ?>
                                </div>
                                <?php if (($file['tags'] ?? []) !== []): ?>
                                    <div class="flex gap-1 mt-1 flex-wrap">
                                        <?php foreach ($file['tags'] as $tag): ?>
                                            <a class="tag" href="?tag=<?= e(urlencode((string) $tag)) ?>"><?= icon('tag', 'icon icon-sm') ?><?= e((string) $tag) ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap tabular"><?= e(bytes((int) $file['size'])) ?></td>
                            <td class="nowrap"><span class="badge"><?= e($kind) ?></span></td>
                            <td class="nowrap tabular"><?= e((string) $file['download_count']) ?></td>
                            <td class="nowrap faint"><?= e(time_ago($file['updated_at'])) ?></td>
                            <td>
                                <div class="actions">
                                    <a class="btn btn-sm btn-ghost btn-icon" href="<?= e(url('/files/' . $file['uuid'] . '/download')) ?>" title="Download"><?= icon('download') ?></a>
                                    <?= View::include('partials.file-menu', ['file' => $file, 'folderTree' => $folderTree]) ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="flex items-center gap-2 mb-3">
        <label class="check" style="padding:0">
            <input type="checkbox" data-select-all>
            <span class="check__text small">Select all on this page</span>
        </label>
    </div>

    <div class="file-grid">
        <?php foreach ($files['data'] as $file): ?>
            <?php $kind = FileRecord::kind($file); ?>
            <article class="file-card">
                <label class="file-card__check">
                    <input type="checkbox" data-select-item value="<?= e($file['uuid']) ?>" aria-label="Select <?= e($file['name']) ?>">
                </label>

                <div class="file-card__actions">
                    <?= View::include('partials.file-menu', ['file' => $file, 'folderTree' => $folderTree]) ?>
                </div>

                <a class="file-card__thumb" href="<?= e(url('/files/' . $file['uuid'])) ?>">
                    <?php if ($kind === 'image'): ?>
                        <img src="<?= e(url('/files/' . $file['uuid'] . '/preview')) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <span class="kind-<?= e($kind) ?>"><?= icon($kindIcons[$kind] ?? 'file', 'icon icon-hero') ?></span>
                    <?php endif; ?>
                </a>

                <div class="file-card__body">
                    <a class="file-card__name" href="<?= e(url('/files/' . $file['uuid'])) ?>"><?= e($file['name']) ?></a>
                    <div class="file-card__meta">
                        <span class="tabular"><?= e(bytes((int) $file['size'])) ?></span>
                        <span>·</span>
                        <span><?= e(time_ago($file['created_at'])) ?></span>
                        <?php if ((int) $file['is_public'] === 1): ?>
                            <span class="badge badge-accent"><?= icon('link') ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?= View::include('partials.pagination', ['result' => $files]) ?>

<!-- Modals -->
<div class="modal-backdrop hidden" id="modal-folder">
    <div class="modal">
        <form method="post" action="<?= e(url('/folders')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="parent_id" value="<?= e($currentFolderId) ?>">
            <div class="modal__head"><?= icon('folder-plus') ?><h3>New folder</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="field">
                    <label class="label" for="folder-name">Folder name</label>
                    <input class="input" id="folder-name" name="name" required maxlength="120" placeholder="Documents">
                </div>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Create</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop hidden" id="modal-folder-rename">
    <div class="modal">
        <form method="post" action="">
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('edit') ?><h3>Rename folder</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="field">
                    <label class="label">New name</label>
                    <input class="input" name="name" required maxlength="120">
                </div>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Rename</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop hidden" id="modal-folder-move">
    <div class="modal">
        <form method="post" action="">
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('move') ?><h3>Move folder</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="field">
                    <label class="label">Destination</label>
                    <select class="select" name="parent_id">
                        <option value="root">Home</option>
                        <?php foreach ($folderTree as $node): ?>
                            <option value="<?= e((string) $node['id']) ?>"><?= e(str_repeat('— ', $node['depth']) . $node['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Move</button>
            </div>
        </form>
    </div>
</div>

<?= View::include('partials.share-modal', ['folderTree' => $folderTree]) ?>
<?= View::include('partials.file-modals', ['folderTree' => $folderTree]) ?>

<?php
View::endSection();

View::startSection('scripts');
?>
<script src="<?= e(asset('js/uploader.js')) ?>"></script>
<script>
new S3.Uploader({
    dropzone: document.getElementById('dropzone'),
    input: document.getElementById('file-input'),
    list: document.getElementById('upload-list'),
    folderId: <?= json_encode($currentFolderId) ?>,
    chunkSize: <?= (int) $chunkSize ?>,
    maxSize: <?= (int) $maxUpload ?>,
    simpleUrl: <?= json_encode(url('/files/upload')) ?>,
    apiBase: <?= json_encode(url('/api/v1')) ?>,
    onComplete: function () {
        S3.toast('Upload finished — refreshing…', 'success', 1600);
        setTimeout(() => window.location.reload(), 1000);
    },
});
</script>
<?php
View::endSection();
