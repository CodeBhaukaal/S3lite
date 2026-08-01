/* =========================================================================
   S3 Lite — panel behaviour. No framework, no external requests.
   ========================================================================= */
(function () {
    'use strict';

    const App = window.S3 = {
        config: window.S3_CONFIG || {},
    };

    // --- Helpers ---------------------------------------------------------

    const $ = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

    App.$ = $;
    App.$$ = $$;

    function csrf() {
        const meta = $('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }
    App.csrf = csrf;

    App.url = function (path) {
        const base = (App.config.baseUrl || '').replace(/\/$/, '');
        return base + '/' + String(path).replace(/^\//, '');
    };

    App.bytes = function (value) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let n = Number(value) || 0;
        let i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return (i === 0 ? n : n.toFixed(n < 10 ? 2 : 1)) + ' ' + units[i];
    };

    App.request = async function (url, options) {
        options = options || {};
        const headers = Object.assign({
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        }, options.headers || {});

        if (options.method && options.method !== 'GET' && !(options.body instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-Token'] = csrf();
            if (options.body && typeof options.body !== 'string') {
                options.body = JSON.stringify(options.body);
            }
        }

        if (options.body instanceof FormData) {
            headers['X-CSRF-Token'] = csrf();
        }

        const response = await fetch(url, Object.assign({}, options, { headers, credentials: 'same-origin' }));
        const text = await response.text();

        let payload = null;
        try { payload = text ? JSON.parse(text) : null; } catch (e) { payload = { raw: text }; }

        if (!response.ok) {
            const message = (payload && payload.error && payload.error.message) || ('Request failed (' + response.status + ')');
            const error = new Error(message);
            error.status = response.status;
            error.payload = payload;
            throw error;
        }

        return payload;
    };

    // --- Toasts ----------------------------------------------------------

    const icons = { success: 'check-circle', error: 'x-circle', warning: 'alert', info: 'info' };

    App.toast = function (message, type, timeout) {
        type = type || 'info';
        let host = $('.toasts');

        if (!host) {
            host = document.createElement('div');
            host.className = 'toasts';
            document.body.appendChild(host);
        }

        const el = document.createElement('div');
        el.className = 'toast toast--' + type;
        el.innerHTML =
            '<svg class="icon" aria-hidden="true"><use href="#i-' + (icons[type] || 'info') + '"></use></svg>' +
            '<div class="flex-1">' + escapeHtml(message) + '</div>' +
            '<button class="toast__close" type="button" aria-label="Dismiss">' +
            '<svg class="icon icon-sm" aria-hidden="true"><use href="#i-x"></use></svg></button>';

        host.appendChild(el);

        const remove = () => {
            el.classList.add('is-leaving');
            setTimeout(() => el.remove(), 200);
        };

        el.querySelector('.toast__close').addEventListener('click', remove);
        setTimeout(remove, timeout || 4500);
    };

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value == null ? '' : value);
        return div.innerHTML;
    }
    App.escapeHtml = escapeHtml;

    // --- Theme -----------------------------------------------------------

    App.theme = {
        get() {
            return localStorage.getItem('s3-theme') || 'dark';
        },
        set(value) {
            localStorage.setItem('s3-theme', value);
            document.documentElement.setAttribute('data-theme', value);
            $$('[data-theme-icon]').forEach((el) => {
                const use = el.querySelector('use');
                if (use) use.setAttribute('href', value === 'dark' ? '#i-sun' : '#i-moon');
            });
        },
        toggle() {
            this.set(this.get() === 'dark' ? 'light' : 'dark');
        },
    };

    document.documentElement.setAttribute('data-theme', App.theme.get());

    // --- Modals ----------------------------------------------------------

    App.openModal = function (id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('hidden');
        const focusable = modal.querySelector('input:not([type=hidden]), select, textarea, button');
        if (focusable) setTimeout(() => focusable.focus(), 40);
        document.body.style.overflow = 'hidden';
    };

    App.closeModal = function (id) {
        const modal = id ? document.getElementById(id) : null;
        if (modal) {
            modal.classList.add('hidden');
        } else {
            $$('.modal-backdrop').forEach((el) => el.classList.add('hidden'));
        }
        document.body.style.overflow = '';
    };

    // --- Clipboard -------------------------------------------------------

    App.copy = function (text, label) {
        const done = () => App.toast((label || 'Copied') + ' to clipboard', 'success', 2200);

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done).catch(() => fallback());
        } else {
            fallback();
        }

        function fallback() {
            const area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy'); done(); } catch (e) { App.toast('Copy failed', 'error'); }
            area.remove();
        }
    };

    // --- Charts (tiny inline SVG renderers, no dependencies) --------------

    App.charts = {
        line(canvasId, series, options) {
            const host = document.getElementById(canvasId);
            if (!host) return;

            options = options || {};
            const labels = options.labels || [];
            const width = host.clientWidth || 600;
            const height = options.height || host.clientHeight || 200;
            const padX = 34;
            const padY = 18;

            let max = 0;
            series.forEach((s) => s.values.forEach((v) => { if (v > max) max = v; }));
            max = max || 1;

            const count = Math.max(1, (series[0] ? series[0].values.length : 1) - 1);
            const stepX = (width - padX - 12) / count;
            const scaleY = (height - padY * 2) / max;

            let svg = '<svg viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="none" class="chart" style="height:' + height + 'px">';

            // Horizontal guides
            for (let g = 0; g <= 4; g++) {
                const y = padY + ((height - padY * 2) / 4) * g;
                svg += '<line x1="' + padX + '" y1="' + y + '" x2="' + (width - 6) + '" y2="' + y +
                    '" stroke="currentColor" stroke-opacity="0.09" stroke-width="1"/>';
                const value = Math.round(max - (max / 4) * g);
                svg += '<text x="4" y="' + (y + 3.5) + '" font-size="9" fill="currentColor" fill-opacity="0.4">' +
                    (options.format === 'bytes' ? App.bytes(value) : value) + '</text>';
            }

            series.forEach((s) => {
                const points = s.values.map((v, i) => {
                    const x = padX + stepX * i;
                    const y = height - padY - v * scaleY;
                    return [x, y];
                });

                if (!points.length) return;

                const path = points.map((p, i) => (i === 0 ? 'M' : 'L') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
                const area = path + ' L' + points[points.length - 1][0].toFixed(1) + ' ' + (height - padY) +
                    ' L' + points[0][0].toFixed(1) + ' ' + (height - padY) + ' Z';

                svg += '<path d="' + area + '" fill="' + s.color + '" fill-opacity="0.12"/>';
                svg += '<path d="' + path + '" fill="none" stroke="' + s.color + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';

                points.forEach((p, i) => {
                    const title = (labels[i] || '') + ': ' +
                        (options.format === 'bytes' ? App.bytes(s.values[i]) : s.values[i]) + ' ' + s.label;
                    svg += '<circle cx="' + p[0].toFixed(1) + '" cy="' + p[1].toFixed(1) + '" r="2.6" fill="' + s.color + '">' +
                        '<title>' + escapeHtml(title) + '</title></circle>';
                });
            });

            // X labels — thin them out so they never overlap.
            const every = Math.ceil(labels.length / 8) || 1;
            labels.forEach((label, i) => {
                if (i % every !== 0) return;
                const x = padX + stepX * i;
                svg += '<text x="' + x + '" y="' + (height - 4) + '" font-size="9" text-anchor="middle" fill="currentColor" fill-opacity="0.42">' +
                    escapeHtml(label) + '</text>';
            });

            svg += '</svg>';
            host.innerHTML = svg;
        },

        ring(hostId, percent, color) {
            const host = document.getElementById(hostId);
            if (!host) return;

            const r = 32;
            const c = 2 * Math.PI * r;
            const offset = c - (Math.min(100, Math.max(0, percent)) / 100) * c;

            host.innerHTML =
                '<svg width="78" height="78" viewBox="0 0 78 78">' +
                '<circle cx="39" cy="39" r="' + r + '" fill="none" stroke="currentColor" stroke-opacity="0.12" stroke-width="7"/>' +
                '<circle cx="39" cy="39" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="7" stroke-linecap="round" ' +
                'stroke-dasharray="' + c.toFixed(1) + '" stroke-dashoffset="' + offset.toFixed(1) + '"/>' +
                '</svg>' +
                '<div class="gauge__value">' + Math.round(percent) + '%</div>';
        },
    };

    // --- Mobile sidebar ---------------------------------------------------
    //
    // Below the breakpoint the sidebar is a drawer over the content, so it needs
    // a scrim and must be dismissable by clicking away from it.

    App.sidebar = {
        element() {
            return $('.sidebar');
        },

        isOpen() {
            const sidebar = this.element();

            return sidebar !== null && sidebar.classList.contains('is-open');
        },

        open() {
            const sidebar = this.element();
            if (sidebar === null || this.isOpen()) return;

            sidebar.classList.add('is-open');

            if (!$('.sidebar-scrim')) {
                const scrim = document.createElement('div');
                scrim.className = 'sidebar-scrim';
                scrim.setAttribute('aria-hidden', 'true');
                document.body.appendChild(scrim);
            }

            document.body.classList.add('sidebar-open');
        },

        close() {
            const sidebar = this.element();
            if (sidebar !== null) sidebar.classList.remove('is-open');

            const scrim = $('.sidebar-scrim');
            if (scrim) scrim.remove();

            document.body.classList.remove('sidebar-open');
        },

        toggle() {
            this.isOpen() ? this.close() : this.open();
        },
    };

    // --- Delegated behaviour ---------------------------------------------

    // --- Dropdown positioning -------------------------------------------
    //
    // Menus sit inside file cards, scrolling tables and fading toolbars, any of
    // which would clip or hide them (`overflow`, `opacity`, `transform`). While
    // open, a menu is moved to <body> and positioned against its trigger,
    // flipping above it when there is no room below. Every style needed is set
    // inline, so the menu behaves correctly even if the stylesheet is stale.

    let openMenu = null;

    function placeMenu(dropdown) {
        const menu = $('.dropdown__menu', dropdown);
        const trigger = $('[data-dropdown]', dropdown);
        if (!menu || !trigger) return;

        // Remember where the menu came from, and any author styles it carries
        // (the notifications menu sets its own min-width), so both can be
        // restored on close.
        menu._origin = {
            parent: menu.parentNode,
            next: menu.nextSibling,
            style: menu.getAttribute('style'),
        };

        menu.classList.add('is-floating');
        menu.style.position = 'fixed';
        menu.style.display = 'block';
        menu.style.opacity = '1';
        menu.style.zIndex = '120';
        menu.style.right = 'auto';
        menu.style.bottom = 'auto';
        menu.style.top = '0px';
        menu.style.left = '0px';

        document.body.appendChild(menu);
        openMenu = menu;

        const anchor = trigger.getBoundingClientRect();
        const width = menu.offsetWidth;
        const height = menu.offsetHeight;
        const margin = 8;

        let top = anchor.bottom + 6;
        if (top + height > window.innerHeight - margin) {
            const above = anchor.top - height - 6;
            top = above >= margin ? above : Math.max(margin, window.innerHeight - height - margin);
        }

        let left = menu.classList.contains('dropdown__menu--left') ? anchor.left : anchor.right - width;
        left = Math.min(Math.max(margin, left), Math.max(margin, window.innerWidth - width - margin));

        menu.style.top = top + 'px';
        menu.style.left = left + 'px';
    }

    function restoreMenu(menu) {
        if (!menu || !menu._origin) return;

        const origin = menu._origin;
        origin.parent.insertBefore(menu, origin.next);
        menu._origin = null;

        menu.classList.remove('is-floating');

        if (origin.style === null) {
            menu.removeAttribute('style');
        } else {
            menu.setAttribute('style', origin.style);
        }
    }

    function closeDropdowns() {
        if (openMenu) {
            restoreMenu(openMenu);
            openMenu = null;
        }

        $$('.dropdown.is-open').forEach((dropdown) => dropdown.classList.remove('is-open'));

        // Release the hover-only action button on file cards.
        $$('.file-card__actions.is-forced').forEach((el) => el.classList.remove('is-forced'));
    }

    App.closeDropdowns = closeDropdowns;

    document.addEventListener('click', function (event) {
        // Dropdowns
        const trigger = event.target.closest('[data-dropdown]');
        if (trigger) {
            const parent = trigger.closest('.dropdown');
            const wasOpen = parent.classList.contains('is-open');
            closeDropdowns();

            if (!wasOpen) {
                parent.classList.add('is-open');

                // Keep the trigger visible while its menu is open.
                const actions = parent.closest('.file-card__actions');
                if (actions) actions.classList.add('is-forced');

                placeMenu(parent);
            }

            event.preventDefault();
            return;
        }

        if (!event.target.closest('.dropdown__menu')) {
            closeDropdowns();
        }

        // Modals
        const open = event.target.closest('[data-modal-open]');
        if (open) {
            event.preventDefault();

            // The trigger often lives in a dropdown; put that away first.
            closeDropdowns();

            const id = open.getAttribute('data-modal-open');
            App.openModal(id);

            // Prefill fields from data-fill-* attributes.
            const modal = document.getElementById(id);
            if (modal) {
                Array.from(open.attributes).forEach((attr) => {
                    if (!attr.name.startsWith('data-fill-')) return;
                    const field = modal.querySelector('[name="' + attr.name.slice(10) + '"], [data-fill-target="' + attr.name.slice(10) + '"]');
                    if (!field) return;
                    if (field.tagName === 'FORM') return;
                    if ('value' in field) field.value = attr.value;
                    else field.textContent = attr.value;
                });

                const action = open.getAttribute('data-modal-action');
                if (action) {
                    const form = modal.querySelector('form');
                    if (form) form.setAttribute('action', action);
                }

                const title = open.getAttribute('data-modal-title');
                if (title) {
                    const heading = modal.querySelector('[data-modal-heading]');
                    if (heading) heading.textContent = title;
                }
            }
            return;
        }

        if (event.target.closest('[data-modal-close]')) {
            event.preventDefault();
            const backdrop = event.target.closest('.modal-backdrop');
            App.closeModal(backdrop ? backdrop.id : null);
            return;
        }

        if (event.target.classList && event.target.classList.contains('modal-backdrop')) {
            App.closeModal(event.target.id);
            return;
        }

        // Copy buttons
        const copyBtn = event.target.closest('[data-copy]');
        if (copyBtn) {
            event.preventDefault();
            const value = copyBtn.getAttribute('data-copy');
            const target = value.startsWith('#') ? $(value) : null;
            App.copy(target ? target.value || target.textContent : value, copyBtn.getAttribute('data-copy-label'));
            return;
        }

        // Theme toggle
        if (event.target.closest('[data-theme-toggle]')) {
            event.preventDefault();
            App.theme.toggle();
            return;
        }

        // Sidebar (mobile)
        if (event.target.closest('[data-sidebar-toggle]')) {
            event.preventDefault();
            App.sidebar.toggle();
            return;
        }

        if (event.target.closest('.sidebar-scrim')) {
            App.sidebar.close();
            return;
        }

        if (App.sidebar.isOpen()) {
            // Anything outside the drawer dismisses it, and so does picking a
            // destination inside it.
            if (!event.target.closest('.sidebar') || event.target.closest('.nav-item')) {
                App.sidebar.close();
            }
        }

        // Confirm-and-submit forms
        const confirmBtn = event.target.closest('[data-confirm]');
        if (confirmBtn) {
            if (!window.confirm(confirmBtn.getAttribute('data-confirm'))) {
                event.preventDefault();
                event.stopPropagation();
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            App.closeModal();
            closeDropdowns();
            App.sidebar.close();
        }

        // "/" focuses search unless already typing.
        if (event.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) {
            const search = $('[data-search-input]');
            if (search) {
                event.preventDefault();
                search.focus();
            }
        }
    });

    // Auto-submit filter forms on change.
    document.addEventListener('change', function (event) {
        const el = event.target.closest('[data-auto-submit]');
        if (el && el.form) el.form.submit();
    });

    // Tabs
    document.addEventListener('click', function (event) {
        const tab = event.target.closest('[data-tab]');
        if (!tab) return;

        event.preventDefault();
        const group = tab.getAttribute('data-tab-group') || 'default';
        const name = tab.getAttribute('data-tab');

        $$('[data-tab][data-tab-group="' + group + '"]').forEach((t) => t.classList.toggle('is-active', t === tab));
        $$('[data-tab-panel][data-tab-group="' + group + '"]').forEach((p) => {
            p.classList.toggle('hidden', p.getAttribute('data-tab-panel') !== name);
        });
    });

    // Selection checkboxes on the file grid.
    App.selection = {
        ids() {
            return $$('[data-select-item]:checked').map((el) => el.value);
        },
        sync() {
            const ids = this.ids();
            const bar = $('[data-bulk-bar]');
            if (bar) bar.classList.toggle('hidden', ids.length === 0);
            const counter = $('[data-bulk-count]');
            if (counter) counter.textContent = String(ids.length);
            $$('[data-select-item]').forEach((el) => {
                const card = el.closest('.file-card');
                if (card) card.classList.toggle('is-selected', el.checked);
            });
        },
    };

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-select-item]')) App.selection.sync();

        if (event.target.matches('[data-select-all]')) {
            const checked = event.target.checked;
            $$('[data-select-item]').forEach((el) => { el.checked = checked; });
            App.selection.sync();
        }
    });

    document.addEventListener('submit', function (event) {
        const form = event.target.closest('[data-bulk-form]');
        if (!form) return;

        const ids = App.selection.ids();
        if (!ids.length) {
            event.preventDefault();
            App.toast('Select at least one file first.', 'warning');
            return;
        }

        form.querySelectorAll('input[name="ids[]"]').forEach((el) => el.remove());
        ids.forEach((id) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = id;
            form.appendChild(input);
        });
    });

    // --- Responsive tables -----------------------------------------------
    //
    // Below the phone breakpoint every table collapses into stacked cards. Each
    // cell needs its column heading as a label; copying it here keeps the
    // markup in the templates clean and covers new tables automatically.

    App.labelTableCells = function (root) {
        $$('table.table', root || document).forEach((table) => {
            const headings = $$('thead th', table).map((th) => th.textContent.trim());

            if (headings.length === 0) return;

            $$('tbody tr', table).forEach((row) => {
                Array.from(row.children).forEach((cell, index) => {
                    // Spanning cells (sub-rows) and pre-labelled ones are left alone.
                    if (cell.hasAttribute('data-label') || cell.colSpan > 1) return;

                    const heading = headings[index];
                    if (heading) cell.setAttribute('data-label', heading);
                });
            });
        });
    };

    // Auto-dismiss server-rendered alerts after a while.
    document.addEventListener('DOMContentLoaded', function () {
        App.theme.set(App.theme.get());
        App.labelTableCells();

        $$('[data-autofocus]').forEach((el, i) => { if (i === 0) el.focus(); });

        $$('[data-chart]').forEach((el) => {
            const payload = JSON.parse(el.getAttribute('data-chart'));
            App.charts.line(el.id, payload.series, payload.options || {});
        });

        $$('[data-ring]').forEach((el) => {
            const payload = JSON.parse(el.getAttribute('data-ring'));
            App.charts.ring(el.id, payload.percent, payload.color);
        });
    });

    window.addEventListener('resize', debounce(function () {
        $$('[data-chart]').forEach((el) => {
            const payload = JSON.parse(el.getAttribute('data-chart'));
            App.charts.line(el.id, payload.series, payload.options || {});
        });
    }, 220));

    // A floating menu is positioned against a point on screen, so it has to go
    // away as soon as that point moves.
    window.addEventListener('resize', closeDropdowns);
    window.addEventListener('scroll', closeDropdowns, true);

    // Back on a wide viewport the sidebar is permanent again; drop the drawer
    // state so the scrim and the scroll lock do not linger.
    window.addEventListener('resize', function () {
        if (window.innerWidth > 900) App.sidebar.close();
    });

    function debounce(fn, wait) {
        let timer;
        return function () {
            clearTimeout(timer);
            timer = setTimeout(fn, wait);
        };
    }
    App.debounce = debounce;
})();
