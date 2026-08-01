<?php

use App\Core\View;
use App\Services\MetricsService;

View::extend('layouts.app');
View::share('title', 'Admin overview');

/** @var array $stats */
/** @var array $timeline */
/** @var list<array> $byType */
/** @var list<array> $topUsers */
/** @var list<array> $topFiles */
/** @var array $health */
/** @var array $system */
/** @var list<array> $activity */
/** @var array $jobs */

$statusBadge = [
    'ok'       => ['badge-success', 'check-circle'],
    'degraded' => ['badge-warning', 'alert'],
    'fail'     => ['badge-danger', 'x-circle'],
    'skipped'  => ['badge', 'minus'],
];

$maxTypeBytes = 0;
foreach ($byType as $row) {
    $maxTypeBytes = max($maxTypeBytes, (int) $row['bytes']);
}

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('shield', 'icon icon-lg') ?> Platform overview</h1>
        <div class="page-head__sub">
            <?= e($system['hostname']) ?> · <?= e($system['platform']) ?> · PHP <?= e($system['php']['version']) ?>
            · up <?= e($system['uptime']['human']) ?>
        </div>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/admin/monitoring')) ?>"><?= icon('cpu') ?> Monitoring</a>
        <a class="btn" href="<?= e(url('/admin/jobs')) ?>"><?= icon('zap') ?> Jobs</a>
        <a class="btn btn-primary" href="<?= e(url('/admin/users')) ?>"><?= icon('users') ?> Users</a>
    </div>
</div>

