<?php

use App\Core\View;
use App\Services\Auth;
use App\Services\StatsService;

$quota = isset($authUser) && $authUser !== null
    ? \App\Services\QuotaService::check((int) $authUser['id'])
    : ['limit' => 0, 'used' => 0, 'percent' => 0.0];

$quotaClass = $quota['percent'] >= 90 ? 'is-danger' : ($quota['percent'] >= 75 ? 'is-warning' : '');
?><!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? 'Dashboard') . ' — ' . ($siteName ?? 'S3 Lite')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#4f7cff" stroke-width="2" stroke-linecap="round"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>') ?>">
    <style>:root { --accent: <?= e($accentColor ?? '#4f7cff') ?>; }</style>
</head>
<body>
<?= View::include('partials.icons') ?>

<div class="shell">
    <aside class="sidebar">
        <div class="sidebar__brand">
            <span class="sidebar__logo"><?= icon('cloud') ?></span>
            <span class="sidebar__name"><?= e($siteName ?? 'S3 Lite') ?></span>
        </div>

        <nav class="sidebar__nav">
            <div class="nav-group">
                <div class="nav-group__label">Workspace</div>
                <a class="nav-item <?= active_class('/dashboard') ?>" href="<?= e(url('/dashboard')) ?>">
                    <?= icon('home') ?><span>Dashboard</span>
                </a>
                <a class="nav-item <?= active_class('/files') ?>" href="<?= e(url('/files')) ?>">
                    <?= icon('folder') ?><span>Files</span>
                </a>
                <a class="nav-item <?= active_class('/shares') ?>" href="<?= e(url('/shares')) ?>">
                    <?= icon('link') ?><span>Share links</span>
                </a>
                <a class="nav-item <?= active_class('/trash') ?>" href="<?= e(url('/trash')) ?>">
                    <?= icon('trash') ?><span>Trash</span>
                </a>
            </div>

            <div class="nav-group">
                <div class="nav-group__label">Account</div>
                <a class="nav-item <?= $currentPath === '/settings' ? 'is-active' : '' ?>" href="<?= e(url('/settings')) ?>">
                    <?= icon('user') ?><span>Profile</span>
                </a>
                <a class="nav-item <?= active_class('/settings/api-keys') ?>" href="<?= e(url('/settings/api-keys')) ?>">
                    <?= icon('key') ?><span>API keys</span>
                </a>
                <a class="nav-item <?= active_class('/settings/sftp') ?>" href="<?= e(url('/settings/sftp')) ?>">
                    <?= icon('terminal') ?><span>FTP / SFTP</span>
                </a>
                <a class="nav-item <?= active_class('/settings/webhooks') ?>" href="<?= e(url('/settings/webhooks')) ?>">
                    <?= icon('webhook') ?><span>Webhooks</span>
                </a>
                <a class="nav-item <?= active_class('/api-docs') ?>" href="<?= e(url('/api-docs')) ?>">
                    <?= icon('book') ?><span>API docs</span>
                </a>
            </div>

            <?php if (Auth::isAdmin()): ?>
                <div class="nav-group">
                    <div class="nav-group__label">Administration</div>
                    <a class="nav-item <?= $currentPath === '/admin' ? 'is-active' : '' ?>" href="<?= e(url('/admin')) ?>">
                        <?= icon('activity') ?><span>Overview</span>
                    </a>
                    <a class="nav-item <?= active_class('/admin/users') ?>" href="<?= e(url('/admin/users')) ?>">
                        <?= icon('users') ?><span>Users</span>
                    </a>
                    <a class="nav-item <?= active_class('/admin/roles') ?>" href="<?= e(url('/admin/roles')) ?>">
                        <?= icon('shield') ?><span>Roles</span>
                    </a>
                    <a class="nav-item <?= active_class('/admin/files') ?>" href="<?= e(url('/admin/files')) ?>">
                        <?= icon('files') ?><span>All files</span>
                    </a>
                    <a class="nav-item <?= active_class('/admin/sftp') ?>" href="<?= e(url('/admin/sftp')) ?>">
                        <?= icon('server') ?><span>FTP / SFTP</span>
                    </a>
                    <a class="nav-item <?= active_class('/admin/monitoring') ?>" href="<?= e(url('/admin/monitoring')) ?>">
                        <?= icon('cpu') ?><span>Monitoring</span>
                    </a>
                    <a class="nav-item <?= active_class('/admin/jobs') ?>" href="<?= e(url('/admin/jobs')) ?>">
                        <?= icon('zap') ?><span>Jobs &amp; backups</span>
                    </a>
                    <a class="nav-item <?= active_class('/admin/logs') ?>" href="<?= e(url('/admin/logs')) ?>">
                        <?= icon('file-text') ?><span>Logs</span>
                    </a>
                    <a class="nav-item <?= active_class('/admin/ip-rules') ?>" href="<?= e(url('/admin/ip-rules')) ?>">
                        <?= icon('shield-check') ?><span>IP rules</span>
                    </a>
                    <a class="nav-item <?= active_class('/admin/settings') ?>" href="<?= e(url('/admin/settings')) ?>">
                        <?= icon('sliders') ?><span>Settings</span>
                    </a>
                </div>
            <?php endif; ?>
        </nav>

        <div class="sidebar__footer">
            <div class="quota-card">
                <div class="quota-card__head">
                    <span>Storage</span>
                    <span class="quota-card__value"><?= e(bytes($quota['used'])) ?></span>
                </div>
                <div class="progress">
                    <div class="progress__bar <?= e($quotaClass) ?>" style="width: <?= e((string) min(100, $quota['percent'])) ?>%"></div>
                </div>
                <div class="quota-card__head mt-2" style="margin-bottom:0">
                    <span><?= $quota['limit'] > 0 ? e((string) $quota['percent']) . '% used' : 'Unlimited' ?></span>
                    <span><?= $quota['limit'] > 0 ? e(bytes($quota['limit'])) : '' ?></span>
                </div>
            </div>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="btn btn-ghost btn-icon sidebar-toggle" type="button" data-sidebar-toggle aria-label="Toggle navigation">
                <?= icon('menu') ?>
            </button>

            <div class="topbar__title"><?= e($title ?? 'Dashboard') ?></div>

            <div class="topbar__spacer"></div>

            <form class="search hidden" style="display:none"></form>

            <div class="dropdown">
                <button class="btn btn-ghost btn-icon" type="button" data-dropdown aria-label="Notifications">
                    <?= icon('bell') ?>
                    <?php if (($notificationCount ?? 0) > 0): ?>
                        <span class="badge badge-accent" style="margin-left:-4px"><?= e((string) $notificationCount) ?></span>
                    <?php endif; ?>
                </button>
                <div class="dropdown__menu" style="min-width:300px">
                    <div class="dropdown__label">Notifications</div>
                    <?php if (($notifications ?? []) === []): ?>
                        <div class="dropdown__item faint" style="cursor:default">Nothing new</div>
                    <?php else: ?>
                        <?php foreach ($notifications as $note): ?>
                            <a class="dropdown__item" href="<?= e($note['link'] ?: url('/dashboard')) ?>">
                                <?= icon($note['type'] === 'warning' ? 'alert' : 'info') ?>
                                <span class="flex-1">
                                    <span class="strong"><?= e($note['title']) ?></span><br>
                                    <span class="small faint"><?= e($note['body']) ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                        <div class="dropdown__divider"></div>
                        <form method="post" action="<?= e(url('/notifications/read')) ?>">
                            <?= csrf_field() ?>
                            <button class="dropdown__item" type="submit"><?= icon('check') ?> Mark all as read</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <button class="btn btn-ghost btn-icon" type="button" data-theme-toggle aria-label="Toggle theme">
                <span data-theme-icon><?= icon('sun') ?></span>
            </button>

            <div class="dropdown">
                <button class="btn btn-ghost" type="button" data-dropdown>
                    <span class="avatar avatar-sm" style="background: <?= e($authUser['avatar_color'] ?? '#4f7cff') ?>">
                        <?= e(\App\Support\Str::initials((string) ($authUser['name'] ?? 'U'))) ?>
                    </span>
                    <span class="nowrap"><?= e($authUser['name'] ?? '') ?></span>
                    <?= icon('chevron-down', 'icon icon-sm') ?>
                </button>
                <div class="dropdown__menu">
                    <div class="dropdown__label"><?= e($authUser['email'] ?? '') ?></div>
                    <a class="dropdown__item" href="<?= e(url('/settings')) ?>"><?= icon('user') ?> Profile</a>
                    <a class="dropdown__item" href="<?= e(url('/settings/api-keys')) ?>"><?= icon('key') ?> API keys</a>
                    <?php if (Auth::isAdmin()): ?>
                        <a class="dropdown__item" href="<?= e(url('/admin')) ?>"><?= icon('shield') ?> Admin panel</a>
                    <?php endif; ?>
                    <div class="dropdown__divider"></div>
                    <form method="post" action="<?= e(url('/logout')) ?>">
                        <?= csrf_field() ?>
                        <button class="dropdown__item is-danger" type="submit"><?= icon('log-out') ?> Sign out</button>
                    </form>
                </div>
            </div>
        </header>

        <main class="content <?= e($contentClass ?? '') ?>">
            <?= View::include('partials.flash', ['flash' => $flash ?? [], 'errors' => $errors ?? []]) ?>
            <?= View::section('content') ?>
        </main>
    </div>
</div>

<div class="toasts"></div>

<script>window.S3_CONFIG = <?= json_encode([
    'baseUrl' => url(''),
    'apiBase' => url('api/v1'),
    'user'    => ['id' => (int) ($authUser['id'] ?? 0), 'name' => $authUser['name'] ?? ''],
], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?= View::section('scripts') ?>
</body>
</html>
