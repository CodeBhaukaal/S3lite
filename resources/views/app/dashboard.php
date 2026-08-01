<?php

use App\Core\View;
use App\Models\FileRecord;

View::extend('layouts.app');
View::share('title', 'Dashboard');

/** @var array $stats */
/** @var array $timeline */
/** @var list<array> $byType */
/** @var list<array> $recent */
/** @var list<array> $topFiles */
/** @var list<array> $activity */
/** @var bool $isAdmin */
/** @var array|null $globalStats */

$maxTypeBytes = 0;
foreach ($byType as $row) {
    $maxTypeBytes = max($maxTypeBytes, (int) $row['bytes']);
}

$kindIcons = [
    'image' => 'image', 'video' => 'video', 'audio' => 'music',
    'pdf' => 'file-text', 'text' => 'file-text', 'archive' => 'archive', 'file' => 'file',
];

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('home', 'icon icon-lg') ?> Welcome back, <?= e(explode(' ', (string) $authUser['name'])[0]) ?></h1>
        <div class="page-head__sub">Here is what is happening in your storage.</div>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/api-docs')) ?>"><?= icon('book') ?> API docs</a>
        <a class="btn btn-primary" href="<?= e(url('/files')) ?>"><?= icon('upload') ?> Upload files</a>
    </div>
</div>

<div class="grid grid-4 mb-4">
    <div class="stat">
        <div class="stat__icon"><?= icon('file') ?></div>
        <div class="stat__label">Files</div>
        <div class="stat__value tabular"><?= e(number_format($stats['files'])) ?></div>
        <div class="stat__meta"><?= icon('folder', 'icon icon-sm') ?> <?= e(number_format($stats['folders'])) ?> folders</div>
    </div>

    <div class="stat stat--info">
        <div class="stat__icon"><?= icon('hard-drive') ?></div>
        <div class="stat__label">Storage used</div>
        <div class="stat__value tabular"><?= e(bytes($stats['bytes'])) ?></div>
        <div class="stat__meta">
            <?php if ($stats['quota']['limit'] > 0): ?>
                <?= e((string) $stats['quota']['percent']) ?>% of <?= e(bytes($stats['quota']['limit'])) ?>
            <?php else: ?>
                Unlimited quota
            <?php endif; ?>
        </div>
    </div>

    <div class="stat stat--success">
        <div class="stat__icon"><?= icon('download') ?></div>
        <div class="stat__label">Downloads</div>
        <div class="stat__value tabular"><?= e(number_format($stats['downloads'])) ?></div>
        <div class="stat__meta">All time</div>
    </div>

    <div class="stat stat--warning">
        <div class="stat__icon"><?= icon('link') ?></div>
        <div class="stat__label">Active links</div>
        <div class="stat__value tabular"><?= e(number_format($stats['shares'])) ?></div>
        <div class="stat__meta"><?= e(number_format($stats['trashed'])) ?> items in trash</div>
    </div>
</div>

