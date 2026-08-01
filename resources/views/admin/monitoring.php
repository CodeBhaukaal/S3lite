<?php

use App\Core\View;
use App\Models\SftpSession;

View::extend('layouts.app');
View::share('title', 'Monitoring');

/** @var array $system */
/** @var array $health */
/** @var array $throughput */
/** @var array $cpuSeries */
/** @var array $memSeries */
/** @var array $diskSeries */
/** @var array $stats */
/** @var list<array> $sessions */
/** @var list<array> $failedLogins */
/** @var array $jobs */

$statusBadge = [
    'ok' => ['badge-success', 'check-circle'],
    'degraded' => ['badge-warning', 'alert'],
    'fail' => ['badge-danger', 'x-circle'],
    'skipped' => ['badge', 'minus'],
];

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('cpu', 'icon icon-lg') ?> Monitoring</h1>
        <div class="page-head__sub">
            Live host metrics, service health and transfer throughput. Auto-refreshes every 15 seconds.
        </div>
    </div>
    <div class="page-head__actions">
        <form method="post" action="<?= e(url('/admin/jobs/run')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="type" value="metrics.sample">
            <button class="btn" type="submit"><?= icon('save') ?> Record sample</button>
        </form>
        <button class="btn btn-primary" type="button" onclick="location.reload()"><?= icon('refresh') ?> Refresh</button>
    </div>
</div>

<div class="grid grid-3 mb-4">
    <div class="card">
        <div class="card__body gauge">
            <div class="gauge__ring" id="ring-cpu" data-ring='<?= e(json_encode(['percent' => $system['cpu']['percent'], 'color' => 'var(--accent)'])) ?>'></div>
            <div class="gauge__body">
                <div class="gauge__label">CPU</div>
                <div class="strong" style="font-size:16px" data-live="cpu"><?= e((string) $system['cpu']['percent']) ?>%</div>
                <div class="gauge__meta"><?= e((string) $system['cpu']['cores']) ?> cores</div>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card__body gauge">
            <div class="gauge__ring" id="ring-mem" data-ring='<?= e(json_encode(['percent' => $system['memory']['percent'], 'color' => '#7c5cff'])) ?>'></div>
            <div class="gauge__body">
                <div class="gauge__label">Memory</div>
                <div class="strong" style="font-size:16px" data-live="mem"><?= e(bytes($system['memory']['used'])) ?></div>
                <div class="gauge__meta">of <?= e(bytes($system['memory']['total'])) ?> · PHP <?= e(bytes($system['memory']['php_usage'])) ?></div>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card__body gauge">
            <div class="gauge__ring" id="ring-disk" data-ring='<?= e(json_encode(['percent' => $system['disk']['percent'], 'color' => $system['disk']['percent'] >= 85 ? '#ef4444' : '#22c55e'])) ?>'></div>
            <div class="gauge__body">
                <div class="gauge__label">Disk</div>
                <div class="strong" style="font-size:16px"><?= e(bytes($system['disk']['free'])) ?> free</div>
                <div class="gauge__meta truncate" style="max-width:200px"><?= e($system['disk']['path']) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-4 mb-4">
    <div class="stat stat--success">
        <div class="stat__icon"><?= icon('download') ?></div>
        <div class="stat__label">Download throughput</div>
        <div class="stat__value tabular"><?= e(bytes($throughput['download_bps'])) ?>/s</div>
        <div class="stat__meta"><?= e(bytes($throughput['download_bytes'])) ?> in the last hour</div>
    </div>
    <div class="stat">
        <div class="stat__icon"><?= icon('upload') ?></div>
        <div class="stat__label">Upload throughput</div>
        <div class="stat__value tabular"><?= e(bytes($throughput['upload_bps'])) ?>/s</div>
        <div class="stat__meta"><?= e(bytes($throughput['upload_bytes'])) ?> in the last hour</div>
    </div>
    <div class="stat stat--info">
        <div class="stat__icon"><?= icon('activity') ?></div>
        <div class="stat__label">Live sessions</div>
        <div class="stat__value tabular"><?= e((string) count($sessions)) ?></div>
        <div class="stat__meta">FTP / SFTP transfers</div>
    </div>
    <div class="stat <?= ($jobs['failed'] ?? 0) > 0 ? 'stat--danger' : '' ?>">
        <div class="stat__icon"><?= icon('zap') ?></div>
        <div class="stat__label">Queue</div>
        <div class="stat__value tabular"><?= e((string) ($jobs['pending'] ?? 0)) ?></div>
        <div class="stat__meta"><?= e((string) ($jobs['failed'] ?? 0)) ?> failed jobs</div>
    </div>
</div>