<!-- Health -->
<div class="card mb-4">
    <div class="card__head">
        <?= icon('activity') ?>
        <h2>System health</h2>
        <?php [$cls, $ic] = $statusBadge[$health['status']] ?? $statusBadge['ok']; ?>
        <span class="badge <?= e($cls) ?>"><?= icon($ic) ?> <?= e($health['status']) ?></span>
    </div>
    <div class="card__body">
        <div class="grid grid-3">
            <?php foreach ($health['checks'] as $name => $check): ?>
                <?php [$cls, $ic] = $statusBadge[$check['status']] ?? $statusBadge['skipped']; ?>
                <div class="flex items-start gap-3">
                    <span class="stat__icon" style="margin:0; width:30px; height:30px">
                        <?= icon($ic, 'icon icon-sm') ?>
                    </span>
                    <div class="flex-1">
                        <div class="strong small"><?= e(ucwords(str_replace('_', ' ', (string) $name))) ?></div>
                        <div class="faint small"><?= e($check['message']) ?></div>
                        <?php if (isset($check['latency_ms'])): ?>
                            <div class="faint small"><?= e((string) $check['latency_ms']) ?> ms</div>
                        <?php endif; ?>
                    </div>
                    <span class="badge <?= e($cls) ?>"><?= e($check['status']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Resources -->
<div class="grid grid-3 mb-4">
    <div class="card">
        <div class="card__body gauge">
            <div class="gauge__ring" id="ring-cpu" data-ring='<?= e(json_encode(['percent' => $system['cpu']['percent'], 'color' => 'var(--accent)'])) ?>'></div>
            <div class="gauge__body">
                <div class="gauge__label">CPU load</div>
                <div class="strong" style="font-size:16px"><?= e((string) $system['cpu']['cores']) ?> cores</div>
                <?php if ($system['cpu']['load'] !== []): ?>
                    <div class="gauge__meta">avg <?= e(implode(' / ', array_map('strval', $system['cpu']['load']))) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__body gauge">
            <div class="gauge__ring" id="ring-mem" data-ring='<?= e(json_encode(['percent' => $system['memory']['percent'], 'color' => '#7c5cff'])) ?>'></div>
            <div class="gauge__body">
                <div class="gauge__label">Memory</div>
                <div class="strong" style="font-size:16px"><?= e(bytes($system['memory']['used'])) ?></div>
                <div class="gauge__meta">of <?= e(bytes($system['memory']['total'])) ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__body gauge">
            <div class="gauge__ring" id="ring-disk" data-ring='<?= e(json_encode(['percent' => $system['disk']['percent'], 'color' => $system['disk']['percent'] >= 85 ? '#ef4444' : '#22c55e'])) ?>'></div>
            <div class="gauge__body">
                <div class="gauge__label">Disk</div>
                <div class="strong" style="font-size:16px"><?= e(bytes($system['disk']['free'])) ?> free</div>
                <div class="gauge__meta"><?= e(bytes($system['disk']['stored'])) ?> stored by the platform</div>
            </div>
        </div>
    </div>
</div>

<!-- Counters -->
<div class="grid grid-4 mb-4">
    <div class="stat">
        <div class="stat__icon"><?= icon('users') ?></div>
        <div class="stat__label">Users</div>
        <div class="stat__value tabular"><?= e(number_format($stats['users'])) ?></div>
        <div class="stat__meta"><?= e((string) $stats['active_users']) ?> active</div>
    </div>
    <div class="stat stat--info">
        <div class="stat__icon"><?= icon('database') ?></div>
        <div class="stat__label">Stored</div>
        <div class="stat__value tabular"><?= e(bytes($stats['bytes'])) ?></div>
        <div class="stat__meta"><?= e(number_format($stats['files'])) ?> files · <?= e(number_format($stats['trashed'])) ?> trashed</div>
    </div>
    <div class="stat stat--success">
        <div class="stat__icon"><?= icon('trending-up') ?></div>
        <div class="stat__label">Traffic (24h)</div>
        <div class="stat__value tabular"><?= e(bytes($stats['download_bytes_24h'])) ?></div>
        <div class="stat__meta"><?= e(number_format($stats['downloads_24h'])) ?> downloads · <?= e(number_format($stats['uploads_24h'])) ?> uploads</div>
    </div>
    <div class="stat <?= $stats['failed_logins_24h'] > 0 ? 'stat--danger' : '' ?>">
        <div class="stat__icon"><?= icon('shield-check') ?></div>
        <div class="stat__label">Failed logins (24h)</div>
        <div class="stat__value tabular"><?= e(number_format($stats['failed_logins_24h'])) ?></div>
        <div class="stat__meta"><?= e((string) $stats['active_sessions']) ?> live SFTP sessions</div>
    </div>
</div>

<div class="grid grid-main">
    <div>
        <div class="card">
            <div class="card__head"><?= icon('bar-chart') ?><h2>Platform transfers</h2><span class="badge">14 days</span></div>
            <div class="card__body">
                <div id="chart-admin" data-chart='<?= e(json_encode([
                    'series' => [
                        ['label' => 'uploads', 'color' => 'var(--accent)', 'values' => $timeline['uploads']],
                        ['label' => 'downloads', 'color' => '#22c55e', 'values' => $timeline['downloads']],
                    ],
                    'options' => ['labels' => $timeline['labels'], 'height' => 220],
                ], JSON_UNESCAPED_SLASHES)) ?>'></div>
                <div class="chart-legend">
                    <span class="chart-legend__item"><span class="chart-legend__swatch" style="background:var(--accent)"></span> Uploads</span>
                    <span class="chart-legend__item"><span class="chart-legend__swatch" style="background:#22c55e"></span> Downloads</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card__head">
                <?= icon('users') ?><h2>Storage by user</h2>
                <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/users')) ?>">All users <?= icon('chevron-right', 'icon icon-sm') ?></a>
            </div>
            <div class="card__body card__body--flush">
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>User</th><th class="nowrap">Files</th><th style="min-width:180px">Usage</th><th class="nowrap right">Used</th></tr></thead>
                        <tbody>
                        <?php foreach ($topUsers as $user): ?>
                            <?php
                            $limit = (int) $user['quota_bytes'];
                            $used = (int) $user['used_bytes'];
                            $pct = $limit > 0 ? min(100, round($used / $limit * 100)) : 0;
                            ?>
                            <tr>
                                <td>
                                    <a href="<?= e(url('/admin/users/' . $user['id'])) ?>"><?= e($user['name']) ?></a>
                                    <div class="faint small"><?= e($user['email']) ?></div>
                                </td>
                                <td class="tabular"><?= e(number_format((int) $user['files'])) ?></td>
                                <td>
                                    <div class="progress"><div class="progress__bar <?= $pct >= 90 ? 'is-danger' : ($pct >= 75 ? 'is-warning' : '') ?>" style="width:<?= e((string) $pct) ?>%"></div></div>
                                    <div class="faint small mt-1"><?= $limit > 0 ? e((string) $pct) . '% of ' . e(bytes($limit)) : 'unlimited' ?></div>
                                </td>
                                <td class="right nowrap tabular"><?= e(bytes($used)) ?></td>
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
            <div class="card__head"><?= icon('zap') ?><h3>Job queue</h3></div>
            <div class="card__body">
                <div class="grid grid-2" style="gap:10px">
                    <?php foreach ([
                        'pending' => ['badge', 'clock'],
                        'running' => ['badge-info', 'play'],
                        'completed' => ['badge-success', 'check'],
                        'failed' => ['badge-danger', 'x'],
                    ] as $key => [$cls, $ic]): ?>
                        <div class="flex items-center gap-2">
                            <span class="badge <?= e($cls) ?>"><?= icon($ic) ?></span>
                            <span class="flex-1 small"><?= e(ucfirst($key)) ?></span>
                            <span class="strong tabular"><?= e((string) ($jobs[$key] ?? 0)) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <a class="btn btn-sm btn-block mt-3" href="<?= e(url('/admin/jobs')) ?>"><?= icon('sliders') ?> Manage jobs</a>
            </div>
        </div>

        <div class="card">
            <div class="card__head"><?= icon('pie-chart') ?><h3>Storage by type</h3></div>
            <div class="card__body">
                <?php if ($byType === []): ?>
                    <p class="faint small">No files stored yet.</p>
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

        <div class="card">
            <div class="card__head">
                <?= icon('activity') ?><h3>Recent events</h3>
                <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/logs')) ?>"><?= icon('chevron-right', 'icon icon-sm') ?></a>
            </div>
            <div class="card__body card__body--flush">
                <table class="table">
                    <tbody>
                    <?php foreach ($activity as $entry): ?>
                        <tr>
                            <td>
                                <span class="badge <?= $entry['status'] === 'failed' ? 'badge-danger' : '' ?>"><?= e($entry['action']) ?></span>
                                <div class="small muted mt-1 truncate" style="max-width:230px"><?= e((string) $entry['description']) ?></div>
                                <div class="faint small"><?= e((string) ($entry['user_name'] ?? 'system')) ?></div>
                            </td>
                            <td class="right faint small nowrap"><?= e(time_ago($entry['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
View::endSection();