<div class="grid grid-main">
    <div>
        <div class="card">
            <div class="card__head">
                <?= icon('trending-up') ?>
                <h2>Transfer activity</h2>
                <span class="badge">Last 14 days</span>
            </div>
            <div class="card__body">
                <div id="chart-timeline" data-chart='<?= e(json_encode([
                    'series' => [
                        ['label' => 'uploads', 'color' => 'var(--accent)', 'values' => $timeline['uploads']],
                        ['label' => 'downloads', 'color' => '#22c55e', 'values' => $timeline['downloads']],
                    ],
                    'options' => ['labels' => $timeline['labels'], 'height' => 210],
                ], JSON_UNESCAPED_SLASHES)) ?>'></div>
                <div class="chart-legend">
                    <span class="chart-legend__item"><span class="chart-legend__swatch" style="background:var(--accent)"></span> Uploads</span>
                    <span class="chart-legend__item"><span class="chart-legend__swatch" style="background:#22c55e"></span> Downloads</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card__head">
                <?= icon('clock') ?>
                <h2>Recent files</h2>
                <a class="btn btn-sm btn-ghost" href="<?= e(url('/files')) ?>">View all <?= icon('chevron-right', 'icon icon-sm') ?></a>
            </div>
            <div class="card__body card__body--flush">
                <?php if ($recent === []): ?>
                    <div class="empty">
                        <div class="empty__icon"><?= icon('inbox', 'icon icon-hero') ?></div>
                        <div class="empty__title">No files yet</div>
                        <div class="empty__text">Upload your first file to get started.</div>
                        <a class="btn btn-primary" href="<?= e(url('/files')) ?>"><?= icon('upload') ?> Upload</a>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>Name</th>
                                <th class="nowrap">Size</th>
                                <th class="nowrap">Downloads</th>
                                <th class="nowrap">Added</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recent as $file): ?>
                                <?php $kind = FileRecord::kind($file); ?>
                                <tr>
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <?= icon($kindIcons[$kind] ?? 'file', 'icon kind-' . $kind) ?>
                                            <a class="truncate" href="<?= e(url('/files/' . $file['uuid'])) ?>" style="max-width:320px; display:inline-block">
                                                <?= e($file['name']) ?>
                                            </a>
                                            <?php if ((int) $file['is_public'] === 1): ?>
                                                <span class="badge badge-accent"><?= icon('link') ?> shared</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="nowrap tabular"><?= e(bytes((int) $file['size'])) ?></td>
                                    <td class="nowrap tabular"><?= e((string) $file['download_count']) ?></td>
                                    <td class="nowrap faint"><?= e(time_ago($file['created_at'])) ?></td>
                                    <td class="right">
                                        <a class="btn btn-sm btn-ghost btn-icon" href="<?= e(url('/files/' . $file['uuid'] . '/download')) ?>" title="Download">
                                            <?= icon('download') ?>
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
    </div>

    <div>
        <div class="card">
            <div class="card__head"><?= icon('pie-chart') ?><h3>Storage by type</h3></div>
            <div class="card__body">
                <?php if ($byType === []): ?>
                    <p class="faint small">No data yet.</p>
                <?php else: ?>
                    <?php foreach ($byType as $row): ?>
                        <div class="bar-row">
                            <span class="bar-row__label"><?= e($row['kind']) ?></span>
                            <span class="bar-row__track">
                                <span class="bar-row__fill kind-<?= e($row['kind']) ?>"
                                      style="width: <?= e((string) ($maxTypeBytes > 0 ? round((int) $row['bytes'] / $maxTypeBytes * 100) : 0)) ?>%; background: currentColor"></span>
                            </span>
                            <span class="bar-row__value"><?= e(bytes((int) $row['bytes'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($topFiles !== []): ?>
            <div class="card">
                <div class="card__head"><?= icon('bar-chart') ?><h3>Most downloaded</h3></div>
                <div class="card__body card__body--flush">
                    <table class="table">
                        <tbody>
                        <?php foreach ($topFiles as $file): ?>
                            <tr>
                                <td>
                                    <a class="truncate" style="max-width:200px; display:inline-block" href="<?= e(url('/files/' . $file['uuid'])) ?>">
                                        <?= e($file['name']) ?>
                                    </a>
                                    <div class="faint small"><?= e(bytes((int) $file['size'])) ?></div>
                                </td>
                                <td class="right nowrap">
                                    <span class="badge"><?= icon('download') ?> <?= e((string) $file['download_count']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card__head"><?= icon('activity') ?><h3>Your activity</h3></div>
            <div class="card__body card__body--flush">
                <?php if ($activity === []): ?>
                    <div class="card__body"><p class="faint small">Nothing recorded yet.</p></div>
                <?php else: ?>
                    <table class="table">
                        <tbody>
                        <?php foreach ($activity as $entry): ?>
                            <tr>
                                <td>
                                    <span class="badge"><?= e($entry['action']) ?></span>
                                    <div class="small muted mt-1 truncate" style="max-width:240px"><?= e($entry['description']) ?></div>
                                </td>
                                <td class="right faint small nowrap"><?= e(time_ago($entry['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($isAdmin && $globalStats !== null): ?>
    <div class="card mt-4">
        <div class="card__head">
            <?= icon('shield') ?>
            <h2>Platform overview</h2>
            <a class="btn btn-sm" href="<?= e(url('/admin')) ?>">Admin panel <?= icon('chevron-right', 'icon icon-sm') ?></a>
        </div>
        <div class="card__body">
            <div class="grid grid-4">
                <div class="stat">
                    <div class="stat__icon"><?= icon('users') ?></div>
                    <div class="stat__label">Users</div>
                    <div class="stat__value tabular"><?= e(number_format($globalStats['users'])) ?></div>
                    <div class="stat__meta"><?= e((string) $globalStats['active_users']) ?> active</div>
                </div>
                <div class="stat stat--info">
                    <div class="stat__icon"><?= icon('database') ?></div>
                    <div class="stat__label">Total stored</div>
                    <div class="stat__value tabular"><?= e(bytes($globalStats['bytes'])) ?></div>
                    <div class="stat__meta"><?= e(number_format($globalStats['files'])) ?> files</div>
                </div>
                <div class="stat stat--success">
                    <div class="stat__icon"><?= icon('trending-up') ?></div>
                    <div class="stat__label">Transfers (24h)</div>
                    <div class="stat__value tabular"><?= e(number_format($globalStats['downloads_24h'] + $globalStats['uploads_24h'])) ?></div>
                    <div class="stat__meta"><?= e(bytes($globalStats['download_bytes_24h'])) ?> out</div>
                </div>
                <div class="stat <?= $globalStats['failed_logins_24h'] > 0 ? 'stat--danger' : '' ?>">
                    <div class="stat__icon"><?= icon('shield-check') ?></div>
                    <div class="stat__label">Failed logins (24h)</div>
                    <div class="stat__value tabular"><?= e(number_format($globalStats['failed_logins_24h'])) ?></div>
                    <div class="stat__meta"><?= e((string) $globalStats['active_sessions']) ?> live transfer sessions</div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php
View::endSection();
