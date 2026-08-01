<?php
/** @var array $file */
/** @var list<array> $folderTree */
?>
<div class="dropdown">
    <button class="btn btn-sm btn-ghost btn-icon" type="button" data-dropdown aria-label="File actions"><?= icon('more') ?></button>
    <div class="dropdown__menu">
        <a class="dropdown__item" href="<?= e(url('/files/' . $file['uuid'])) ?>"><?= icon('eye') ?> Open</a>
        <a class="dropdown__item" href="<?= e(url('/files/' . $file['uuid'] . '/download')) ?>"><?= icon('download') ?> Download</a>

        <div class="dropdown__divider"></div>

        <button class="dropdown__item" type="button"
                data-modal-open="modal-rename"
                data-modal-action="<?= e(url('/files/' . $file['uuid'] . '/rename')) ?>"
                data-fill-name="<?= e($file['name']) ?>"><?= icon('edit') ?> Rename</button>

        <button class="dropdown__item" type="button"
                data-modal-open="modal-move"
                data-modal-action="<?= e(url('/files/' . $file['uuid'] . '/move')) ?>"><?= icon('move') ?> Move</button>

        <button class="dropdown__item" type="button"
                data-modal-open="modal-tags"
                data-modal-action="<?= e(url('/files/' . $file['uuid'] . '/tags')) ?>"
                data-fill-tags="<?= e(implode(', ', (array) ($file['tags'] ?? []))) ?>"><?= icon('tag') ?> Tags</button>

        <form method="post" action="<?= e(url('/files/' . $file['uuid'] . '/copy')) ?>">
            <?= csrf_field() ?>
            <button class="dropdown__item" type="submit"><?= icon('copy') ?> Duplicate</button>
        </form>

        <button class="dropdown__item" type="button"
                data-modal-open="modal-share"
                data-fill-file_id="<?= e($file['uuid']) ?>"
                data-fill-folder_id=""><?= icon('share') ?> Share link</button>

        <div class="dropdown__divider"></div>

        <form method="post" action="<?= e(url('/files/' . $file['uuid'] . '/trash')) ?>">
            <?= csrf_field() ?>
            <button class="dropdown__item is-danger" type="submit"
                    data-confirm="Move this file to trash?"><?= icon('trash') ?> Move to trash</button>
        </form>
    </div>
</div>
