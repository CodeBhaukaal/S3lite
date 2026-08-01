<?php

use App\Core\View;

/** @var array{ok:bool, checks:list<array>} $requirements */
/** @var array $defaults */
/** @var list<string> $timezones */
?><!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title>Install — S3 Lite</title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<?= View::include('partials.icons') ?>

<div class="wizard">
    <div class="wizard__card">
        <div class="auth__brand mb-5">
            <span class="auth__brand-logo"><?= icon('cloud', 'icon icon-lg') ?></span>
            <span class="auth__brand-name">S3 Lite installer</span>
        </div>

        <div class="wizard__steps">
            <div class="wizard__step is-active" data-step-indicator="1"><div class="wizard__step-dot">1</div>Requirements</div>
            <div class="wizard__step" data-step-indicator="2"><div class="wizard__step-dot">2</div>Database</div>
            <div class="wizard__step" data-step-indicator="3"><div class="wizard__step-dot">3</div>Application</div>
            <div class="wizard__step" data-step-indicator="4"><div class="wizard__step-dot">4</div>Administrator</div>
        </div>

        <div class="card">
            <div class="card__body">
                <!-- Step 1 -->
                <section class="wizard__panel is-active" data-step="1">
                    <h2>Server requirements</h2>
                    <p class="muted small mb-4">Everything marked as required must pass before the platform can be installed.</p>

                    <?php foreach ($requirements['checks'] as $check): ?>
                        <div class="req-row">
                            <?php if ($check['status'] === 'ok'): ?>
                                <?= icon('check-circle', 'icon text-success') ?>
                            <?php elseif ($check['status'] === 'warn'): ?>
                                <?= icon('alert', 'icon text-warning') ?>
                            <?php else: ?>
                                <?= icon('x-circle', 'icon text-danger') ?>
                            <?php endif; ?>
                            <span class="req-row__name"><?= e($check['name']) ?></span>
                            <span class="req-row__msg"><?= e($check['message']) ?></span>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!$requirements['ok']): ?>
                        <div class="alert alert-danger mt-4">
                            <?= icon('alert') ?>
                            <div class="alert__body">Fix the failing requirements above, then reload this page.</div>
                        </div>
                    <?php endif; ?>

                    <div class="form-actions">
                        <button class="btn btn-primary" type="button" data-next="2" <?= $requirements['ok'] ? '' : 'disabled' ?>>
                            Continue <?= icon('arrow-right') ?>
                        </button>
                        <button class="btn" type="button" onclick="location.reload()"><?= icon('refresh') ?> Re-check</button>
                    </div>
                </section>

                <!-- Step 2 -->
                <section class="wizard__panel" data-step="2">
                    <h2>Database connection</h2>
                    <p class="muted small mb-4">The database is created automatically if it does not exist yet.</p>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="db_host">Host <span class="req">*</span></label>
                            <input class="input" id="db_host" value="<?= e($defaults['db_host']) ?>">
                        </div>
                        <div class="field">
                            <label class="label" for="db_port">Port <span class="req">*</span></label>
                            <input class="input" id="db_port" type="number" value="<?= e((string) $defaults['db_port']) ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label class="label" for="db_name">Database name <span class="req">*</span></label>
                        <input class="input" id="db_name" value="<?= e($defaults['db_name']) ?>">
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="db_user">Username <span class="req">*</span></label>
                            <input class="input" id="db_user" value="<?= e($defaults['db_user']) ?>">
                        </div>
                        <div class="field">
                            <label class="label" for="db_pass">Password</label>
                            <input class="input" id="db_pass" type="password" autocomplete="new-password">
                        </div>
                    </div>

                    <div id="db-result" class="mt-3"></div>

                    <hr>

                    <h3>Redis cache <span class="badge">optional</span></h3>
                    <p class="muted small mb-3">If Redis is unreachable the platform falls back to a file cache automatically.</p>

                    <label class="switch mb-3">
                        <input type="checkbox" id="redis_enabled" checked>
                        <span class="switch__track"></span>
                        <span>Use Redis for caching and rate limiting</span>
                    </label>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="redis_host">Redis host</label>
                            <input class="input" id="redis_host" value="<?= e($defaults['redis_host']) ?>">
                        </div>
                        <div class="field">
                            <label class="label" for="redis_port">Redis port</label>
                            <input class="input" id="redis_port" type="number" value="<?= e((string) $defaults['redis_port']) ?>">
                        </div>
                        <div class="field">
                            <label class="label" for="redis_password">Redis password</label>
                            <input class="input" id="redis_password" type="password" autocomplete="new-password">
                        </div>
                    </div>

                    <div id="redis-result" class="mt-3"></div>

                    <div class="form-actions">
                        <button class="btn" type="button" data-next="1"><?= icon('arrow-left') ?> Back</button>
                        <button class="btn" type="button" id="test-db"><?= icon('database') ?> Test database</button>
                        <button class="btn" type="button" id="test-redis"><?= icon('zap') ?> Test Redis</button>
                        <button class="btn btn-primary" type="button" data-next="3">Continue <?= icon('arrow-right') ?></button>
                    </div>
                </section>

                <!-- Step 3 -->
                <section class="wizard__panel" data-step="3">
                    <h2>Application</h2>
                    <p class="muted small mb-4">These values are written to your .env file.</p>

                    <div class="field">
                        <label class="label" for="app_name">Site name <span class="req">*</span></label>
                        <input class="input" id="app_name" value="S3 Lite">
                    </div>

                    <div class="field">
                        <label class="label" for="app_url">Public URL <span class="req">*</span></label>
                        <input class="input" id="app_url" value="<?= e($defaults['app_url']) ?>">
                        <div class="hint">The address of the <span class="code-inline">public/</span> directory, without a trailing slash.</div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="timezone">Timezone</label>
                            <select class="select" id="timezone">
                                <?php foreach ($timezones as $tz): ?>
                                    <option value="<?= e($tz) ?>" <?= $tz === $defaults['timezone'] ? 'selected' : '' ?>><?= e($tz) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label class="label" for="app_env">Environment</label>
                            <select class="select" id="app_env">
                                <option value="production">Production</option>
                                <option value="local">Local / development</option>
                            </select>
                        </div>
                    </div>

                    <label class="check">
                        <input type="checkbox" id="app_debug">
                        <span>
                            <span class="check__text">Enable debug mode</span>
                            <span class="check__desc">Shows full stack traces. Never leave this on in production.</span>
                        </span>
                    </label>

                    <div class="form-actions">
                        <button class="btn" type="button" data-next="2"><?= icon('arrow-left') ?> Back</button>
                        <button class="btn btn-primary" type="button" data-next="4">Continue <?= icon('arrow-right') ?></button>
                    </div>
                </section>

                <!-- Step 4 -->
                <section class="wizard__panel" data-step="4">
                    <h2>Administrator account</h2>
                    <p class="muted small mb-4">This account gets unlimited storage and full access.</p>

                    <div class="field">
                        <label class="label" for="admin_name">Name <span class="req">*</span></label>
                        <input class="input" id="admin_name" value="Administrator">
                    </div>

                    <div class="field">
                        <label class="label" for="admin_email">Email <span class="req">*</span></label>
                        <input class="input" id="admin_email" type="email" placeholder="admin@example.com">
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="admin_password">Password <span class="req">*</span></label>
                            <input class="input" id="admin_password" type="password" autocomplete="new-password">
                            <div class="hint">Minimum 8 characters, with letters and numbers.</div>
                        </div>
                        <div class="field">
                            <label class="label" for="admin_password_confirmation">Confirm password <span class="req">*</span></label>
                            <input class="input" id="admin_password_confirmation" type="password" autocomplete="new-password">
                        </div>
                    </div>

                    <div id="install-result" class="mt-3"></div>

                    <div class="form-actions">
                        <button class="btn" type="button" data-next="3"><?= icon('arrow-left') ?> Back</button>
                        <button class="btn btn-primary btn-lg" type="button" id="run-install">
                            <?= icon('play') ?> Install now
                        </button>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>

