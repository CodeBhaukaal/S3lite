<?php

use App\Core\View;
use App\Services\SettingService;

View::extend('layouts.app');
View::share('title', 'Settings');

/** @var array<string, array<string, mixed>> $settings */
/** @var array $defaults */

$get = static function (string $group, string $key, mixed $fallback = null) use ($settings) {
    return $settings[$group][$key] ?? $fallback;
};

$groups = [
    'branding' => ['Branding', 'globe'],
    'general'  => ['General', 'sliders'],
    'uploads'  => ['Uploads', 'upload'],
    'security' => ['Security', 'shield'],
    'services' => ['Transfer services', 'server'],
];

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('sliders', 'icon icon-lg') ?> Platform settings</h1>
        <div class="page-head__sub">These values are stored in the database and take effect immediately.</div>
    </div>
</div>

<div class="tabs">
    <?php $first = true; foreach ($groups as $key => [$label, $ic]): ?>
        <button class="tab <?= $first ? 'is-active' : '' ?>" data-tab="<?= e($key) ?>" data-tab-group="settings"><?= icon($ic) ?> <?= e($label) ?></button>
    <?php $first = false; endforeach; ?>
</div>

<!-- Branding -->
<div data-tab-panel="branding" data-tab-group="settings">
    <form method="post" action="<?= e(url('/admin/settings')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="group" value="branding">
        <div class="card">
            <div class="card__head"><?= icon('globe') ?><h2>Branding</h2></div>
            <div class="card__body">
                <div class="field">
                    <label class="label">Site name</label>
                    <input class="input" name="site_name" value="<?= e((string) $get('branding', 'site_name', 'S3 Lite')) ?>">
                </div>
                <div class="field">
                    <label class="label">Tagline</label>
                    <input class="input" name="site_tagline" value="<?= e((string) $get('branding', 'site_tagline', '')) ?>">
                </div>
                <div class="field" style="max-width:220px">
                    <label class="label">Accent colour</label>
                    <input class="input" name="accent_color" type="color" value="<?= e((string) $get('branding', 'accent_color', '#4f7cff')) ?>" style="height:40px; padding:4px">
                </div>
            </div>
            <div class="card__foot"><button class="btn btn-primary" type="submit"><?= icon('save') ?> Save branding</button></div>
        </div>
    </form>
</div>

<!-- General -->
<div data-tab-panel="general" data-tab-group="settings" class="hidden">
    <form method="post" action="<?= e(url('/admin/settings')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="group" value="general">
        <input type="hidden" name="__flags[]" value="allow_registration">
        <input type="hidden" name="__flags[]" value="require_email_verify">

        <div class="card">
            <div class="card__head"><?= icon('sliders') ?><h2>General</h2></div>
            <div class="card__body">
                <label class="check">
                    <input type="checkbox" name="allow_registration" value="1" <?= $get('general', 'allow_registration') ? 'checked' : '' ?>>
                    <span>
                        <span class="check__text">Allow self-registration</span>
                        <span class="check__desc">Anyone can create an account from the sign-in page.</span>
                    </span>
                </label>
                <label class="check">
                    <input type="checkbox" name="require_email_verify" value="1" <?= $get('general', 'require_email_verify') ? 'checked' : '' ?>>
                    <span>
                        <span class="check__text">New accounts need approval</span>
                        <span class="check__desc">Registrations start as “pending” until an administrator activates them.</span>
                    </span>
                </label>

                <hr>

                <div class="form-row">
                    <div class="field">
                        <label class="label">Default quota (bytes)</label>
                        <input class="input" name="default_quota" type="number" min="0" value="<?= e((string) $get('general', 'default_quota', 10737418240)) ?>">
                        <div class="hint">Currently <?= e(bytes((int) $get('general', 'default_quota', 10737418240))) ?>. Use 0 for unlimited.</div>
                    </div>
                    <div class="field">
                        <label class="label">Trash retention (days)</label>
                        <input class="input" name="trash_retention_days" type="number" min="0" value="<?= e((string) $get('general', 'trash_retention_days', 30)) ?>">
                    </div>
                    <div class="field">
                        <label class="label">Versions kept per file</label>
                        <input class="input" name="versions_kept" type="number" min="1" value="<?= e((string) $get('general', 'versions_kept', 10)) ?>">
                    </div>
                </div>

                <div class="field">
                    <label class="label">CORS allowed origins</label>
                    <input class="input" name="cors_origins" value="<?= e((string) $get('general', 'cors_origins', '*')) ?>">
                    <div class="hint">Comma-separated list, or <span class="code-inline">*</span> to allow any origin.</div>
                </div>
            </div>
            <div class="card__foot"><button class="btn btn-primary" type="submit"><?= icon('save') ?> Save general settings</button></div>
        </div>
    </form>
