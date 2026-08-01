<?php

use App\Core\View;

View::extend('layouts.app');
View::share('title', 'Logs');

/** @var array $logs */
/** @var list<string> $actions */
/** @var array $filters */
/** @var list<array> $appLogs */
/** @var list<string> $logDates */
/** @var string $level */
/** @var string $date */
/** @var string $tab */

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('file-text', 'icon icon-lg') ?> Logs</h1>
        <div class="page-head__sub">Audit trail of every write, plus the raw application log.</div>
    </div>
    <div class="page-head__actions">
        <form method="post" action="<?= e(url('/admin/logs/purge')) ?>" class="flex gap-2">
            <?= csrf_field() ?>
            <input class="input" name="days" type="number" min="1" value="90" style="width:100px">
            <button class="btn btn-danger" type="submit" data-confirm="Delete audit entries older than the given number of days?">
                <?= icon('trash') ?> Purge older than
            </button>
        </form>
    </div>
</div>

<div class="tabs">
    <button class="tab <?= $tab !== 'app' ? 'is-active' : '' ?>" data-tab="audit" data-tab-group="logs"><?= icon('shield') ?> Audit trail</button>
    <button class="tab <?= $tab === 'app' ? 'is-active' : '' ?>" data-tab="app" data-tab-group="logs"><?= icon('terminal') ?> Application log</button>
</div>

<div data-tab-panel="audit" data-tab-group="logs" class="<?= $tab === 'app' ? 'hidden' : '' ?>">
    <form class="filters" method="get">
        <input type="hidden" name="tab" value="audit">
        <div class="field flex-1" style="max-width:300px">
            <div class="search">
                <?= icon('search') ?>
                <input class="input" type="search" name="q" placeholder="Search description, action or IP…" value="<?= e($filters['q']) ?>" data-search-input>
            </div>
        </div>
        <div class="field">
            <select class="select" name="action" data-auto-submit>
                <option value="">All actions</option>
                <?php foreach ($actions as $action): ?>
                    <option value="<?= e($action) ?>" <?= $filters['action'] === $action ? 'selected' : '' ?>><?= e($action) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <select class="select" name="status" data-auto-submit>
                <option value="">Any result</option>
                <option value="success" <?= $filters['status'] === 'success' ? 'selected' : '' ?>>Success</option>
                <option value="failed" <?= $filters['status'] === 'failed' ? 'selected' : '' ?>>Failed</option>
            </select>
        </div>
        <div class="field">
            <input class="input" type="date" name="from" value="<?= e($filters['from']) ?>">
        </div>
        <div class="field">
            <input class="input" type="date" name="to" value="<?= e($filters['to']) ?>">
        </div>
        <button class="btn" type="submit"><?= icon('filter') ?> Filter</button>
    </form>

    <div class="card">
        <div class="card__body card__body--flush">
            <?php if ($logs['data'] === []): ?>
                <div class="empty">
                    <div class="empty__icon"><?= icon('search', 'icon icon-hero') ?></div>
                    <div class="empty__title">No audit entries match</div>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th class="nowrap">When</th><th>Actor</th><th>Action</th><th>Description</th><th>Source</th><th>Result</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($logs['data'] as $entry): ?>
                            <tr>
                                <td class="nowrap faint small">
                                    <?= e(date('d M H:i:s', strtotime((string) $entry['created_at']))) ?>
                                </td>
                                <td>
                                    <?php if ($entry['user_name'] !== null): ?>
                                        <a href="<?= e(url('/admin/users/' . $entry['user_id'])) ?>"><?= e((string) $entry['user_name']) ?></a>
                                    <?php else: ?>
                                        <span class="badge"><?= e((string) $entry['actor_type']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-accent mono"><?= e($entry['action']) ?></span></td>
                                <td class="muted" style="max-width:340px"><?= e((string) $entry['description']) ?></td>
                                <td class="faint small mono nowrap">
                                    <?= e((string) $entry['ip']) ?>
                                    <?php if ($entry['method'] !== null): ?>
                                        <div><?= e((string) $entry['method']) ?> <?= e((string) $entry['path']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $entry['status'] === 'failed' ? 'badge-danger' : 'badge-success' ?>">
                                        <?= e($entry['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?= View::include('partials.pagination', ['result' => $logs]) ?>
</div>

<div data-tab-panel="app" data-tab-group="logs" class="<?= $tab === 'app' ? '' : 'hidden' ?>">
    <form class="filters" method="get">
        <input type="hidden" name="tab" value="app">
        <div class="field">
            <select class="select" name="date" data-auto-submit>
                <?php if ($logDates === []): ?>
                    <option value="">No log files</option>
                <?php endif; ?>
                <?php foreach ($logDates as $logDate): ?>
                    <option value="<?= e($logDate) ?>" <?= $date === $logDate ? 'selected' : '' ?>><?= e($logDate) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <select class="select" name="level" data-auto-submit>
                <option value="">All levels</option>
                <?php foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $option): ?>
                    <option value="<?= e($option) ?>" <?= $level === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn" type="submit"><?= icon('filter') ?> Filter</button>
    </form>

    <div class="card">
        <div class="card__body">
            <?php if ($appLogs === []): ?>
                <div class="empty" style="padding:34px">
                    <div class="empty__icon"><?= icon('file-text', 'icon icon-hero') ?></div>
                    <div class="empty__title">No log entries</div>
                    <div class="empty__text">Nothing has been written to the application log for this date.</div>
                </div>
            <?php else: ?>
                <?php foreach ($appLogs as $entry): ?>
                    <div class="log-line">
                        <span class="log-line__time"><?= e(date('H:i:s', strtotime((string) $entry['time']))) ?></span>
                        <span class="log-line__level <?= e((string) $entry['level']) ?>"><?= e((string) $entry['level']) ?></span>
                        <span class="log-line__msg">
                            <?= e((string) $entry['message']) ?>
                            <?php if (($entry['context'] ?? []) !== []): ?>
                                <div class="faint" style="margin-top:3px"><?= e(json_encode($entry['context'], JSON_UNESCAPED_SLASHES)) ?></div>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
View::endSection();
