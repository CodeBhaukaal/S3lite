<?php

use App\Core\View;

View::extend('layouts.app');
View::share('title', 'Roles & permissions');

/** @var list<array> $roles */
/** @var array<string, list<array>> $permissions */
/** @var array<int, list<string>> $rolePermissions */

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('shield', 'icon icon-lg') ?> Roles &amp; permissions</h1>
        <div class="page-head__sub">Role-based access control. Administrators always hold every permission.</div>
    </div>
    <div class="page-head__actions">
        <button class="btn btn-primary" type="button" data-modal-open="modal-role"><?= icon('plus') ?> New role</button>
    </div>
</div>

<?php foreach ($roles as $role): ?>
    <?php $granted = $rolePermissions[(int) $role['id']] ?? []; ?>
    <div class="card">
        <div class="card__head">
            <?= icon($role['name'] === 'admin' ? 'shield-check' : 'shield') ?>
            <h2><?= e($role['label']) ?></h2>
            <span class="badge badge-accent mono"><?= e($role['name']) ?></span>
            <span class="badge"><?= icon('users') ?> <?= e((string) $role['user_count']) ?></span>
            <?php if ((int) $role['is_system'] === 1): ?>
                <span class="badge badge-info">system</span>
            <?php endif; ?>
        </div>
        <div class="card__body">
            <p class="muted small"><?= e((string) $role['description']) ?></p>

            <form method="post" action="<?= e(url('/admin/roles/' . $role['id'] . '/update')) ?>">
                <?= csrf_field() ?>

                <div class="field" style="max-width:320px">
                    <label class="label">Display label</label>
                    <input class="input" name="label" value="<?= e($role['label']) ?>">
                </div>

                <?php if ($role['name'] === 'admin'): ?>
                    <div class="alert alert-info">
                        <?= icon('info') ?>
                        <div class="alert__body">The administrator role always has every permission and cannot be restricted.</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($permissions as $group => $items): ?>
                        <div class="mb-3">
                            <div class="label"><?= e(ucfirst((string) $group)) ?></div>
                            <div class="grid grid-3" style="gap:0 16px">
                                <?php foreach ($items as $permission): ?>
                                    <label class="check">
                                        <input type="checkbox" name="permissions[]" value="<?= e($permission['name']) ?>"
                                            <?= in_array($permission['name'], $granted, true) ? 'checked' : '' ?>>
                                        <span>
                                            <span class="check__text"><?= e($permission['label']) ?></span>
                                            <span class="check__desc mono"><?= e($permission['name']) ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= icon('save') ?> Save role</button>
                    <?php if ((int) $role['is_system'] !== 1): ?>
                        <button class="btn btn-danger" type="submit" formaction="<?= e(url('/admin/roles/' . $role['id'] . '/delete')) ?>"
                                data-confirm="Delete this role?"><?= icon('trash') ?> Delete role</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<div class="modal-backdrop hidden" id="modal-role">
    <div class="modal modal--wide">
        <form method="post" action="<?= e(url('/admin/roles')) ?>">
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('shield') ?><h3>New role</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="form-row">
                    <div class="field">
                        <label class="label">Machine name <span class="req">*</span></label>
                        <input class="input mono" name="name" required pattern="[a-zA-Z0-9_\-]+" maxlength="64" placeholder="auditor">
                    </div>
                    <div class="field">
                        <label class="label">Display label <span class="req">*</span></label>
                        <input class="input" name="label" required maxlength="128" placeholder="Auditor">
                    </div>
                </div>
                <div class="field">
                    <label class="label">Description</label>
                    <input class="input" name="description" maxlength="255">
                </div>
                <?php foreach ($permissions as $group => $items): ?>
                    <div class="mb-3">
                        <div class="label"><?= e(ucfirst((string) $group)) ?></div>
                        <div class="grid grid-2" style="gap:0 16px">
                            <?php foreach ($items as $permission): ?>
                                <label class="check">
                                    <input type="checkbox" name="permissions[]" value="<?= e($permission['name']) ?>">
                                    <span class="check__text"><?= e($permission['label']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Create role</button>
            </div>
        </form>
    </div>
</div>
<?php
View::endSection();
