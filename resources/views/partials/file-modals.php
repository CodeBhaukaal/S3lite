<?php
/** @var list<array> $folderTree */
?>
<div class="modal-backdrop hidden" id="modal-rename">
    <div class="modal">
        <form method="post" action="">
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('edit') ?><h3>Rename file</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="field">
                    <label class="label">New name</label>
                    <input class="input" name="name" required maxlength="255">
                    <div class="hint">Keep the file extension so previews keep working.</div>
                </div>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Rename</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop hidden" id="modal-move">
    <div class="modal">
        <form method="post" action="">
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('move') ?><h3>Move file</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="field">
                    <label class="label">Destination folder</label>
                    <select class="select" name="folder_id">
                        <option value="root">Home</option>
                        <?php foreach ($folderTree as $node): ?>
                            <option value="<?= e((string) $node['id']) ?>"><?= e(str_repeat('— ', $node['depth']) . $node['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Move</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop hidden" id="modal-tags">
    <div class="modal">
        <form method="post" action="">
            <?= csrf_field() ?>
            <div class="modal__head"><?= icon('tag') ?><h3>Tags</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>
            <div class="modal__body">
                <div class="field">
                    <label class="label">Comma-separated tags</label>
                    <input class="input" name="tags" placeholder="invoices, 2026, archived">
                </div>
            </div>
            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Save</button>
            </div>
        </form>
    </div>
</div>