</div>

<!-- Uploads -->
<div data-tab-panel="uploads" data-tab-group="settings" class="hidden">
    <form method="post" action="<?= e(url('/admin/settings')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="group" value="uploads">
        <input type="hidden" name="__flags[]" value="allow_duplicates">
        <input type="hidden" name="__flags[]" value="virus_scan_enabled">

        <div class="card">
            <div class="card__head"><?= icon('upload') ?><h2>Uploads</h2></div>
            <div class="card__body">
                <div class="form-row">
                    <div class="field">
                        <label class="label">Maximum upload size (bytes)</label>
                        <input class="input" name="max_upload_size" type="number" min="0" value="<?= e((string) $get('uploads', 'max_upload_size', 5368709120)) ?>">
                        <div class="hint">Currently <?= e(bytes((int) $get('uploads', 'max_upload_size', 5368709120))) ?>. PHP's own limits still apply.</div>
                    </div>
                    <div class="field">
                        <label class="label">Chunk size (bytes)</label>
                        <input class="input" name="chunk_size" type="number" min="262144" value="<?= e((string) $get('uploads', 'chunk_size', 8388608)) ?>">
                        <div class="hint">Files above this size upload in resumable parts.</div>
                    </div>
                </div>

                <label class="check">
                    <input type="checkbox" name="allow_duplicates" value="1" <?= $get('uploads', 'allow_duplicates') ? 'checked' : '' ?>>
                    <span>
                        <span class="check__text">Allow duplicate files</span>
                        <span class="check__desc">When off, an upload matching an existing SHA-256 in the same folder returns the existing file.</span>
                    </span>
                </label>

                <label class="check">
                    <input type="checkbox" name="virus_scan_enabled" value="1" <?= $get('uploads', 'virus_scan_enabled') ? 'checked' : '' ?>>
                    <span>
                        <span class="check__text">Scan uploads for malware</span>
                        <span class="check__desc">Runs the command below on every upload. EICAR test files are always caught.</span>
                    </span>
                </label>

                <div class="field mt-2">
                    <label class="label">Scan command</label>
                    <input class="input mono" name="virus_scan_command" value="<?= e((string) $get('uploads', 'virus_scan_command', '')) ?>"
                           placeholder="clamscan --no-summary {file}">
                    <div class="hint"><span class="code-inline">{file}</span> is replaced with the path. Exit code 1 means infected.</div>
                </div>

                <div class="field">
                    <label class="label">Allowed MIME types</label>
                    <input class="input mono" name="allowed_mime_types" value="<?= e((string) $get('uploads', 'allowed_mime_types', '')) ?>"
                           placeholder="image/*, application/pdf">
                    <div class="hint">Leave empty to accept everything except the built-in executable blocklist.</div>
                </div>
            </div>
            <div class="card__foot"><button class="btn btn-primary" type="submit"><?= icon('save') ?> Save upload settings</button></div>
        </div>
    </form>
</div>

