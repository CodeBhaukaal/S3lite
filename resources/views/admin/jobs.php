<?php

use App\Core\View;

View::extend('layouts.app');
View::share('title', 'Jobs &amp; backups');

/** @var list<array> $jobs */
/** @var array $stats */
/** @var array<string,string> $types */
/** @var list<array> $backups */

$jobIcons = [
    'cleanup' => 'trash', 'rescan' => 'refresh', 'backup' => 'save',
    'sftp.sync' => 'server', 'metrics.sample' => 'activity',
    'integrity.check' => 'shield-check', 'orphans.prune' => 'package',
    'webhook.deliver' => 'webhook', 'audit.purge' => 'file-text',
];

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('zap', 'icon icon-lg') ?> Jobs &amp; backups</h1>
        <div class="page-head__sub">Run maintenance now, or queue it for the worker.</div>
    </div>
    <div class="page-head__actions">
        <form method="post" action="<?= e(url('/admin/jobs/work')) ?>">
            <?= csrf_field() ?>
            <button class="btn" type="submit"><?= icon('play') ?> Drain queue now</button>
        </form>
        <form method="post" action="<?= e(url('/admin/backups')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-primary" type="submit"><?= icon('save') ?> Create backup</button>
        </form>
    </div>
</div>

<div class="grid grid-4 mb-4">
    <div class="stat"><div class="stat__icon"><?= icon('clock') ?></div><div class="stat__label">Pending</div><div class="stat__value tabular"><?= e((string) $stats['pending']) ?></div></div>
    <div class="stat stat--info"><div class="stat__icon"><?= icon('play') ?></div><div class="stat__label">Running</div><div class="stat__value tabular"><?= e((string) $stats['running']) ?></div></div>
    <div class="stat stat--success"><div class="stat__icon"><?= icon('check') ?></div><div class="stat__label">Completed</div><div class="stat__value tabular"><?= e((string) $stats['completed']) ?></div></div>
    <div class="stat <?= $stats['failed'] > 0 ? 'stat--danger' : '' ?>"><div class="stat__icon"><?= icon('x') ?></div><div class="stat__label">Failed</div><div class="stat__value tabular"><?= e((string) $stats['failed']) ?></div></div>
</div>

<div class="card mb-4">
    <div class="card__head"><?= icon('sliders') ?><h2>Maintenance jobs</h2></div>
    <div class="card__body">
        <div class="grid grid-2">
            <?php foreach ($types as $type => $description): ?>
                <?php if ($type === 'webhook.deliver') { continue; } ?>
                <div class="flex items-start gap-3" style="padding:10px; border:1px solid var(--border); border-radius: var(--radius)">
                    <span class="stat__icon" style="margin:0; width:32px; height:32px"><?= icon($jobIcons[$type] ?? 'zap', 'icon icon-sm') ?></span>
                    <div class="flex-1">
                        <div class="strong mono small"><?= e($type) ?></div>
                        <div class="faint small"><?= e($description) ?></div>
                    </div>
                    <div class="flex gap-1">
                        <form method="post" action="<?= e(url('/admin/jobs/run')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="type" value="<?= e($type) ?>">
                            <button class="btn btn-sm btn-primary" type="submit" title="Run now"><?= icon('play') ?></button>
                        </form>
                        <form method="post" action="<?= e(url('/admin/jobs/run')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="type" value="<?= e($type) ?>">
                            <input type="hidden" name="queue" value="1">
                            <button class="btn btn-sm" type="submit" title="Add to queue"><?= icon('clock') ?></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="alert alert-info mt-4">
            <?= icon('terminal') ?>
            <div class="alert__body">
                <div class="alert__title">Automate with cron / Task Scheduler</div>
                <pre class="code-block mt-2">* * * * *   php <?= e(base_path('bin/console')) ?> queue:run-once
0 3 * * *   php <?= e(base_path('bin/console')) ?> job:run cleanup
*/5 * * * * php <?= e(base_path('bin/console')) ?> job:run metrics.sample</pre>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-main">
    <div class="card">
        <div class="card__head"><?= icon('history') ?><h2>Recent jobs</h2></div>
        <div class="card__body card__body--flush">
            <?php if ($jobs === []): ?>
                <div class="card__body"><p class="faint small">The queue is empty.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Type</th><th>Status</th><th class="nowrap">Attempts</th><th>Output</th><th class="nowrap">When</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($jobs as $job): ?>
                            <tr>
                                <td class="mono small"><?= e($job['type']) ?></td>
                                <td>
                                    <span class="badge <?= match ($job['status']) {
                                        'completed' => 'badge-success',
                                        'failed'    => 'badge-danger',
                                        'running'   => 'badge-info',
                                        default     => '',
                                    } ?>"><?= e($job['status']) ?></span>
                                </td>
                                <td class="tabular"><?= e((string) $job['attempts']) ?>/<?= e((string) $job['max_attempts']) ?></td>
                                <td class="faint small truncate" style="max-width:280px">
                                    <?= e((string) ($job['error'] ?: $job['output'] ?: '—')) ?>
                                </td>
                                <td class="nowrap faint"><?= e(time_ago($job['created_at'])) ?></td>
                                <td class="right">
                                    <?php if ($job['status'] === 'failed'): ?>
                                        <form method="post" action="<?= e(url('/admin/jobs/' . $job['id'] . '/retry')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-ghost btn-icon" type="submit" title="Retry"><?= icon('refresh') ?></button>
                                        </form>
                                    <?php endif; ?>
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
        <div class="card__head"><?= icon('database') ?><h2>Backups</h2><span class="badge"><?= e((string) count($backups)) ?></span></div>
        <div class="card__body card__body--flush">
            <?php if ($backups === []): ?>
                <div class="card__body"><p class="faint small">No backups yet.</p></div>
            <?php else: ?>
                <table class="table">
                    <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td>
                                <div class="mono small truncate" style="max-width:200px"><?= e($backup['name']) ?></div>
                                <div class="faint small"><?= e(bytes($backup['size'])) ?> · <?= e(time_ago($backup['created_at'])) ?></div>
                            </td>
                            <td class="right">
                                <div class="actions">
                                    <a class="btn btn-sm btn-ghost btn-icon" href="<?= e(url('/admin/backups/' . $backup['name'] . '/download')) ?>" title="Download"><?= icon('download') ?></a>
                                    <form method="post" action="<?= e(url('/admin/backups/' . $backup['name'] . '/delete')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-ghost btn-icon" type="submit" data-confirm="Delete this backup?" title="Delete"><?= icon('trash') ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <div class="card__foot">
            <p class="faint small" style="margin:0">
                Restore with:
                <span class="code-inline">mysql -u root s3lite &lt; backup-file.sql</span>
            </p>
        </div>
    </div>
</div>
<?php
View::endSection();
