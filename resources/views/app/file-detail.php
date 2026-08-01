<?php

use App\Core\View;
use App\Models\FileRecord;
use App\Models\Share;

View::extend('layouts.app');
View::share('title', (string) $file['name']);

/** @var array $file */
/** @var list<array> $versions */
/** @var list<array> $shares */
/** @var array|null $folder */
/** @var list<array> $breadcrumbs */
/** @var string|null $textPreview */
/** @var list<array> $folderTree */
/** @var string $signedUrl */

$kind = FileRecord::kind($file);
$previewUrl = url('/files/' . $file['uuid'] . '/preview');

View::startSection('content');
?>
<nav class="breadcrumbs">
    <a href="<?= e(url('/files')) ?>"><?= icon('home', 'icon icon-sm') ?> Home</a>
    <?php foreach ($breadcrumbs as $crumb): ?>
        <span class="sep"><?= icon('chevron-right', 'icon icon-sm') ?></span>
        <a href="<?= e(url('/files?folder=' . $crumb['id'])) ?>"><?= e($crumb['name']) ?></a>
    <?php endforeach; ?>
    <span class="sep"><?= icon('chevron-right', 'icon icon-sm') ?></span>
    <span class="current"><?= e($file['name']) ?></span>
</nav>

<div class="page-head">
    <div class="page-head__text">
        <h1 class="truncate">
            <span class="kind-<?= e($kind) ?>"><?= icon(match ($kind) {
                'image' => 'image', 'video' => 'video', 'audio' => 'music',
                'pdf', 'text' => 'file-text', 'archive' => 'archive', default => 'file',
            }, 'icon icon-lg') ?></span>
            <?= e($file['name']) ?>
        </h1>
        <div class="page-head__sub">
            <?= e(bytes((int) $file['size'])) ?> · <?= e($file['mime']) ?> · version <?= e((string) $file['version']) ?>
            <?php if ($file['deleted_at'] !== null): ?>
                <span class="badge badge-danger"><?= icon('trash') ?> in trash</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/files/' . $file['uuid'] . '/download')) ?>"><?= icon('download') ?> Download</a>
        <button class="btn btn-primary" type="button" data-modal-open="modal-share"
                data-fill-file_id="<?= e($file['uuid']) ?>" data-fill-folder_id=""><?= icon('share') ?> Share</button>
        <?= View::include('partials.file-menu', ['file' => $file, 'folderTree' => $folderTree]) ?>
    </div>
</div>

