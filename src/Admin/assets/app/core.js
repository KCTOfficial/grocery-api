/*!
 * Grocery Restable admin - core: helpers, HTTP client and dialogs.
 * Every dynamic value inserted into HTML goes through esc().
 */
(function (GR, $) {
    'use strict';

    const CFG = JSON.parse(document.body.dataset.gr || '{}');

    // ---------------------------------------------------------------- html helpers

    const ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' };
    const esc = (value) => String(value ?? '').replace(/[&<>"'`]/g, (c) => ESC_MAP[c]);
    const icon = (name, extra = '') => `<i class="fa-solid fa-${esc(name)} ${esc(extra)}"></i>`;
    const badge = (text, cls = 'secondary') => `<span class="badge text-bg-${esc(cls)}">${esc(text)}</span>`;
    const sourceBadge = (source) => `<span class="badge gr-badge-${esc(source)}">${esc(source)}</span>`;
    // Code blocks are highlighted by highlight.js (see admin.js); the text is always escaped.
    const codeBlock = (text, lang = 'plaintext', extra = '') => `<pre class="gr-code ${esc(extra)}"><code class="language-${esc(lang)}">${esc(text)}</code></pre>`;
    const yesNo = (v) => (v ? `<span class="text-success">${icon('check')}</span>` : `<span class="text-muted">${icon('minus')}</span>`);
    const loading = (text = 'Loading…') => `<div class="text-center text-muted py-5">${icon('spinner', 'fa-spin')} ${esc(text)}</div>`;
    const empty = (html) => `<div class="gr-empty">${html}</div>`;

    const panel = (title, body, actions = '', footer = '') => `<div class="gr-panel"><div class="gr-panel-title">${title}<div class="gr-actions">${actions}</div></div>
        <div class="gr-panel-body">${body}</div>${footer ? `<div class="gr-panel-footer">${footer}</div>` : ''}</div>`;

    /** Hash link from path segments: link('data', 'default', 'users') → #/data/default/users */
    const link = (...parts) => '#/' + parts.map((p) => encodeURIComponent(p)).join('/');
    const withQuery = (href, query) => href + '?' + new URLSearchParams(query).toString();

    function cell(value) {
        if (value === null || value === undefined) return '<span class="gr-null">NULL</span>';
        if (typeof value === 'boolean') return value ? 'true' : 'false';
        if (typeof value === 'object') return `<code>${esc(JSON.stringify(value))}</code>`;
        return esc(value);
    }

    // ---------------------------------------------------------------- value helpers

    const isEmpty = (v) => v === null || v === undefined || v === '';
    const uniq = (list) => [...new Set(list)];
    const sameSet = (a, b) => a.length === b.length && a.every((x) => b.includes(x));
    const humanize = (name) => String(name || '').replace(/[_-]+/g, ' ').replace(/^./, (c) => c.toUpperCase());
    const absoluteApiUrl = () => (/^https?:/i.test(CFG.apiUrl) ? CFG.apiUrl : window.location.origin + CFG.apiUrl);

    function formatBytes(bytes) {
        if (bytes === null || bytes === undefined) return '';
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0;
        let n = Number(bytes);
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
    }

    function formValues($form) {
        const values = {};
        $form.find('[name]').each(function () {
            const $el = $(this);
            const name = $el.attr('name');
            if ($el.is('[type=checkbox]')) values[name] = $el.is(':checked');
            else if ($el.is('[type=radio]')) { if ($el.is(':checked')) values[name] = $el.val(); }
            else values[name] = $el.val();
        });
        return values;
    }

    // ---------------------------------------------------------------- errors

    function errorMessage(error) {
        if (!error) return 'Unknown error';
        if (typeof error === 'string') return error;
        let message = error.message || 'Request failed';
        const details = error.details || {};
        if (details.fields) message += '<ul class="mb-0 mt-1">' + Object.entries(details.fields).map(([f, m]) => `<li><b>${esc(f)}</b>: ${esc(m)}</li>`).join('') + '</ul>';
        if (details.problems) message += '<ul class="mb-0 mt-1">' + details.problems.map((p) => `<li>${esc(p)}</li>`).join('') + '</ul>';
        return message;
    }

    function parseError(xhr) {
        const json = xhr.responseJSON || (() => { try { return JSON.parse(xhr.responseText); } catch (e) { return null; } })();
        const err = (json && json.error) || { message: xhr.statusText || 'Network error' };
        return { status: xhr.status, code: err.code, message: esc(err.message), details: err.details || {} };
    }

    // ---------------------------------------------------------------- http

    function post(url, options) {
        return new Promise((resolve, reject) => {
            $.ajax({ url, method: 'POST', headers: { 'X-CSRF-Token': CFG.csrf }, dataType: 'json', ...options })
                .done(resolve)
                .fail((xhr) => {
                    const err = parseError(xhr);
                    if (err.status === 401 && GR.page === 'app') { window.location.href = CFG.adminUrl + '/login'; return; }
                    reject(err);
                });
        });
    }

    /** POST an action to {admin}/xhr and resolve with its "data" member. */
    const api = (action, data = {}) => post(CFG.adminUrl + '/xhr', { contentType: 'application/json', data: JSON.stringify({ action, ...data }) }).then((r) => r.data);

    /** Multipart variant of api() for file uploads. */
    function upload(action, formData) {
        formData.append('action', action);
        return post(CFG.adminUrl + '/xhr', { data: formData, processData: false, contentType: false }).then((r) => r.data);
    }

    /** Setup wizard endpoint (resolves with the whole body). */
    const setup = (action, data = {}) => post(CFG.adminUrl + '/setup', { contentType: 'application/json', data: JSON.stringify({ action, ...data }) });

    // ---------------------------------------------------------------- feedback

    function toast(message, type = 'success', html = false) {
        const iconName = { success: 'circle-check', danger: 'circle-exclamation', warning: 'triangle-exclamation', info: 'circle-info' }[type] || 'circle-info';
        const $t = $(`<div class="toast align-items-center text-bg-${esc(type)} border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex"><div class="toast-body">${icon(iconName, 'me-1')} <span class="msg"></span></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>`);
        if (html) $t.find('.msg').html(message); else $t.find('.msg').text(message);
        $('#gr-toasts').append($t);
        const t = new bootstrap.Toast($t[0], { delay: type === 'danger' ? 8000 : 3500 });
        $t.on('hidden.bs.toast', () => $t.remove());
        t.show();
    }

    const fail = (error) => toast(errorMessage(error), 'danger', true);

    function copy(text) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => toast('Copied to clipboard'));
            return;
        }
        const $t = $('<textarea class="position-fixed opacity-0"></textarea>').val(text).appendTo('body');
        $t[0].select();
        document.execCommand('copy');
        $t.remove();
        toast('Copied to clipboard');
    }

    function download(filename, content, type = 'application/json') {
        const url = URL.createObjectURL(new Blob([content], { type }));
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    // ---------------------------------------------------------------- dialogs

    const OVERLAYS = {
        modal: { el: '#gr-modal', Ctor: () => bootstrap.Modal, title: '.modal-title', body: '.modal-body', footer: '.modal-footer', dismiss: 'modal' },
        drawer: { el: '#gr-drawer', Ctor: () => bootstrap.Offcanvas, title: '.offcanvas-title', body: '.offcanvas-body', footer: '.gr-drawer-footer', dismiss: 'offcanvas' },
    };

    /**
     * Fill and show the shared modal or side drawer.
     * buttons: [{ label, cls, save, start, action: ($el) => Promise|false|void }] - returning false keeps it open.
     */
    function overlay(kind, { title, body, buttons = [], size = '' }) {
        const o = OVERLAYS[kind];
        const $el = $(o.el);
        if (kind === 'modal') $el.find('.modal-dialog').attr('class', 'modal-dialog modal-dialog-scrollable ' + (size ? 'modal-' + size : ''));
        else $el.toggleClass('gr-drawer-lg', size === 'lg');
        $el.find(o.title).html(title);
        $el.find(o.body).off().html(body);

        const instance = o.Ctor().getOrCreateInstance($el[0]);
        const $footer = $el.find(o.footer).empty();
        $footer.append(`<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="${o.dismiss}">Close</button>`);
        buttons.forEach((b) => {
            const $b = $(`<button type="button" class="btn btn-sm ${esc(b.cls || 'btn-primary')} ${b.start ? 'me-auto order-first' : ''}" ${b.save ? 'data-save' : ''}>${b.label}</button>`);
            $b.on('click', async () => {
                $b.prop('disabled', true);
                try {
                    if (await b.action($el) !== false) instance.hide();
                } catch (e) {
                    fail(e);
                } finally {
                    $b.prop('disabled', false);
                }
            });
            $footer.append($b);
        });
        // Bootstrap ignores show() while the dialog is still closing: wait until it is hidden.
        if (kind === 'modal' && $el.hasClass('show')) {
            $el.one('hidden.bs.modal', () => instance.show());
            instance.hide();
        } else {
            instance.show();
        }
        return $el;
    }

    const modal = (options) => overlay('modal', options);
    const drawer = (options) => overlay('drawer', options);

    function closeOverlays() {
        Object.values(OVERLAYS).forEach((o) => {
            const instance = o.Ctor().getInstance(document.querySelector(o.el));
            if (instance) instance.hide();
        });
    }

    function confirmDialog(message, label = 'Confirm', cls = 'btn-danger') {
        return new Promise((resolve) => {
            let confirmed = false;
            const $m = modal({
                title: `${icon('triangle-exclamation', 'text-warning me-1')} Please confirm`,
                body: `<p class="mb-0">${message}</p>`,
                buttons: [{ label: esc(label), cls, action: () => { confirmed = true; } }],
            });
            $m.one('hidden.bs.modal', () => resolve(confirmed));
        });
    }

    GR.CFG = CFG;
    GR.page = document.body.dataset.page;
    GR.html = { esc, icon, badge, sourceBadge, codeBlock, yesNo, loading, empty, panel, link, withQuery, cell };
    GR.util = { isEmpty, uniq, sameSet, humanize, absoluteApiUrl, formatBytes, formValues, errorMessage };
    GR.http = { api, upload, setup };
    GR.ui = { toast, fail, copy, download, modal, drawer, closeOverlays, confirmDialog };
})(window.GR = window.GR || {}, jQuery);