<!-- Security -->
<div data-tab-panel="security" data-tab-group="settings" class="hidden">
    <form method="post" action="<?= e(url('/admin/settings')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="group" value="security">
        <input type="hidden" name="__flags[]" value="force_2fa_admins">
        <input type="hidden" name="__flags[]" value="require_request_signing">

        <div class="card">
            <div class="card__head"><?= icon('shield') ?><h2>Security</h2></div>
            <div class="card__body">
                <div class="form-row">
                    <div class="field">
                        <label class="label">Max failed logins</label>
                        <input class="input" name="max_login_attempts" type="number" min="1" value="<?= e((string) $get('security', 'max_login_attempts', 5)) ?>">
                    </div>
                    <div class="field">
                        <label class="label">Lockout duration (minutes)</label>
                        <input class="input" name="lockout_minutes" type="number" min="1" value="<?= e((string) $get('security', 'lockout_minutes', 15)) ?>">
                    </div>
                    <div class="field">
                        <label class="label">Signed URL lifetime (seconds)</label>
                        <input class="input" name="signed_url_ttl" type="number" min="60" value="<?= e((string) $get('security', 'signed_url_ttl', 3600)) ?>">
                    </div>
                </div>

                <label class="check">
                    <input type="checkbox" name="force_2fa_admins" value="1" <?= $get('security', 'force_2fa_admins') ? 'checked' : '' ?>>
                    <span>
                        <span class="check__text">Require 2FA for administrators</span>
                        <span class="check__desc">Administrators are prompted to enable two-factor authentication.</span>
                    </span>
                </label>

                <label class="check">
                    <input type="checkbox" name="require_request_signing" value="1" <?= $get('security', 'require_request_signing') ? 'checked' : '' ?>>
                    <span>
                        <span class="check__text">Require signed API writes</span>
                        <span class="check__desc">
                            API-key clients must send X-Signature and X-Timestamp on every write.
                            Turn this on only once your clients implement it.
                        </span>
                    </span>
                </label>
            </div>
            <div class="card__foot"><button class="btn btn-primary" type="submit"><?= icon('save') ?> Save security settings</button></div>
        </div>
    </form>
</div>

<!-- Services -->
<div data-tab-panel="services" data-tab-group="settings" class="hidden">
    <form method="post" action="<?= e(url('/admin/settings')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="group" value="services">
        <input type="hidden" name="__flags[]" value="sftp_enabled">
        <input type="hidden" name="__flags[]" value="ftp_enabled">
        <input type="hidden" name="__flags[]" value="ftps_enabled">

        <div class="card">
            <div class="card__head"><?= icon('server') ?><h2>Transfer services</h2></div>
            <div class="card__body">
                <label class="check">
                    <input type="checkbox" name="sftp_enabled" value="1" <?= $get('services', 'sftp_enabled', true) ? 'checked' : '' ?>>
                    <span><span class="check__text">SFTP enabled</span><span class="check__desc">SSH file transfer.</span></span>
                </label>
                <label class="check">
                    <input type="checkbox" name="ftps_enabled" value="1" <?= $get('services', 'ftps_enabled') ? 'checked' : '' ?>>
                    <span><span class="check__text">FTPS enabled</span><span class="check__desc">FTP over explicit TLS.</span></span>
                </label>
                <label class="check">
                    <input type="checkbox" name="ftp_enabled" value="1" <?= $get('services', 'ftp_enabled') ? 'checked' : '' ?>>
                    <span><span class="check__text">Plain FTP enabled</span><span class="check__desc">Unencrypted — only enable on a trusted network.</span></span>
                </label>

                <hr>

                <div class="form-row">
                    <div class="field">
                        <label class="label">FTP control port</label>
                        <input class="input" name="ftp_port" type="number" value="<?= e((string) $get('services', 'ftp_port', 21)) ?>">
                    </div>
                    <div class="field">
                        <label class="label">Passive port range start</label>
                        <input class="input" name="ftp_passive_min" type="number" value="<?= e((string) $get('services', 'ftp_passive_min', 50000)) ?>">
                    </div>
                    <div class="field">
                        <label class="label">Passive port range end</label>
                        <input class="input" name="ftp_passive_max" type="number" value="<?= e((string) $get('services', 'ftp_passive_max', 50100)) ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label class="label">FTPS TLS certificate path</label>
                        <input class="input mono" name="ftps_tls_cert" value="<?= e((string) $get('services', 'ftps_tls_cert', '')) ?>">
                    </div>
                    <div class="field">
                        <label class="label">FTPS TLS key path</label>
                        <input class="input mono" name="ftps_tls_key" value="<?= e((string) $get('services', 'ftps_tls_key', '')) ?>">
                    </div>
                </div>
            </div>
            <div class="card__foot">
                <button class="btn btn-primary" type="submit"><?= icon('save') ?> Save service settings</button>
                <a class="btn" href="<?= e(url('/admin/sftp')) ?>"><?= icon('server') ?> Manage accounts</a>
            </div>
        </div>
    </form>
</div>
<?php
View::endSection();