<div class="grid grid-main">
    <div>
        <div class="card">
            <div class="card__head"><?= icon('eye') ?><h2>Preview</h2></div>
            <div class="card__body">
                <?php if ($kind === 'image'): ?>
                    <img class="preview-image" src="<?= e($previewUrl) ?>" alt="<?= e($file['name']) ?>">
                <?php elseif ($kind === 'pdf'): ?>
                    <iframe class="preview-frame" src="<?= e($previewUrl) ?>" title="PDF preview"></iframe>
                <?php elseif ($kind === 'video'): ?>
                    <video class="preview-media" controls preload="metadata" src="<?= e($previewUrl) ?>"></video>
                <?php elseif ($kind === 'audio'): ?>
                    <audio class="preview-media" controls preload="metadata" src="<?= e($previewUrl) ?>"></audio>
                <?php elseif ($textPreview !== null): ?>
                    <pre class="code-block" style="max-height:460px"><?= e($textPreview) ?></pre>
                <?php else: ?>
                    <div class="empty">
                        <div class="empty__icon"><?= icon('file', 'icon icon-hero') ?></div>
                        <div class="empty__title">No preview available</div>
                        <div class="empty__text">This file type cannot be shown in the browser.</div>
                        <a class="btn btn-primary" href="<?= e(url('/files/' . $file['uuid'] . '/download')) ?>"><?= icon('download') ?> Download</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card__head">
                <?= icon('link') ?><h2>Share links</h2>
                <button class="btn btn-sm btn-primary" type="button" data-modal-open="modal-share"
                        data-fill-file_id="<?= e($file['uuid']) ?>" data-fill-folder_id=""><?= icon('plus') ?> New link</button>
            </div>
            <div class="card__body card__body--flush">
                <?php if ($shares === []): ?>
                    <div class="card__body"><p class="faint small">This file is not shared yet.</p></div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                            <tr><th>Link</th><th>Type</th><th class="nowrap">Downloads</th><th>Status</th><th></th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($shares as $share): ?>
                                <?php $public = Share::publicArray($share); ?>
                                <tr>
                                    <td>
                                        <div class="input-copy" style="max-width:340px">
                                            <input class="input" readonly value="<?= e($public['url']) ?>" id="share-<?= e((string) $share['id']) ?>">
                                            <button class="btn btn-icon" type="button" data-copy="#share-<?= e((string) $share['id']) ?>" data-copy-label="Link"><?= icon('clipboard') ?></button>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?= $public['type'] === 'permanent' ? 'badge-success' : 'badge-warning' ?>"><?= e($public['type']) ?></span>
                                        <?php if ($public['password_protected']): ?><span class="badge"><?= icon('lock') ?></span><?php endif; ?>
                                    </td>
                                    <td class="tabular"><?= e((string) $public['download_count']) ?><?= $public['max_downloads'] !== null ? ' / ' . e((string) $public['max_downloads']) : '' ?></td>
                                    <td>
                                        <?php if ($public['usable']): ?>
                                            <span class="badge badge-success"><span class="dot dot-success"></span> active</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="right">
                                        <form method="post" action="<?= e(url('/shares/' . $share['id'] . '/revoke')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-ghost btn-icon" type="submit" data-confirm="Revoke this link?" title="Revoke"><?= icon('link-off') ?></button>
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
            <div class="card__head"><?= icon('history') ?><h2>Version history</h2><span class="badge"><?= e((string) (count($versions) + 1)) ?> versions</span></div>
            <div class="card__body card__body--flush">
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Version</th><th class="nowrap">Size</th><th>Checksum</th><th class="nowrap">Created</th><th></th></tr></thead>
                        <tbody>
                        <tr>
                            <td><span class="badge badge-accent">v<?= e((string) $file['version']) ?> · current</span></td>
                            <td class="nowrap tabular"><?= e(bytes((int) $file['size'])) ?></td>
                            <td class="mono faint small"><?= e(substr((string) $file['checksum'], 0, 16)) ?>…</td>
                            <td class="nowrap faint"><?= e(time_ago($file['updated_at'])) ?></td>
                            <td></td>
                        </tr>
                        <?php foreach ($versions as $version): ?>
                            <tr>
                                <td><span class="badge">v<?= e((string) $version['version']) ?></span></td>
                                <td class="nowrap tabular"><?= e(bytes((int) $version['size'])) ?></td>
                                <td class="mono faint small"><?= e(substr((string) $version['checksum'], 0, 16)) ?>…</td>
                                <td class="nowrap faint"><?= e(time_ago($version['created_at'])) ?></td>
                                <td class="right">
                                    <div class="actions">
                                        <a class="btn btn-sm btn-ghost btn-icon"
                                           href="<?= e(url('/api/v1/files/' . $file['uuid'] . '/versions/' . $version['version'] . '/download')) ?>"
                                           title="Download this version"><?= icon('download') ?></a>
                                        <form method="post" action="<?= e(url('/files/' . $file['uuid'] . '/versions/' . $version['version'] . '/restore')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-ghost btn-icon" type="submit"
                                                    data-confirm="Restore version <?= e((string) $version['version']) ?>?" title="Restore"><?= icon('restore') ?></button>
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
    </div>

    <div>
        <div class="card">
            <div class="card__head"><?= icon('info') ?><h3>Details</h3></div>
            <div class="card__body">
                <dl class="kv">
                    <dt>UUID</dt><dd class="mono small"><?= e($file['uuid']) ?></dd>
                    <dt>Original name</dt><dd><?= e($file['original_name']) ?></dd>
                    <dt>MIME type</dt><dd class="mono small"><?= e($file['mime']) ?></dd>
                    <dt>Size</dt><dd><?= e(bytes((int) $file['size'])) ?> (<?= e(number_format((int) $file['size'])) ?> bytes)</dd>
                    <dt>SHA-256</dt><dd class="mono small" style="word-break:break-all"><?= e($file['checksum']) ?></dd>
                    <dt>Folder</dt><dd><?= $folder === null ? 'Home' : e($folder['name']) ?></dd>
                    <dt>Source</dt><dd><span class="badge"><?= e($file['source']) ?></span></dd>
                    <dt>Downloads</dt><dd class="tabular"><?= e((string) $file['download_count']) ?></dd>
                    <dt>Scan result</dt><dd>
                        <?php if ($file['scan_result'] === 'clean'): ?><span class="badge badge-success"><?= icon('shield-check') ?> clean</span>
                        <?php elseif ($file['scan_result'] === 'infected'): ?><span class="badge badge-danger">infected</span>
                        <?php else: ?><span class="badge"><?= e((string) ($file['scan_result'] ?: 'not scanned')) ?></span><?php endif; ?>
                    </dd>
                    <dt>Uploaded</dt><dd><?= e($file['created_at']) ?></dd>
                    <dt>Modified</dt><dd><?= e($file['updated_at']) ?></dd>
                </dl>

                <?php if (($file['meta'] ?? []) !== []): ?>
                    <hr>
                    <div class="label">Metadata</div>
                    <dl class="kv">
                        <?php foreach ($file['meta'] as $key => $value): ?>
                            <dt><?= e(str_replace('_', ' ', (string) $key)) ?></dt>
                            <dd><?= e(is_scalar($value) ? (string) $value : json_encode($value)) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>

                <hr>
                <div class="label">Tags</div>
                <div class="flex gap-1 flex-wrap mb-3">
                    <?php if (($file['tags'] ?? []) === []): ?>
                        <span class="faint small">No tags</span>
                    <?php else: ?>
                        <?php foreach ($file['tags'] as $tag): ?>
                            <a class="tag" href="<?= e(url('/files?tag=' . urlencode((string) $tag))) ?>"><?= icon('tag', 'icon icon-sm') ?><?= e((string) $tag) ?></a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button class="btn btn-sm btn-block" type="button"
                        data-modal-open="modal-tags"
                        data-modal-action="<?= e(url('/files/' . $file['uuid'] . '/tags')) ?>"
                        data-fill-tags="<?= e(implode(', ', (array) ($file['tags'] ?? []))) ?>"><?= icon('tag') ?> Edit tags</button>
            </div>
        </div>

        <div class="card">
            <div class="card__head"><?= icon('key') ?><h3>Temporary link</h3></div>
            <div class="card__body">
                <p class="small muted">A signed URL that works without signing in and expires after one hour.</p>
                <div class="input-copy">
                    <input class="input" id="signed-url" readonly value="<?= e($signedUrl) ?>">
                    <button class="btn btn-icon" type="button" data-copy="#signed-url" data-copy-label="Signed URL"><?= icon('clipboard') ?></button>
                </div>
            </div>
        </div>
    </div>
</div>

<?= View::include('partials.share-modal', ['folderTree' => $folderTree]) ?>
<?= View::include('partials.file-modals', ['folderTree' => $folderTree]) ?>
<?php
View::endSection();
