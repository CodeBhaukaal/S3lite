<?php

use App\Core\View;
use App\Models\FileRecord;

View::extend('layouts.app');
View::share('title', 'All files');

/** @var array $files */
/** @var array<int,array> $owners */
/** @var array $filters */
/** @var list<array> $users */

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('files', 'icon icon-lg') ?> All files</h1>
        <div class="page-head__sub"><?= e(number_format((int) $files['total'])) ?> matching files across every account.</div>
    </div>
</div>

<form class="filters" method="get">
    <div class="field flex-1" style="max-width:320px">
        <div class="search">
            <?= icon('search') ?>
            <input class="input" type="search" name="q" placeholder="Search all files…" value="<?= e($filters['q']) ?>" data-search-input>
        </div>
    </div>
    <div class="field">
        <select class="select" name="user_id" data-auto-submit>
            <option value="">All users</option>
            <?php foreach ($users as $user): ?>
                <option value="<?= e((string) $user['id']) ?>" <?= (string) ($filters['user_id'] ?? '') === (string) $user['id'] ? 'selected' : '' ?>>
                    <?= e($user['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <select class="select" name="type" data-auto-submit>
            <option value="">All types</option>
            <?php foreach (['image/' => 'Images', 'video/' => 'Video', 'audio/' => 'Audio', 'application/pdf' => 'PDF', 'text/' => 'Text'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $filters['mime'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <label class="check">
        <input type="checkbox" name="trashed" value="1" <?= !empty($filters['trashed']) ? 'checked' : '' ?> data-auto-submit>
        <span class="check__text">Trashed only</span>
    </label>
    <button class="btn" type="submit"><?= icon('filter') ?> Filter</button>
</form>

<div class="card">
    <div class="card__body card__body--flush">
        <?php if ($files['data'] === []): ?>
            <div class="empty">
                <div class="empty__icon"><?= icon('search', 'icon icon-hero') ?></div>
                <div class="empty__title">No files match these filters</div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th>File</th><th>Owner</th><th class="nowrap">Size</th><th class="nowrap">Downloads</th><th>Flags</th><th class="nowrap">Uploaded</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($files['data'] as $file): ?>
                        <?php
                        $kind = FileRecord::kind($file);
                        $owner = $owners[(int) $file['user_id']] ?? null;
                        ?>
                        <tr>
                            <td>
                                <div class="flex items-center gap-2">
                                    <?= icon(match ($kind) {
                                        'image' => 'image', 'video' => 'video', 'audio' => 'music',
                                        'pdf', 'text' => 'file-text', 'archive' => 'archive', default => 'file',
                                    }, 'icon kind-' . $kind) ?>
                                    <span class="truncate" style="max-width:300px"><?= e($file['name']) ?></span>
                                </div>
                                <div class="faint small mono"><?= e($file['mime']) ?></div>
                            </td>
                            <td>
                                <?php if ($owner !== null): ?>
                                    <a href="<?= e(url('/admin/users/' . $owner['id'])) ?>"><?= e($owner['name']) ?></a>
                                    <div class="faint small"><?= e($owner['email']) ?></div>
                                <?php else: ?>
                                    <span class="faint">unknown</span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap tabular"><?= e(bytes((int) $file['size'])) ?></td>
                            <td class="tabular"><?= e((string) $file['download_count']) ?></td>
                            <td>
                                <?php if ((int) $file['is_public'] === 1): ?><span class="badge badge-accent"><?= icon('link') ?> shared</span><?php endif; ?>
                                <?php if ($file['deleted_at'] !== null): ?><span class="badge badge-danger">trashed</span><?php endif; ?>
                                <?php if ($file['scan_result'] === 'infected'): ?><span class="badge badge-danger">infected</span><?php endif; ?>
                                <span class="badge"><?= e($file['source']) ?></span>
                            </td>
                            <td class="nowrap faint"><?= e(time_ago($file['created_at'])) ?></td>
                            <td class="right">
                                <form method="post" action="<?= e(url('/admin/files/' . $file['uuid'] . '/delete')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-danger btn-icon" type="submit"
                                            data-confirm="Permanently delete this file and its versions?" title="Delete permanently"><?= icon('trash') ?></button>
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

<?= View::include('partials.pagination', ['result' => $files]) ?>
<?php
View::endSection();
