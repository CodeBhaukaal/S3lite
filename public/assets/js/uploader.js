/* =========================================================================
   Drag-and-drop uploader with automatic chunking for large files.
   Small files go through the plain form endpoint; anything above the
   configured threshold uses the resumable multipart API.
   ========================================================================= */
(function () {
    'use strict';

    const App = window.S3 || (window.S3 = {});

    function Uploader(options) {
        this.dropzone = options.dropzone;
        this.input = options.input;
        this.list = options.list;
        this.folderId = options.folderId || '';
        this.chunkSize = options.chunkSize || 8 * 1024 * 1024;
        this.maxSize = options.maxSize || 0;
        this.simpleUrl = options.simpleUrl;
        this.apiBase = options.apiBase;
        this.onComplete = options.onComplete || function () {};
        this.queue = [];
        this.active = false;

        this.bind();
    }

    Uploader.prototype.bind = function () {
        const self = this;

        if (this.dropzone) {
            ['dragenter', 'dragover'].forEach((type) => {
                self.dropzone.addEventListener(type, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    self.dropzone.classList.add('is-dragging');
                });
            });

            ['dragleave', 'drop'].forEach((type) => {
                self.dropzone.addEventListener(type, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (type === 'dragleave' && self.dropzone.contains(e.relatedTarget)) return;
                    self.dropzone.classList.remove('is-dragging');
                });
            });

            self.dropzone.addEventListener('drop', (e) => {
                if (e.dataTransfer && e.dataTransfer.files.length) self.add(e.dataTransfer.files);
            });

            self.dropzone.addEventListener('click', () => self.input && self.input.click());
        }

        if (this.input) {
            this.input.addEventListener('change', () => {
                if (self.input.files.length) self.add(self.input.files);
                self.input.value = '';
            });
        }

        // Also accept files dropped anywhere on the page.
        window.addEventListener('dragover', (e) => e.preventDefault());
        window.addEventListener('drop', (e) => {
            if (e.target.closest('.dropzone')) return;
            e.preventDefault();
            if (e.dataTransfer && e.dataTransfer.files.length) self.add(e.dataTransfer.files);
        });
    };

    Uploader.prototype.add = function (files) {
        Array.from(files).forEach((file) => {
            if (this.maxSize > 0 && file.size > this.maxSize) {
                App.toast(file.name + ' exceeds the maximum upload size.', 'error');
                return;
            }
            this.queue.push({ file: file, row: this.renderRow(file) });
        });

        this.process();
    };

    Uploader.prototype.renderRow = function (file) {
        if (!this.list) return null;

        const row = document.createElement('div');
        row.className = 'upload-item';
        row.innerHTML =
            '<svg class="icon" aria-hidden="true"><use href="#i-file"></use></svg>' +
            '<div class="upload-item__body">' +
            '<div class="upload-item__name"></div>' +
            '<div class="progress mt-1"><div class="progress__bar" style="width:0%"></div></div>' +
            '<div class="upload-item__meta"></div>' +
            '</div>' +
            '<div class="upload-item__status">Queued</div>';

        row.querySelector('.upload-item__name').textContent = file.name;
        row.querySelector('.upload-item__meta').textContent = App.bytes(file.size);
        this.list.appendChild(row);

        return row;
    };

    Uploader.prototype.update = function (row, percent, status, state) {
        if (!row) return;
        const bar = row.querySelector('.progress__bar');
        if (bar) bar.style.width = Math.min(100, percent) + '%';
        const label = row.querySelector('.upload-item__status');
        if (label) label.textContent = status;
        if (state) row.classList.add('is-' + state);
    };

    Uploader.prototype.process = async function () {
        if (this.active) return;

        this.active = true;

        while (this.queue.length) {
            const item = this.queue.shift();

            try {
                if (item.file.size > this.chunkSize) {
                    await this.uploadChunked(item);
                } else {
                    await this.uploadSimple(item);
                }
                this.update(item.row, 100, 'Done', 'done');
            } catch (error) {
                this.update(item.row, 100, error.message || 'Failed', 'error');
                App.toast(item.file.name + ': ' + (error.message || 'Upload failed'), 'error', 7000);
            }
        }

        this.active = false;
        this.onComplete();
    };

    Uploader.prototype.uploadSimple = function (item) {
        const self = this;

        return new Promise(function (resolve, reject) {
            const form = new FormData();
            form.append('file', item.file);
            form.append('folder_id', self.folderId);
            form.append('_token', App.csrf());

            const xhr = new XMLHttpRequest();
            xhr.open('POST', self.simpleUrl);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-Token', App.csrf());

            xhr.upload.addEventListener('progress', function (e) {
                if (e.lengthComputable) {
                    self.update(item.row, (e.loaded / e.total) * 100, Math.round((e.loaded / e.total) * 100) + '%');
                }
            });

            xhr.addEventListener('load', function () {
                let payload = null;
                try { payload = JSON.parse(xhr.responseText); } catch (e) { payload = null; }

                if (xhr.status >= 200 && xhr.status < 300 && payload && payload.success) {
                    const errors = (payload.data && payload.data.errors) || [];
                    if (errors.length) {
                        reject(new Error(errors[0].message || 'Rejected'));
                        return;
                    }
                    resolve(payload);
                } else {
                    const message = payload && payload.error ? payload.error.message
                        : (payload && payload.data && payload.data.errors && payload.data.errors[0]
                            ? payload.data.errors[0].message : 'Upload failed (' + xhr.status + ')');
                    reject(new Error(message));
                }
            });

            xhr.addEventListener('error', () => reject(new Error('Network error')));
            xhr.addEventListener('abort', () => reject(new Error('Aborted')));

            xhr.send(form);
        });
    };

    Uploader.prototype.uploadChunked = async function (item) {
        const self = this;
        const file = item.file;

        this.update(item.row, 0, 'Preparing');

        const init = await App.request(this.apiBase + '/files/multipart/init', {
            method: 'POST',
            body: {
                filename: file.name,
                total_size: file.size,
                mime: file.type || 'application/octet-stream',
                folder_id: this.folderId,
                part_size: this.chunkSize,
            },
        });

        const uploadId = init.data.upload_id;
        const totalParts = init.data.total_parts;
        const received = new Set(init.data.received_parts || []);

        for (let part = 1; part <= totalParts; part++) {
            if (received.has(part)) continue;

            const start = (part - 1) * this.chunkSize;
            const blob = file.slice(start, Math.min(start + this.chunkSize, file.size));
            const form = new FormData();
            form.append('part', blob, file.name + '.part' + part);
            form.append('part_number', String(part));

            await App.request(this.apiBase + '/files/multipart/' + uploadId + '/part', {
                method: 'POST',
                body: form,
            });

            self.update(item.row, (part / totalParts) * 100, 'Part ' + part + '/' + totalParts);
        }

        this.update(item.row, 100, 'Assembling');

        await App.request(this.apiBase + '/files/multipart/' + uploadId + '/complete', {
            method: 'POST',
            body: {},
        });
    };

    App.Uploader = Uploader;
})();