<div class="toasts"></div>
<script>window.S3_CONFIG = <?= json_encode(['baseUrl' => url('')], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script>
(function () {
    const $ = (s) => document.querySelector(s);
    const val = (id) => { const el = document.getElementById(id); return el ? el.value : ''; };
    const checked = (id) => { const el = document.getElementById(id); return el ? el.checked : false; };

    function goto(step) {
        document.querySelectorAll('.wizard__panel').forEach((p) => {
            p.classList.toggle('is-active', p.getAttribute('data-step') === String(step));
        });
        document.querySelectorAll('[data-step-indicator]').forEach((el) => {
            const n = Number(el.getAttribute('data-step-indicator'));
            el.classList.toggle('is-active', n === Number(step));
            el.classList.toggle('is-done', n < Number(step));
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    document.querySelectorAll('[data-next]').forEach((btn) => {
        btn.addEventListener('click', () => goto(btn.getAttribute('data-next')));
    });

    function notice(hostId, ok, message) {
        const host = document.getElementById(hostId);
        host.innerHTML = '<div class="alert ' + (ok ? 'alert-success' : 'alert-danger') + '">' +
            '<svg class="icon"><use href="#i-' + (ok ? 'check-circle' : 'x-circle') + '"></use></svg>' +
            '<div class="alert__body">' + S3.escapeHtml(message) + '</div></div>';
    }

    async function post(path, body) {
        const form = new FormData();
        Object.keys(body).forEach((k) => form.append(k, body[k]));
        form.append('_token', S3.csrf());

        const response = await fetch(S3.url(path), {
            method: 'POST',
            body: form,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        const text = await response.text();
        try { return { status: response.status, payload: JSON.parse(text) }; }
        catch (e) { return { status: response.status, payload: { error: { message: text.slice(0, 400) } } }; }
    }

    $('#test-db').addEventListener('click', async () => {
        const res = await post('/install/test-database', {
            db_host: val('db_host'), db_port: val('db_port'), db_name: val('db_name'),
            db_user: val('db_user'), db_pass: val('db_pass'),
        });
        const data = res.payload.data || {};
        notice('db-result', !!data.ok, data.message || (res.payload.error && res.payload.error.message) || 'Unknown error');
    });

    $('#test-redis').addEventListener('click', async () => {
        const res = await post('/install/test-redis', {
            redis_host: val('redis_host'), redis_port: val('redis_port'), redis_password: val('redis_password'),
        });
        const data = res.payload.data || {};
        notice('redis-result', !!data.ok, data.message || 'Unknown error');
    });

    $('#run-install').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        button.innerHTML = 'Installing…';

        const res = await post('/install/run', {
            app_name: val('app_name'),
            app_url: val('app_url'),
            app_env: val('app_env'),
            app_debug: checked('app_debug') ? '1' : '0',
            timezone: val('timezone'),
            db_host: val('db_host'), db_port: val('db_port'), db_name: val('db_name'),
            db_user: val('db_user'), db_pass: val('db_pass'),
            redis_enabled: checked('redis_enabled') ? '1' : '0',
            redis_host: val('redis_host'), redis_port: val('redis_port'), redis_password: val('redis_password'),
            admin_name: val('admin_name'),
            admin_email: val('admin_email'),
            admin_password: val('admin_password'),
            admin_password_confirmation: val('admin_password_confirmation'),
        });

        if (res.payload && res.payload.success) {
            notice('install-result', true, 'Installation complete. Redirecting to the sign-in page…');
            setTimeout(() => { window.location.href = res.payload.data.login_url; }, 1200);
            return;
        }

        button.disabled = false;
        button.innerHTML = 'Install now';

        const error = (res.payload && res.payload.error) || {};
        let message = error.message || 'Installation failed.';

        if (error.details) {
            const parts = [];
            Object.keys(error.details).forEach((k) => {
                const list = error.details[k];
                parts.push(Array.isArray(list) ? list[0] : list);
            });
            if (parts.length) message = parts.join(' ');
        }

        notice('install-result', false, message);
    });
})();
</script>
</body>
</html>
