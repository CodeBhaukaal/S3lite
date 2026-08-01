<?php

use App\Core\View;
use App\Models\FileRecord;

View::extend('layouts.public');
View::share('title', $file !== null ? (string) $file['name'] : (string) ($folder['name'] ?? 'Shared'));

/** @var array $share */
/** @var array|null $file */
/** @var array|null $folder */
/** @var array $files */
/** @var string|null $textPreview */

$kind = $file === null ? 'folder' : FileRecord::kind($file);
$allowPreview = (int) $share['allow_preview'] === 1;
$previewUrl = url('/s/' . $share['token'] . '/preview');
$downloadUrl = url('/s/' . $share['token'] . '/download');

View::startSection('content');
?>
<?php if ($file !== null): ?>
    <div class="card">
        <div class="card__body">
            <div class="flex items-center gap-4 flex-wrap">
                <span class="kind-<?= e($kind) ?>"><?= icon(match ($kind) {
                    'image' => 'image', 'video' => 'video', 'audio' => 'music',
                    'pdf', 'text' => 'file-text', 'archive' => 'archive', default => 'file',
                }, 'icon icon-hero') ?></span>

                <div class="flex-1" style="min-width:220px">
                    <h1 style="word-break:break-word"><?= e($file['name']) ?></h1>
                    <div class="muted mt-1">
                        <?= e(bytes((int) $file['size'])) ?> · <?= e($file['mime']) ?>
                    </div>
                    <div class="flex gap-2 mt-2 flex-wrap">
                        <span class="badge"><?= icon('clock') ?> shared <?= e(time_ago($share['created_at'])) ?></span>
                        <?php if ($share['expires_at'] !== null): ?>
                            <span class="badge badge-warning"><?= icon('clock') ?> expires <?= e(date('d M Y H:i', strtotime((string) $share['expires_at']))) ?></span>
                        <?php else: ?>
                            <span class="badge badge-success"><?= icon('check') ?> permanent link</span>
                        <?php endif; ?>
                        <?php if ($share['max_downloads'] !== null): ?>
                            <span class="badge"><?= icon('download') ?> <?= e((string) $share['download_count']) ?> / <?= e((string) $share['max_downloads']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <a class="btn btn-primary btn-lg" href="<?= e($downloadUrl) ?>">
                    <?= icon('download') ?> Download
                </a>
            </div>
        </div>

        <?php if ($allowPreview): ?>
            <div class="card__body" style="border-top:1px solid var(--border)">
                <?php if ($kind === 'image'): ?>
                    <img class="preview-image" src="<?= e($previewUrl) ?>" alt="<?= e($file['name']) ?>">
                <?php elseif ($kind === 'pdf'): ?>
                    <iframe class="preview-frame" src="<?= e($previewUrl) ?>" title="PDF preview"></iframe>
                <?php elseif ($kind === 'video'): ?>
                    <video class="preview-media" controls preload="metadata" src="<?= e($previewUrl) ?>"></video>
                <?php elseif ($kind === 'audio'): ?>
                    <audio class="preview-media" controls preload="metadata" src="<?= e($previewUrl) ?>"></audio>
                <?php elseif ($textPreview !== null): ?>
                    <pre class="code-block" style="max-height:420px"><?= e($textPreview) ?></pre>
                <?php else: ?>
                    <div class="empty" style="padding:26px">
                        <div class="empty__text">No in-browser preview for this file type.</div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card__foot flex items-center gap-3 flex-wrap">
            <span class="faint small">SHA-256</span>
            <span class="mono small truncate flex-1"><?= e($file['checksum']) ?></span>
            <button class="btn btn-sm" type="button" data-copy="<?= e(url('/s/' . $share['token'])) ?>" data-copy-label="Link">
                <?= icon('link') ?> Copy link
            </button>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card__body flex items-center gap-4 flex-wrap">
            <span class="text-accent"><?= icon('folder', 'icon icon-hero') ?></span>
            <div class="flex-1">
                <h1><?= e((string) $folder['name']) ?></h1>
                <div class="muted mt-1"><?= e((string) $files['total']) ?> file<?= (int) $files['total'] === 1 ? '' : 's' ?> in this shared folder</div>
            </div>
        </div>

        <div class="card__body card__body--flush" style="border-top:1px solid var(--border)">
            <?php if ($files['data'] === []): ?>
                <div class="empty">
                    <div class="empty__icon"><?= icon('inbox', 'icon icon-hero') ?></div>
                    <div class="empty__title">This folder is empty</div>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Name</th><th class="nowrap">Size</th><th class="nowrap">Modified</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($files['data'] as $item): ?>
                            <?php $itemKind = FileRecord::kind($item); ?>
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <?= icon(match ($itemKind) {
                                            'image' => 'image', 'video' => 'video', 'audio' => 'music',
                                            'pdf', 'text' => 'file-text', 'archive' => 'archive', default => 'file',
                                        }, 'icon kind-' . $itemKind) ?>
                                        <span class="truncate" style="max-width:340px"><?= e($item['name']) ?></span>
                                    </div>
                                </td>
                                <td class="nowrap tabular"><?= e(bytes((int) $item['size'])) ?></td>
                                <td class="nowrap faint"><?= e(time_ago($item['updated_at'])) ?></td>
                                <td class="right">
                                    <a class="btn btn-sm" href="<?= e($downloadUrl . '?file=' . $item['uuid']) ?>">
                                        <?= icon('download') ?> Download
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php
View::endSection();