<div class="card mb-4">
    <div class="card__head"><?= icon('activity') ?><h2>Service health</h2>
        <?php [$cls, $ic] = $statusBadge[$health['status']] ?? $statusBadge['ok']; ?>
        <span class="badge <?= e($cls) ?>"><?= icon($ic) ?> <?= e($health['status']) ?></span>
    </div>
    <div class="card__body card__body--flush">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Check</th><th>Detail</th><th class="nowrap">Latency</th><th class="right">Status</th></tr></thead>
                <tbody>
                <?php foreach ($health['checks'] as $name => $check): ?>
                    <?php [$cls, $ic] = $statusBadge[$check['status']] ?? $statusBadge['skipped']; ?>
                    <tr>
                        <td class="strong"><?= e(ucwords(str_replace('_', ' ', (string) $name))) ?></td>
                        <td class="muted"><?= e($check['message']) ?></td>
                        <td class="nowrap tabular"><?= isset($check['latency_ms']) ? e((string) $check['latency_ms']) . ' ms' : '—' ?></td>
                        <td class="right"><span class="badge <?= e($cls) ?>"><?= icon($ic) ?> <?= e($check['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid grid-2 mb-4">
    <div class="card">
        <div class="card__head"><?= icon('trending-up') ?><h3>CPU &amp; memory (24h)</h3></div>
        <div class="card__body">
            <?php if ($cpuSeries['values'] === [] && $memSeries['values'] === []): ?>
                <div class="empty" style="padding:30px">
                    <div class="empty__text">
                        No samples recorded yet. Run the <span class="code-inline">metrics.sample</span> job
                        (or schedule it via cron) to build this chart.
                    </div>
                </div>
            <?php else: ?>
                <div id="chart-sys" data-chart='<?= e(json_encode([
                    'series' => [
                        ['label' => '% CPU', 'color' => 'var(--accent)', 'values' => $cpuSeries['values']],
                        ['label' => '% memory', 'color' => '#7c5cff', 'values' => $memSeries['values']],
                    ],
                    'options' => ['labels' => $cpuSeries['labels'], 'height' => 190],
                ], JSON_UNESCAPED_SLASHES)) ?>'></div>
                <div class="chart-legend">
                    <span class="chart-legend__item"><span class="chart-legend__swatch" style="background:var(--accent)"></span> CPU %</span>
                    <span class="chart-legend__item"><span class="chart-legend__swatch" style="background:#7c5cff"></span> Memory %</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card__head"><?= icon('server') ?><h3>Runtime</h3></div>
        <div class="card__body">
            <dl class="kv">
                <dt>Hostname</dt><dd class="mono small"><?= e($system['hostname']) ?></dd>
                <dt>Platform</dt><dd><?= e($system['platform']) ?></dd>
                <dt>Uptime</dt><dd><?= e($system['uptime']['human']) ?></dd>
                <dt>PHP</dt><dd><?= e($system['php']['version']) ?> (<?= e($system['php']['sapi']) ?>)</dd>
                <dt>Memory limit</dt><dd><?= e((string) $system['php']['memory_limit']) ?></dd>
                <dt>Upload limits</dt><dd><?= e((string) $system['php']['upload_max']) ?> / <?= e((string) $system['php']['post_max']) ?></dd>
                <dt>Files indexed</dt><dd class="tabular"><?= e(number_format($stats['files'])) ?></dd>
                <dt>Bytes stored</dt><dd><?= e(bytes($system['disk']['stored'])) ?></dd>
            </dl>

            <div class="label mt-3">Extensions</div>
            <div class="flex gap-1 flex-wrap">
                <?php foreach ($system['php']['extensions'] as $extension => $loaded): ?>
                    <span class="badge <?= $loaded ? 'badge-success' : '' ?>">
                        <?= icon($loaded ? 'check' : 'minus') ?> <?= e((string) $extension) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card__head"><?= icon('terminal') ?><h3>Active transfer sessions</h3></div>
        <div class="card__body card__body--flush">
            <?php if ($sessions === []): ?>
                <div class="card__body"><p class="faint small">No active FTP/SFTP sessions.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Account</th><th>IP</th><th>Traffic</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($sessions as $session): ?>
                            <?php $data = SftpSession::publicArray($session); ?>
                            <tr>
                                <td>
                                    <span class="mono strong"><?= e((string) $data['username']) ?></span>
                                    <div class="faint small"><?= e((string) ($session['owner_name'] ?? '')) ?></div>
                                </td>
                                <td class="mono small"><?= e((string) $data['ip']) ?></td>
                                <td class="small tabular"><?= e(bytes($data['bytes_in'])) ?> in / <?= e(bytes($data['bytes_out'])) ?> out</td>
                                <td class="right">
                                    <form method="post" action="<?= e(url('/admin/sftp/sessions/' . $session['id'] . '/close')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-danger btn-icon" type="submit" title="Disconnect"><?= icon('power') ?></button>
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
        <div class="card__head"><?= icon('shield-check') ?><h3>Recent failed logins</h3></div>
        <div class="card__body card__body--flush">
            <?php if ($failedLogins === []): ?>
                <div class="card__body"><p class="faint small">No failed sign-in attempts recorded.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Email</th><th>IP</th><th>Reason</th><th class="nowrap">When</th></tr></thead>
                        <tbody>
                        <?php foreach ($failedLogins as $attempt): ?>
                            <tr>
                                <td class="truncate" style="max-width:180px"><?= e((string) $attempt['email']) ?></td>
                                <td class="mono small"><?= e((string) $attempt['ip']) ?></td>
                                <td><span class="badge badge-danger"><?= e((string) ($attempt['reason'] ?: 'failed')) ?></span></td>
                                <td class="nowrap faint"><?= e(time_ago($attempt['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="card__foot">
            <a class="btn btn-sm" href="<?= e(url('/admin/ip-rules')) ?>"><?= icon('shield') ?> Manage IP rules</a>
        </div>
    </div>
</div>
<?php
View::endSection();

View::startSection('scripts');
?>
<script>
setInterval(async function () {
    try {
        const payload = await S3.request(<?= json_encode(url('/admin/monitoring/data')) ?>);
        const sys = payload.data.system;

        S3.charts.ring('ring-cpu', sys.cpu.percent, 'var(--accent)');
        S3.charts.ring('ring-mem', sys.memory.percent, '#7c5cff');
        S3.charts.ring('ring-disk', sys.disk.percent, sys.disk.percent >= 85 ? '#ef4444' : '#22c55e');

        const cpu = document.querySelector('[data-live="cpu"]');
        if (cpu) cpu.textContent = sys.cpu.percent + '%';
        const mem = document.querySelector('[data-live="mem"]');
        if (mem) mem.textContent = S3.bytes(sys.memory.used);
    } catch (e) {
        // A transient failure should not spam the operator.
    }
}, 15000);
</script>
<?php
View::endSection();
