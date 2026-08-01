<div class="modal-backdrop hidden" id="modal-share">
    <div class="modal">
        <form method="post" action="<?= e(url('/shares')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="file_id" value="">
            <input type="hidden" name="folder_id" value="">

            <div class="modal__head"><?= icon('share') ?><h3>Create share link</h3>
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-modal-close><?= icon('x') ?></button>
            </div>

            <div class="modal__body">
                <div class="field">
                    <label class="label">Link type</label>
                    <select class="select" name="type">
                        <option value="permanent">Permanent — never expires</option>
                        <option value="temporary">Temporary — expires on a date</option>
                    </select>
                </div>

                <div class="field">
                    <label class="label">Expires at <span class="faint">(optional)</span></label>
                    <input class="input" type="datetime-local" name="expires_at">
                    <div class="hint">Leave empty for a permanent link.</div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label class="label">Password <span class="faint">(optional)</span></label>
                        <input class="input" type="text" name="password" autocomplete="off" placeholder="Not protected">
                    </div>
                    <div class="field">
                        <label class="label">Download limit <span class="faint">(optional)</span></label>
                        <input class="input" type="number" name="max_downloads" min="1" placeholder="Unlimited">
                    </div>
                </div>

                <label class="check">
                    <input type="checkbox" name="allow_preview" value="1" checked>
                    <span>
                        <span class="check__text">Allow in-browser preview</span>
                        <span class="check__desc">Turn off to force a download instead of showing the file.</span>
                    </span>
                </label>
            </div>

            <div class="modal__foot">
                <button class="btn" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><?= icon('link') ?> Create link</button>
            </div>
        </form>
    </div>
</div>
