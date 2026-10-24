/*!
 * Grocery Restable admin GUI — jQuery + Bootstrap 5, no build step.
 * Every dynamic value inserted into HTML goes through esc().
 */
(function ($) {
    'use strict';

    const CFG = JSON.parse(document.body.dataset.gr || '{}');
    const PAGE = document.body.dataset.page;

    // =====================================================================
    // Helpers
    // =====================================================================

    const ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' };
    const esc = (value) => String(value ?? '').replace(/[&<>"'`]/g, (c) => ESC_MAP[c]);
    const isEmpty = (v) => v === null || v === undefined || v === '';
    const uniq = (list) => [...new Set(list)];
    const sameSet = (a, b) => a.length === b.length && a.every((x) => b.includes(x));
    const icon = (name, extra = '') => `<i class="fa-solid fa-${esc(name)} ${esc(extra)}"></i>`;
    const badge = (text, cls = 'secondary') => `<span class="badge text-bg-${esc(cls)}">${esc(text)}</span>`;
    const sourceBadge = (source) => `<span class="badge gr-badge-${esc(source)}">${esc(source)}</span>`;
    // Code blocks are highlighted by highlight.js (see startHighlighting()); the text is always escaped.
    const codeBlock = (text, lang = 'plaintext', extra = '') => `<pre class="gr-code ${esc(extra)}"><code class="language-${esc(lang)}">${esc(text)}</code></pre>`;
    const yesNo = (v) => v ? `<span class="text-success">${icon('check')}</span>` : `<span class="text-muted">${icon('minus')}</span>`;
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

    function cell(value) {
        if (value === null || value === undefined) return '<span class="gr-null">NULL</span>';
        if (typeof value === 'boolean') return value ? 'true' : 'false';
        if (typeof value === 'object') return `<code>${esc(JSON.stringify(value))}</code>`;
        return esc(value);
    }

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

    /**
     * POST an action to {admin}/xhr. Resolves with the "data" member.
     */
    function api(action, data = {}) {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: CFG.adminUrl + '/xhr',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ action, ...data }),
                headers: { 'X-CSRF-Token': CFG.csrf },
                dataType: 'json',
            }).done((r) => resolve(r.data)).fail((xhr) => {
                const err = parseError(xhr);
                if (err.status === 401) { window.location.href = CFG.adminUrl + '/login'; return; }
                reject(err);
            });
        });
    }

    function upload(action, formData) {
        formData.append('action', action);
        return new Promise((resolve, reject) => {
            $.ajax({
                url: CFG.adminUrl + '/xhr', method: 'POST', data: formData, processData: false, contentType: false,
                headers: { 'X-CSRF-Token': CFG.csrf }, dataType: 'json',
            }).done((r) => resolve(r.data)).fail((xhr) => reject(parseError(xhr)));
        });
    }

    function toast(message, type = 'success', html = false) {
        const iconName = { success: 'circle-check', danger: 'circle-exclamation', warning: 'triangle-exclamation', info: 'circle-info' }[type] || 'circle-info';
        const $t = $(`<div class="toast align-items-center text-bg-${esc(type)} border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex"><div class="toast-body">${icon(iconName, 'me-1')} <span class="msg"></span></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>`);
        html ? $t.find('.msg').html(message) : $t.find('.msg').text(message);
        $('#gr-toasts').append($t);
        const t = new bootstrap.Toast($t[0], { delay: type === 'danger' ? 8000 : 3500 });
        $t.on('hidden.bs.toast', () => $t.remove());
        t.show();
    }

    const fail = (error) => toast(errorMessage(error), 'danger', true);

    /**
     * Show the shared modal. buttons: [{label, cls, action: ($modal) => Promise|false|void}]
     */
    function modal({ title, body, buttons = [], size = '' }) {
        const $m = $('#gr-modal');
        $m.find('.modal-dialog').attr('class', 'modal-dialog modal-dialog-scrollable ' + (size ? 'modal-' + size : ''));
        $m.find('.modal-title').html(title);
        $m.find('.modal-body').off().html(body);
        const $footer = $m.find('.modal-footer').empty();
        $footer.append('<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>');
        buttons.forEach((b) => {
            const $b = $(`<button type="button" class="btn btn-sm ${esc(b.cls || 'btn-primary')}">${b.label}</button>`);
            $b.on('click', async () => {
                $b.prop('disabled', true);
                try {
                    const result = await b.action($m);
                    if (result !== false) instance.hide();
                } catch (e) {
                    fail(e);
                } finally {
                    $b.prop('disabled', false);
                }
            });
            $footer.append($b);
        });
        const instance = bootstrap.Modal.getOrCreateInstance($m[0]);
        instance.show();
        return $m;
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

    function copy(text) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => toast('Copied to clipboard'));
        } else {
            const $t = $('<textarea class="position-fixed opacity-0"></textarea>').val(text).appendTo('body');
            $t[0].select();
            document.execCommand('copy');
            $t.remove();
            toast('Copied to clipboard');
        }
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

    const loading = (text = 'Loading…') => `<div class="text-center text-muted py-5">${icon('spinner', 'fa-spin')} ${esc(text)}</div>`;
    const panel = (title, body, actions = '', footer = '') => `<div class="gr-panel"><div class="gr-panel-title">${title}<div class="gr-actions">${actions}</div></div>
        <div class="gr-panel-body">${body}</div>${footer ? `<div class="gr-panel-footer">${footer}</div>` : ''}</div>`;

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

    // =====================================================================
    // Setup wizard
    // =====================================================================

    function setupWizard() {
        const state = {
            step: 1,
            framework: CFG.frameworks[CFG.framework] && CFG.frameworks[CFG.framework].available ? CFG.framework : 'standalone',
            database: { mode: 'custom', connection: CFG.frameworkConnections[0] ? CFG.frameworkConnections[0].name : '', driver: defaultEngine(CFG.engines), client: 'pdo', host: '127.0.0.1', port: '', database: '', username: '', password: '', schema: 'public' },
            account: { username: 'admin', password: '', password_confirm: '' },
            options: { auth_mode: 'api_key', auto_expose: true, app_name: 'Grocery Restable' },
            tested: false,
        };
        const $body = $('#gr-setup-body');
        const $next = $('#gr-setup-next');
        const $back = $('#gr-setup-back');

        function call(action, data) {
            return new Promise((resolve, reject) => {
                $.ajax({ url: CFG.adminUrl + '/setup', method: 'POST', contentType: 'application/json', data: JSON.stringify({ action, ...data }), headers: { 'X-CSRF-Token': CFG.csrf }, dataType: 'json' })
                    .done(resolve).fail((xhr) => reject(parseError(xhr)));
            });
        }

        const alertBox = (html, type = 'danger') => `<div class="alert alert-${type} py-2 small mt-3 mb-0">${html}</div>`;
        // With CodeIgniter 4 the database group of app/Config/Database.php is the natural default.
        const useFrameworkDb = () => state.framework === 'codeigniter4' && CFG.frameworkConnections.length > 0;
        if (useFrameworkDb()) state.database.mode = 'framework';

        function render() {
            $('#gr-steps li').each(function () {
                const s = Number(this.dataset.step);
                $(this).toggleClass('active', s === state.step).toggleClass('done', s < state.step);
            });
            $back.prop('disabled', state.step === 1 || state.step === 5);
            $next.toggle(state.step !== 5).html(state.step === 4 ? `${icon('check')} Finish setup` : `Next ${icon('chevron-right', 'ms-1')}`);

            if (state.step === 1) {
                $body.html(`<h2 class="gr-section-title">${icon('layer-group')}Choose your PHP framework</h2>
                    <p class="gr-help">With CodeIgniter 4, Grocery Restable reuses its routing, database groups, validation and sessions. Without a framework it runs on plain PHP with its own PDO / MySQLi connection.</p>
                    <div class="row g-2">${Object.entries(CFG.frameworks).map(([key, f]) => `
                        <div class="col-md-6"><label class="gr-choice ${f.available ? '' : 'disabled'}">
                            <span class="gr-choice-icon">${icon(key === 'codeigniter4' ? 'fire' : 'code')}</span>
                            <input type="radio" name="framework" value="${esc(key)}" ${state.framework === key ? 'checked' : ''} ${f.available ? '' : 'disabled'}>
                            <b>${esc(f.label)}</b>
                            <div class="small text-muted">${f.detected ? 'Detected — running now' : f.available ? 'Use your own database connection' : 'Not detected: install Grocery Restable inside a CodeIgniter 4 app'}</div>
                        </label></div>`).join('')}</div>`);
                $body.find('input[name=framework]').on('change', function () {
                    state.framework = this.value;
                    state.database.mode = useFrameworkDb() ? 'framework' : 'custom';
                    state.tested = false;
                });
            } else if (state.step === 2) {
                const d = state.database;
                const fw = useFrameworkDb() ? CFG.frameworkConnections : [];
                $body.html(`<h2 class="gr-section-title">${icon('database')}Database connection</h2>
                    ${fw.length ? `<div class="mb-3">
                        <div class="form-check"><input class="form-check-input" type="radio" name="mode" id="m-fw" value="framework" ${d.mode === 'framework' ? 'checked' : ''}>
                        <label class="form-check-label" for="m-fw">Use the CodeIgniter database configuration (app/Config/Database.php)</label></div>
                        <select class="form-select form-select-sm ms-4 mt-1 w-auto" name="connection">${fw.map((c) => `<option value="${esc(c.name)}" ${c.name === d.connection ? 'selected' : ''}>${esc(c.name)} — ${esc(c.label || c.driver || '')} ${esc(c.database || '')}</option>`).join('')}</select>
                        <div class="form-check mt-2"><input class="form-check-input" type="radio" name="mode" id="m-custom" value="custom" ${d.mode === 'custom' ? 'checked' : ''}>
                        <label class="form-check-label" for="m-custom">Configure a connection here (stored encrypted in SQLite)</label></div></div>` : ''}
                    <div id="custom-db" class="${d.mode === 'custom' ? '' : 'd-none'}">${connectionFields(d, CFG.engines)}</div>
                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="test-db">${icon('plug')} Test connection</button>
                    <div id="db-result"></div>`);
                bindConnectionFields($body, d);
                $body.find('input[name=mode]').on('change', function () {
                    d.mode = this.value;
                    $('#custom-db').toggleClass('d-none', d.mode !== 'custom');
                    state.tested = false;
                });
                $body.find('select[name=connection]').on('change', function () { d.connection = this.value; state.tested = false; });
                $body.find('#test-db').on('click', async () => {
                    Object.assign(d, connectionFieldValues($body));
                    $('#db-result').html(alertBox(`${icon('spinner', 'fa-spin')} Testing…`, 'secondary'));
                    try {
                        const r = await call('test', { database: d });
                        state.tested = r.ok;
                        $('#db-result').html(alertBox(esc(r.message), r.ok ? 'success' : 'danger'));
                    } catch (e) { $('#db-result').html(alertBox(errorMessage(e))); }
                });
            } else if (state.step === 3) {
                const a = state.account;
                $body.html(`<h2 class="gr-section-title">${icon('user-shield')}Administrator account</h2>
                    <p class="gr-help">This account logs into this GUI. Passwords are hashed with PHP's password_hash().</p>
                    <div class="row g-2" style="max-width:480px">
                        <div class="col-12"><label class="form-label small mb-0">Username</label><input class="form-control form-control-sm" name="username" value="${esc(a.username)}" autocomplete="username"></div>
                        <div class="col-6"><label class="form-label small mb-0">Password (min. 8)</label><input type="password" class="form-control form-control-sm" name="password" value="${esc(a.password)}" autocomplete="new-password"></div>
                        <div class="col-6"><label class="form-label small mb-0">Confirm password</label><input type="password" class="form-control form-control-sm" name="password_confirm" value="${esc(a.password_confirm)}" autocomplete="new-password"></div>
                    </div><div id="acc-result"></div>`);
            } else if (state.step === 4) {
                const o = state.options;
                $body.html(`<h2 class="gr-section-title">${icon('sliders')}API options</h2>
                    <div class="mb-2"><label class="form-label small mb-0">Application name</label><input class="form-control form-control-sm w-50" name="app_name" value="${esc(o.app_name)}"></div>
                    <label class="form-label small mb-0">Authentication</label>
                    <select class="form-select form-select-sm w-50 mb-1" name="auth_mode">
                        <option value="api_key" ${o.auth_mode === 'api_key' ? 'selected' : ''}>API keys (recommended)</option>
                        <option value="any" ${o.auth_mode === 'any' ? 'selected' : ''}>API keys + JWT bearer tokens</option>
                        <option value="jwt" ${o.auth_mode === 'jwt' ? 'selected' : ''}>JWT bearer tokens only</option>
                        <option value="none" ${o.auth_mode === 'none' ? 'selected' : ''}>None — open API (development only!)</option>
                    </select>
                    <p class="gr-help">An <code>anon</code> key and a <code>service_role</code> key are generated at the end (like Supabase).</p>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="auto" name="auto_expose" ${o.auto_expose ? 'checked' : ''}>
                    <label class="form-check-label" for="auto">Auto expose every table (relations detected from foreign keys)</label></div>
                    <p class="gr-help">You can fine tune each table afterwards in the GUI or in code. Auto exposed tables follow the default permissions.</p>
                    <div id="opt-result"></div>`);
            } else {
                $body.html(`<div class="text-center mb-3"><span class="text-success fs-1">${icon('circle-check')}</span>
                    <h2 class="fs-5">Setup complete</h2><p class="text-muted">Store these keys now — they are shown only once.</p></div>
                    <label class="small fw-bold">anon key <span class="text-muted fw-normal">(public, role "anon")</span></label><div class="gr-key mb-2">${esc(state.keys.anon)}</div>
                    <label class="small fw-bold">service_role key <span class="text-muted fw-normal">(secret, bypasses every permission — server side only)</span></label><div class="gr-key mb-3">${esc(state.keys.service_role)}</div>
                    <div class="text-center"><a class="btn btn-primary btn-sm" href="${esc(CFG.adminUrl)}/login">${icon('right-to-bracket')} Go to login</a></div>`);
            }
        }

        function collect() {
            if (state.step === 3) Object.assign(state.account, formValues($body));
            if (state.step === 4) Object.assign(state.options, formValues($body));
            if (state.step === 2 && state.database.mode === 'custom') Object.assign(state.database, connectionFieldValues($body));
        }

        $next.on('click', async () => {
            collect();
            if (state.step === 2 && !state.tested) {
                try {
                    const r = await call('test', { database: state.database });
                    if (!r.ok) { $('#db-result').html(alertBox(esc(r.message))); return; }
                    state.tested = true;
                } catch (e) { $('#db-result').html(alertBox(errorMessage(e))); return; }
            }
            if (state.step === 3) {
                const a = state.account;
                if (a.password.length < 8) { $('#acc-result').html(alertBox('The password must be at least 8 characters.')); return; }
                if (a.password !== a.password_confirm) { $('#acc-result').html(alertBox('The passwords do not match.')); return; }
            }
            if (state.step === 4) {
                $next.prop('disabled', true);
                try {
                    const r = await call('finish', { framework: state.framework, database: state.database, account: state.account, options: state.options });
                    state.keys = r.keys;
                } catch (e) {
                    $('#opt-result').html(alertBox(errorMessage(e)));
                    return;
                } finally { $next.prop('disabled', false); }
            }
            state.step++;
            render();
        });
        $back.on('click', () => { collect(); state.step--; render(); });
        render();
    }

    // Shared connection form (setup + connections page)
    const engineUsable = (e) => e.supported && Object.values(e.clients).some((c) => c.installed);

    function defaultEngine(engines) {
        return Object.keys(engines).find((k) => engineUsable(engines[k])) || 'mysql';
    }

    function clientOptions(engine, selected) {
        if (!engine) return '';
        const clients = Object.entries(engine.clients);
        const usable = clients.find(([k, c]) => k === selected && c.installed) ? selected : (clients.find(([, c]) => c.installed) || [''])[0];
        return clients.map(([k, c]) => `<option value="${esc(k)}" ${k === usable ? 'selected' : ''} ${c.installed ? '' : 'disabled'}>${esc(c.label)} — ${esc(c.extension)}${c.installed ? '' : ' (not installed)'}</option>`).join('');
    }

    function connectionFields(d, engines) {
        return `<div class="row g-2">
            <div class="col-md-4"><label class="form-label small mb-0">Database engine</label><select class="form-select form-select-sm" name="driver">
                ${Object.entries(engines).map(([k, e]) => `<option value="${esc(k)}" ${d.driver === k ? 'selected' : ''} ${engineUsable(e) ? '' : 'disabled'}>${esc(e.label)}${!e.supported ? ' (not supported yet)' : !engineUsable(e) ? ' (PHP extension missing)' : ''}</option>`).join('')}</select></div>
            <div class="col-md-8"><label class="form-label small mb-0">PHP driver</label><select class="form-select form-select-sm" name="client" data-engines="${esc(JSON.stringify(engines))}">${clientOptions(engines[d.driver], d.client || 'pdo')}</select></div>
            <div class="col-md-5 gr-net"><label class="form-label small mb-0">Host</label><input class="form-control form-control-sm" name="host" value="${esc(d.host || '127.0.0.1')}"></div>
            <div class="col-md-3 gr-net"><label class="form-label small mb-0">Port</label><input class="form-control form-control-sm" name="port" value="${esc(d.port || '')}" placeholder="default"></div>
            <div class="col-md-12"><label class="form-label small mb-0 gr-db-label">Database</label><input class="form-control form-control-sm" name="database" value="${esc(d.database || '')}"></div>
            <div class="col-md-6 gr-net"><label class="form-label small mb-0">Username</label><input class="form-control form-control-sm" name="username" value="${esc(d.username || '')}" autocomplete="off"></div>
            <div class="col-md-6 gr-net"><label class="form-label small mb-0">Password</label><input type="password" class="form-control form-control-sm" name="password" value="" autocomplete="new-password" placeholder="${d.name ? 'leave empty to keep' : ''}"></div>
            <div class="col-md-6 gr-pg"><label class="form-label small mb-0">Schema</label><input class="form-control form-control-sm" name="schema" value="${esc(d.schema || 'public')}"></div>
            <div class="col-md-6 gr-pg"><label class="form-label small mb-0">SSL mode</label><select class="form-select form-select-sm" name="sslmode">
                ${['prefer', 'disable', 'require', 'verify-ca', 'verify-full'].map((m) => `<option ${d.sslmode === m ? 'selected' : ''}>${m}</option>`).join('')}</select></div>
        </div>`;
    }

    function bindConnectionFields($scope) {
        const $client = $scope.find('[name=client]');
        const engines = JSON.parse($client.attr('data-engines') || '{}');
        $scope.find('[name=driver]').on('change', function () { $client.html(clientOptions(engines[this.value], $client.val())); });
        const sync = () => {
            const driver = $scope.find('[name=driver]').val();
            $scope.find('.gr-net').toggleClass('d-none', driver === 'sqlite');
            $scope.find('.gr-pg').toggleClass('d-none', driver !== 'pgsql');
            $scope.find('.gr-db-label').text(driver === 'sqlite' ? 'SQLite file path (absolute)' : 'Database name');
        };
        $scope.find('[name=driver]').on('change', sync);
        sync();
    }

    function connectionFieldValues($scope) {
        const v = {};
        ['driver', 'client', 'host', 'port', 'database', 'username', 'password', 'schema', 'sslmode'].forEach((k) => { v[k] = $scope.find(`[name=${k}]`).val(); });
        return v;
    }

    // =====================================================================
    // App: state, router, navigation
    // =====================================================================

    const state = { boot: null, tables: {}, tablesLoadedAt: {}, expanded: {}, structure: {} };
    const TREE_REFRESH_MS = 10000;
    const $content = () => $('#gr-content');

    function parseHash() {
        const raw = window.location.hash.replace(/^#\/?/, '');
        const [path, query = ''] = raw.split('?');
        const params = Object.fromEntries(new URLSearchParams(query));
        return { parts: path.split('/').filter(Boolean).map(decodeURIComponent), params };
    }

    const link = (...parts) => '#/' + parts.map((p) => encodeURIComponent(p)).join('/');

    function setBreadcrumb(items) {
        const html = [`<span>${icon('server')}</span> <a href="#/home">Server: ${esc(state.boot ? state.boot.settings.app_name : '')}</a>`];
        items.forEach((i) => html.push(`<span class="sep">»</span>${i.icon ? icon(i.icon) + ' ' : ''}${i.href ? `<a href="${esc(i.href)}">${esc(i.label)}</a>` : `<span>${esc(i.label)}</span>`}`));
        $('#gr-breadcrumb').html(html.join(' '));
    }

    function setTabs(tabs, active) {
        $('#gr-tabs').html(tabs.map((t) => `<li><a href="${esc(t.href)}" class="${t.id === active ? 'active' : ''}">${icon(t.icon)} ${esc(t.label)}</a></li>`).join(''));
    }

    const SERVER_TABS = [
        { id: 'home', icon: 'house', label: 'Dashboard', href: '#/home' },
        { id: 'resources', icon: 'cubes', label: 'Resources', href: '#/resources' },
        { id: 'connections', icon: 'database', label: 'Connections', href: '#/connections' },
        { id: 'keys', icon: 'key', label: 'API keys', href: '#/keys' },
        { id: 'storage', icon: 'box-archive', label: 'Storage', href: '#/storage' },
        { id: 'users', icon: 'users', label: 'Users', href: '#/users' },
        { id: 'settings', icon: 'gear', label: 'Settings', href: '#/settings' },
        { id: 'docs', icon: 'book', label: 'API docs', href: '#/docs' },
        { id: 'console', icon: 'terminal', label: 'Console', href: '#/console' },
        { id: 'io', icon: 'right-left', label: 'Export / Import', href: '#/io' },
    ];

    async function loadBoot() {
        state.boot = await api('bootstrap');
        return state.boot;
    }

    async function renderTree() {
        if (!state.boot) return;
        const $tree = $('#gr-tree');
        const connections = state.boot.connections;
        const filter = ($('#gr-tree-filter').val() || '').toLowerCase();
        const current = parseHash().parts;
        const html = ['<ul>'];
        for (const c of connections) {
            const open = state.expanded[c.name] !== false;
            html.push(`<li><a class="gr-node" data-conn="${esc(c.name)}" data-toggle="conn">
                <span class="gr-toggle">${icon(open ? 'minus' : 'plus')}</span>${icon('database')}
                <span class="gr-label">${esc(c.name)}</span>${c.default ? ' ' + badge('default', 'primary') : ''}${c.source === 'framework' ? ' ' + badge('framework', 'secondary') : ''}</a>`);
            if (open) {
                const tables = state.tables[c.name];
                if (!tables) {
                    html.push(`<ul><li class="text-muted small ps-3">${icon('spinner', 'fa-spin')} loading…</li></ul>`);
                    loadTables(c.name);
                } else if (tables.error) {
                    html.push(`<ul><li class="text-danger small ps-3">${icon('triangle-exclamation')} ${esc(tables.error)}</li></ul>`);
                } else {
                    html.push('<ul>');
                    tables.filter((t) => !filter || t.name.toLowerCase().includes(filter)).forEach((t) => {
                        const active = current[0] === 'table' && current[1] === c.name && current[2] === t.name;
                        const dots = t.resources.length
                            ? t.resources.map((r) => `<span class="gr-dot gr-dot-${esc(r.source)}" title="/${esc(r.name)} (${esc(r.source)})"></span>`).join('')
                            : '<span class="gr-dot gr-dot-none" title="not exposed"></span>';
                        html.push(`<li><a class="gr-node ${active ? 'active' : ''}" href="${link('table', c.name, t.name, 'browse')}">
                            ${icon(t.type === 'view' ? 'eye' : 'table')}<span class="gr-label">${esc(t.name)}</span>${dots}</a></li>`);
                    });
                    if (!tables.length) html.push('<li class="text-muted small ps-3">No tables</li>');
                    html.push('</ul>');
                }
            }
            html.push('</li>');
        }
        html.push('</ul>');
        $tree.html(html.join(''));
    }

    async function loadTables(connection, force = false) {
        if (state.tables[connection] && !force) return state.tables[connection];
        const previous = state.tables[connection];
        try {
            state.tables[connection] = await api('tables', { connection });
        } catch (e) {
            state.tables[connection] = { error: $('<div>').html(e.message).text() };
        }
        state.tablesLoadedAt[connection] = Date.now();
        if (Array.isArray(previous) && Array.isArray(state.tables[connection])) announceChanges(connection, previous, state.tables[connection]);
        renderTree();
        return state.tables[connection];
    }

    /** Toast tables that appeared / disappeared since the last load (e.g. after a migration). */
    function announceChanges(connection, before, after) {
        const old = before.map((t) => t.name);
        const now = after.map((t) => t.name);
        after.filter((t) => !old.includes(t.name)).forEach((t) => {
            const status = t.resources.length
                ? `exposed as <code>${esc(CFG.apiUrl)}/${esc(t.resources[0].name)}</code> (${esc(t.resources[0].source)})`
                : `not exposed yet — <a href="${link('table', connection, t.name, 'browse')}" class="text-white">open it</a> to expose it`;
            toast(`${icon('table')} New table <b>${esc(t.name)}</b> detected: ${status}.`, 'info', true);
        });
        old.filter((name) => !now.includes(name)).forEach((name) => toast(`Table <b>${esc(name)}</b> no longer exists.`, 'warning', true));
    }

    /** Expose a table with the generated definition (GUI resource) in one click. */
    async function exposeTable(connection, table) {
        const definition = await api('resource.generate', { connection, table });
        const saved = await api('resource.save', { definition, enabled: true });
        await loadTables(connection, true);
        toast(`<b>${esc(table)}</b> is now available at <code>${esc(CFG.apiUrl)}/${esc(saved.name)}</code>.`, 'success', true);
        return saved;
    }

    async function refreshAll() {
        // Keep the previous lists so new / removed tables can be announced.
        state.structure = {};
        await loadBoot();
        await Promise.all(Object.keys(state.tables).map((connection) => loadTables(connection, true)));
        renderTree();
    }

    async function structure(connection, table, force = false) {
        const key = connection + '\u0000' + table;
        if (!state.structure[key] || force) state.structure[key] = await api('table.structure', { connection, table });
        return state.structure[key];
    }

    async function route() {
        if (!state.boot) return;
        const { parts, params } = parseHash();
        const page = parts[0] || 'home';
        $('#gr-tree .gr-node').removeClass('active');
        const openModal = bootstrap.Modal.getInstance(document.getElementById('gr-modal'));
        if (openModal) openModal.hide();
        $content().off().html(loading());
        $('#gr-status').empty();
        // Re-read the table lists in the background so database changes show up while navigating.
        Object.keys(state.tables).filter((c) => Date.now() - (state.tablesLoadedAt[c] || 0) > TREE_REFRESH_MS).forEach((c) => loadTables(c, true));
        try {
            if (page === 'table') {
                await tablePage(parts[1], parts[2], parts[3] || 'browse', parts[4], params);
            } else {
                setTabs(SERVER_TABS, page === 'bucket' ? 'storage' : page);
                const handler = PAGES[page];
                if (!handler) { $content().html('<div class="gr-empty">Page not found</div>'); return; }
                await handler(parts.slice(1), params);
            }
        } catch (e) {
            $content().html(`<div class="alert alert-danger">${errorMessage(e)}</div>`);
        }
        renderTree();
    }

    // =====================================================================
    // Server level pages
    // =====================================================================

    const PAGES = {};

    PAGES.home = async () => {
        // The first render reuses the data loaded by bootApp(); later visits refresh the counters.
        const b = state.bootFresh ? state.boot : await loadBoot();
        state.bootFresh = false;
        setBreadcrumb([{ label: 'Dashboard' }]);
        const s = b.settings;
        const o = b.overview || {};
        const base = absoluteApiUrl();
        const stat = (value, label, href, iconName) => `<div class="col-6 col-lg"><a class="d-block gr-stat text-decoration-none" href="${href}">
            <div class="value">${icon(iconName, 'fs-5 me-1 text-secondary')}${esc(value)}</div><div class="label">${esc(label)}</div></a></div>`;
        const btn = (href, label, iconName) => `<a class="btn btn-sm btn-outline-primary me-1 mt-1" href="${href}">${icon(iconName)} ${esc(label)}</a>`;
        const step = (status, title, text, actions = '') => `<li class="gr-step gr-step-${status}">
            <span class="gr-step-icon">${icon({ done: 'circle-check', warn: 'triangle-exclamation', todo: 'circle' }[status])}</span>
            <div><b>${title}</b><div class="small text-muted">${text}</div>${actions ? `<div>${actions}</div>` : ''}</div></li>`;

        const steps = [
            step(o.error ? 'warn' : 'done', 'Connect a database', o.error
                ? `The default connection <b>${esc(o.connection)}</b> fails: ${esc(o.error)}`
                : `Default connection <b>${esc(o.connection)}</b> — ${esc(o.tables)} tables / views.`, btn('#/connections', 'Connections', 'database')),
            step(o.exposed > 0 ? 'done' : 'todo', 'Expose tables as API endpoints',
                `${esc(o.exposed ?? 0)} of ${esc(o.tables ?? 0)} tables are endpoints. Auto expose is <b>${s.auto_expose ? 'on' : 'off'}</b>: `
                + (s.auto_expose ? 'every new table becomes an endpoint by itself (detected on the next page load).' : 'expose tables one by one on the Resources page.'),
                btn('#/resources', 'Manage resources', 'cubes')),
            step(s.auth_mode === 'none' ? 'warn' : 'done', 'Secure the API', s.auth_mode === 'none'
                ? 'Authentication is <b>off</b>: anybody can read and write every endpoint. Fine for a local test, never in production.'
                : `Authentication mode <b>${esc(s.auth_mode)}</b>; grant permissions per role on each resource.`, btn('#/settings', 'Settings', 'shield-halved')),
            step(b.counts.apiKeys > 0 ? 'done' : 'todo', 'Give clients a key', `${esc(b.counts.apiKeys)} active API key(s). Send one in the <code>apikey</code> header.`, btn('#/keys', 'API keys', 'key')),
            step('todo', 'Call the API', `Base URL <code>${esc(base)}</code>. Try requests as any role in the console or browse the generated docs.`,
                btn('#/console', 'Console', 'terminal') + btn('#/docs', 'API docs', 'book')),
        ];

        $content().html(`
            ${b.warnings.map((w) => `<div class="alert alert-warning py-2 small">${icon('triangle-exclamation', 'me-1')}${esc(w)}</div>`).join('')}
            <div class="row g-2 mb-3">
                ${stat(b.counts.resources, 'Resources', '#/resources', 'cubes')}
                ${stat(b.connections.length, 'Connections', '#/connections', 'database')}
                ${stat(b.counts.apiKeys, 'Active API keys', '#/keys', 'key')}
                ${stat(b.counts.buckets, 'Buckets', '#/storage', 'box-archive')}
                ${stat(b.counts.functions, 'RPC functions', '#/docs', 'bolt')}
            </div>
            <div class="row g-3">
                <div class="col-xl-7">${panel(`${icon('list-check')} Getting started`, `<ol class="gr-steps-list">${steps.join('')}</ol>`)}</div>
                <div class="col-xl-5">${panel(`${icon('circle-info')} General`, `<table class="gr-table gr-kv w-100 mb-0">
                    <tr><th>Application</th><td>${esc(s.app_name)}</td></tr>
                    <tr><th>Version</th><td>Grocery Restable ${esc(CFG.version)}</td></tr>
                    <tr><th>Framework</th><td>${esc((b.frameworks[b.framework] || {}).label || b.framework)}</td></tr>
                    <tr><th>API base URL</th><td><code>${esc(base)}</code> <a href="#" data-copy="${esc(base)}">${icon('copy')}</a></td></tr>
                    <tr><th>OpenAPI</th><td>${s.expose_openapi ? `<a href="${esc(base)}/openapi.json" target="_blank" rel="noopener">openapi.json</a>` : 'disabled'}</td></tr>
                    <tr><th>Logged in as</th><td>${icon('user')} ${esc(b.user.username)}</td></tr>
                    <tr><th>Credits</th><td class="small">Inspired by <a href="https://github.com/scoumbourdis/grocery-crud" target="_blank" rel="noopener noreferrer">Grocery CRUD</a>. Not affiliated with or endorsed by Grocery CRUD / John Skoumbourdis.</td></tr></table>`)}</div>
            </div>
            ${panel(`${icon('rocket')} Quick start`, `<div class="row g-3">
                <div class="col-lg-4"><p class="small mb-1">List rows of a resource</p>${codeBlock(`curl "${base}/{resource}?select=*&limit=10" \\\n  -H "apikey: <YOUR_KEY>"`, 'bash', 'mb-0')}</div>
                <div class="col-lg-4"><p class="small mb-1">Filter, embed relations and sort</p>${codeBlock(`curl "${base}/employees?select=lastName,offices(city)&officeCode=eq.1&order=lastName.desc" \\\n  -H "apikey: <YOUR_KEY>"`, 'bash', 'mb-0')}</div>
                <div class="col-lg-4"><p class="small mb-1">Create a row</p>${codeBlock(`curl -X POST "${base}/{resource}" \\\n  -H "apikey: <YOUR_KEY>" \\\n  -H "Content-Type: application/json" \\\n  -d '{"name": "value"}'`, 'bash', 'mb-0')}</div></div>`)}`);
    };

    PAGES.resources = async () => {
        setBreadcrumb([{ label: 'Resources', icon: 'cubes' }]);
        const b = await loadBoot();
        const s = b.settings;
        const list = await api('resources.list');
        const byTable = {};
        list.forEach((r) => { (byTable[`${r.connection}\u0000${r.table}`] = byTable[`${r.connection}\u0000${r.table}`] || []).push(r); });
        const seen = new Set();
        let totalTables = 0;
        let exposedTables = 0;

        const sections = [];
        for (const c of b.connections) {
            const tables = await loadTables(c.name, true);
            if (!Array.isArray(tables)) {
                sections.push(panel(`${icon('database')} ${esc(c.name)}`, `<div class="alert alert-danger mb-0 small">${esc(tables.error)}</div>`));
                continue;
            }
            const rows = tables.map((t) => {
                totalTables++;
                const resources = byTable[`${c.name}\u0000${t.name}`] || [];
                resources.forEach((r) => seen.add(r.name));
                if (resources.length) exposedTables++;
                const endpoint = resources.length
                    ? resources.map((r) => `<div><a href="${link('table', c.name, t.name, 'api', r.name)}"><code>/${esc(r.name)}</code></a> ${sourceBadge(r.source)}${r.enabled ? '' : ' ' + badge('disabled', 'secondary')}${r.shadowed ? ' ' + badge('shadowed by code', 'warning') : ''}${(r.problems || []).length ? ` <span class="text-danger" title="${esc(r.problems.join('\n'))}">${icon('triangle-exclamation')}</span>` : ''}</div>`).join('')
                    : '<span class="badge gr-badge-none">not exposed</span>';
                const r0 = resources[0];
                const ops = r0 && r0.operations ? Object.entries(r0.operations).filter(([, v]) => v).map(([k]) => badge(k, 'light')).join(' ') : '';
                const perms = r0 ? (r0.permissions ? Object.keys(r0.permissions).map((k) => badge(k, 'info')).join(' ') : '<span class="text-muted small">default</span>') : '';
                const actions = resources.length
                    ? `<a href="${link('table', c.name, t.name, 'api', r0.name)}">${icon('pen')} Configure</a><a href="${link('table', c.name, t.name, 'try', r0.name)}">${icon('flask')} Try</a>`
                      + (r0.source === 'gui' ? `<a href="#" class="text-danger" data-delete-resource="${esc(r0.name)}">${icon('trash')} Remove</a>` : '')
                    : `<button class="btn btn-sm btn-primary py-0" data-expose="${esc(t.name)}" data-conn="${esc(c.name)}">${icon('plug')} Expose</button>`;
                return `<tr class="${resources.length ? '' : 'gr-row-muted'}"><td>${icon(t.type === 'view' ? 'eye' : 'table', 'text-secondary')} <a href="${link('table', c.name, t.name, 'browse')}"><b>${esc(t.name)}</b></a></td>
                    <td>${endpoint}</td><td>${ops}</td><td>${perms}</td><td class="gr-actions-cell">${actions}</td></tr>`;
            }).join('');
            sections.push(panel(`${icon('database')} ${esc(c.name)} ${c.default ? badge('default', 'primary') : ''} <span class="text-muted fw-normal small">${esc(tables.length)} tables</span>`,
                tables.length ? `<div class="table-responsive"><table class="gr-table w-100 mb-0"><thead><tr><th>Table</th><th>API endpoint</th><th>Operations</th><th>Permissions</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`
                    : '<div class="gr-empty">This database has no tables yet. Create one with your usual tools, then click <b>Refresh schema</b>.</div>'));
        }
        const orphans = list.filter((r) => !seen.has(r.name));
        if (orphans.length) {
            sections.push(panel(`${icon('triangle-exclamation', 'text-warning')} Resources without a table`, `<table class="gr-table w-100 mb-0"><thead><tr><th>Resource</th><th>Source</th><th>Problem</th><th></th></tr></thead><tbody>
                ${orphans.map((r) => `<tr><td><code>/${esc(r.name)}</code></td><td>${sourceBadge(r.source)}</td><td class="small text-danger">${esc((r.problems || []).join(' '))}</td>
                <td class="gr-actions-cell">${r.source === 'gui' ? `<a href="#" class="text-danger" data-delete-resource="${esc(r.name)}">${icon('trash')} Remove</a>` : ''}</td></tr>`).join('')}</tbody></table>`));
        }

        $content().html(`<div class="gr-explain mb-3">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2"><b class="me-auto">${icon('circle-info', 'text-primary')} ${esc(exposedTables)} of ${esc(totalTables)} tables are API endpoints under <code>${esc(CFG.apiUrl)}/{name}</code></b>
                <button class="btn btn-sm btn-outline-secondary" id="refresh-schema">${icon('rotate')} Refresh schema</button>
                <button class="btn btn-sm ${s.auto_expose ? 'btn-outline-danger' : 'btn-outline-primary'}" id="toggle-auto">${icon(s.auto_expose ? 'toggle-on' : 'toggle-off')} Auto expose: ${s.auto_expose ? 'on' : 'off'}</button></div>
            <div class="small">A table becomes an endpoint in one of three ways (the first one wins):
                ${sourceBadge('code')} defined in your application code · ${sourceBadge('gui')} exposed / customized here (stored in SQLite) ·
                ${sourceBadge('auto')} generated from the schema while <b>auto expose</b> is on (default connection only).
                New tables and columns are detected automatically on the next request; <b>Refresh schema</b> forces it.</div></div>`
            + sections.join(''));

        $content().find('[data-expose]').on('click', async function () {
            $(this).prop('disabled', true);
            try { await exposeTable(this.dataset.conn, this.dataset.expose); await loadBoot(); PAGES.resources(); } catch (err) { fail(err); $(this).prop('disabled', false); }
        });
        $content().find('#refresh-schema').on('click', async () => {
            try { await api('schema.refresh'); await refreshAll(); toast('Schema refreshed'); PAGES.resources(); } catch (err) { fail(err); }
        });
        $content().find('#toggle-auto').on('click', async () => {
            const enable = !s.auto_expose;
            if (!enable && !await confirmDialog('Turn auto expose off? Tables that are only <b>auto</b> exposed stop being API endpoints until you expose them.', 'Turn off', 'btn-warning')) return;
            try { await api('settings.save', { settings: { auto_expose: enable } }); toast(`Auto expose ${enable ? 'enabled' : 'disabled'}`); await refreshAll(); PAGES.resources(); } catch (err) { fail(err); }
        });
        $content().find('[data-delete-resource]').on('click', async function (e) {
            e.preventDefault();
            const name = this.dataset.deleteResource;
            if (!await confirmDialog(`Remove the GUI resource <b>${esc(name)}</b>? The table is not touched${s.auto_expose ? ' (it falls back to its auto definition while auto expose is on)' : ''}.`, 'Remove')) return;
            try { await api('resource.delete', { name }); toast('Resource removed'); await refreshAll(); PAGES.resources(); } catch (err) { fail(err); }
        });
    };

    PAGES.connections = async () => {
        setBreadcrumb([{ label: 'Connections', icon: 'database' }]);
        const list = await api('connections.list');
        $content().html(panel(`${icon('database')} Database connections`, `<table class="gr-table w-100"><thead><tr><th>Name</th><th>Source</th><th>Engine</th><th>PHP driver</th><th>Host</th><th>Database</th><th>Default</th><th></th></tr></thead><tbody>
            ${list.map((c) => `<tr><td><b>${esc(c.name)}</b></td><td>${sourceBadge(c.source)}</td><td>${esc((state.boot.engines[c.driver] || {}).label || c.driver || '')}</td><td>${esc(c.source === 'framework' ? c.client || '' : ({ pdo: 'PDO', mysqli: 'MySQLi' }[c.client || 'pdo']))}</td><td>${esc(c.host || '')}${c.port ? ':' + esc(c.port) : ''}</td>
                <td>${esc(c.database || '')}</td><td class="text-center">${c.default ? icon('star', 'text-warning') : `<a href="#" data-default="${esc(c.name)}" title="Make default">${icon('star', 'text-muted')}</a>`}</td>
                <td class="gr-actions-cell"><a href="#" data-test="${esc(c.name)}">${icon('plug')} Test</a>
                ${c.source === 'gui' ? `<a href="#" data-edit="${esc(c.name)}">${icon('pen')} Edit</a>${!c.default ? `<a href="#" class="text-danger" data-del="${esc(c.name)}">${icon('trash')} Delete</a>` : ''}` : '<span class="text-muted small">configured in the framework</span>'}</td></tr>`).join('')}
            </tbody></table>`, `<button class="btn btn-sm btn-primary" data-add>${icon('plus')} Add connection</button>`));

        const editor = (existing) => {
            const d = existing || { driver: defaultEngine(state.boot.engines), client: 'pdo' };
            const $m = modal({
                title: existing ? `Edit connection <b>${esc(existing.name)}</b>` : 'Add connection',
                body: `<div class="mb-2"><label class="form-label small mb-0">Connection name</label><input class="form-control form-control-sm" name="name" value="${esc(d.name || '')}" ${existing ? 'readonly' : ''}></div>
                    ${connectionFields(d, state.boot.engines)}<div class="mt-2" id="conn-result"></div>`,
                size: 'lg',
                buttons: [
                    { label: `${icon('plug')} Test`, cls: 'btn-outline-primary', action: async ($modal) => {
                        const r = await api('connection.test', { name: $modal.find('[name=name]').val(), config: connectionFieldValues($modal) });
                        $modal.find('#conn-result').html(`<div class="alert alert-${r.ok ? 'success' : 'danger'} py-1 small mb-0">${esc(r.message)}</div>`);
                        return false;
                    } },
                    { label: `${icon('floppy-disk')} Save`, action: async ($modal) => {
                        await api('connection.save', { name: $modal.find('[name=name]').val(), config: connectionFieldValues($modal) });
                        toast('Connection saved');
                        await refreshAll();
                        PAGES.connections();
                    } },
                ],
            });
            bindConnectionFields($m);
        };
        $content().find('[data-add]').on('click', () => editor(null));
        $content().find('[data-edit]').on('click', function (e) { e.preventDefault(); editor(list.find((c) => c.name === this.dataset.edit)); });
        $content().find('[data-test]').on('click', async function (e) {
            e.preventDefault();
            try { const r = await api('connection.test', { name: this.dataset.test }); toast(r.message, r.ok ? 'success' : 'danger'); } catch (err) { fail(err); }
        });
        $content().find('[data-default]').on('click', async function (e) {
            e.preventDefault();
            try { await api('connection.default', { name: this.dataset.default }); toast('Default connection changed'); await refreshAll(); PAGES.connections(); } catch (err) { fail(err); }
        });
        $content().find('[data-del]').on('click', async function (e) {
            e.preventDefault();
            if (!await confirmDialog(`Delete connection <b>${esc(this.dataset.del)}</b>?`, 'Delete')) return;
            try { await api('connection.delete', { name: this.dataset.del }); toast('Connection deleted'); await refreshAll(); PAGES.connections(); } catch (err) { fail(err); }
        });
    };

    PAGES.keys = async () => {
        setBreadcrumb([{ label: 'API keys', icon: 'key' }]);
        const [keys, boot] = [await api('apikeys.list'), state.boot];
        const jwtOn = ['jwt', 'any'].includes(boot.settings.auth_mode);
        $content().html(panel(`${icon('key')} API keys`, `<table class="gr-table w-100"><thead><tr><th>#</th><th>Name</th><th>Prefix</th><th>Role</th><th>Created</th><th>Last used</th><th>Status</th><th></th></tr></thead><tbody>
            ${keys.map((k) => `<tr class="${k.revoked_at ? 'text-muted' : ''}"><td>${esc(k.id)}</td><td>${esc(k.name)}</td><td><code>${esc(k.prefix)}…</code></td><td>${badge(k.role, k.role === 'service_role' ? 'danger' : 'info')}</td>
                <td>${esc(k.created_at || '')}</td><td>${esc(k.last_used_at || 'never')}</td><td>${k.revoked_at ? badge('revoked', 'secondary') : badge('active', 'success')}</td>
                <td class="gr-actions-cell">${!k.revoked_at ? `<a href="#" data-revoke="${esc(k.id)}" class="text-warning">${icon('ban')} Revoke</a>` : ''}<a href="#" data-del="${esc(k.id)}" class="text-danger">${icon('trash')} Delete</a></td></tr>`).join('') || '<tr><td colspan="8" class="text-center text-muted">No keys</td></tr>'}
            </tbody></table>
            <p class="gr-help mb-0">Send a key in the <code>apikey</code> header, <code>X-API-Key</code> header or <code>Authorization: Bearer</code>. Only a SHA-256 hash of each key is stored. The <code>service_role</code> role bypasses every permission: never ship it to browsers or mobile apps.</p>`,
            `<button class="btn btn-sm btn-primary" data-add>${icon('plus')} New API key</button>`)
            + panel(`${icon('id-badge')} JWT test token`, jwtOn ? `<div class="row g-2 align-items-end">
                <div class="col-md-3"><label class="form-label small mb-0">Role</label><input class="form-control form-control-sm" id="jwt-role" value="authenticated"></div>
                <div class="col-md-3"><label class="form-label small mb-0">Subject (sub)</label><input class="form-control form-control-sm" id="jwt-sub" value="user-1"></div>
                <div class="col-md-3"><label class="form-label small mb-0">Expires in (seconds)</label><input type="number" class="form-control form-control-sm" id="jwt-exp" value="3600"></div>
                <div class="col-md-3"><button class="btn btn-sm btn-outline-primary w-100" id="jwt-gen">${icon('wand-magic-sparkles')} Generate</button></div></div>
                <div id="jwt-out" class="mt-2"></div>`
                : `<p class="mb-0 small text-muted">JWT authentication is disabled. Set the authentication mode to "API keys + JWT" or "JWT only" in <a href="#/settings">Settings</a>.</p>`));

        $content().find('[data-add]').on('click', () => modal({
            title: 'New API key',
            body: `<div class="mb-2"><label class="form-label small mb-0">Name</label><input class="form-control form-control-sm" name="name" placeholder="Mobile app"></div>
                <label class="form-label small mb-0">Role</label><input class="form-control form-control-sm" name="role" value="authenticated" list="gr-roles">
                <datalist id="gr-roles"><option value="anon"><option value="authenticated"><option value="service_role"></datalist>
                <p class="gr-help mt-1 mb-0">Permissions are granted per role in each resource (or in the default permissions).</p>`,
            buttons: [{ label: `${icon('key')} Create`, action: async ($m) => {
                const r = await api('apikey.create', { name: $m.find('[name=name]').val(), role: $m.find('[name=role]').val() });
                setTimeout(() => modal({
                    title: `${icon('key')} Your new API key`,
                    body: `<p class="small">Copy it now — it will not be shown again.</p><div class="gr-key">${esc(r.key)}</div>`,
                    buttons: [{ label: `${icon('copy')} Copy`, cls: 'btn-outline-primary', action: () => { copy(r.key); return false; } }],
                }), 400);
                PAGES.keys();
            } }],
        }));
        $content().find('[data-revoke]').on('click', async function (e) {
            e.preventDefault();
            if (!await confirmDialog('Revoke this key? Clients using it will receive 401.', 'Revoke', 'btn-warning')) return;
            try { await api('apikey.revoke', { id: Number(this.dataset.revoke) }); toast('Key revoked'); PAGES.keys(); } catch (err) { fail(err); }
        });
        $content().find('[data-del]').on('click', async function (e) {
            e.preventDefault();
            if (!await confirmDialog('Delete this key permanently?', 'Delete')) return;
            try { await api('apikey.delete', { id: Number(this.dataset.del) }); toast('Key deleted'); PAGES.keys(); } catch (err) { fail(err); }
        });
        $content().find('#jwt-gen').on('click', async () => {
            try {
                const r = await api('jwt.generate', { role: $('#jwt-role').val(), sub: $('#jwt-sub').val(), expiresIn: Number($('#jwt-exp').val()) });
                $('#jwt-out').html(`<div class="gr-key small">${esc(r.token)}</div>${codeBlock(JSON.stringify(r.claims, null, 2), 'json', 'mt-2 mb-0')}`);
            } catch (err) { fail(err); }
        });
    };

    function permissionGrid(permissions, name = 'perm') {
        const roles = uniq(['anon', 'authenticated', ...Object.keys(permissions || {})]);
        const ops = state.boot.permissions;
        return `<table class="gr-table gr-perm-grid" data-name="${esc(name)}"><thead><tr><th>Role</th>${ops.map((o) => `<th class="text-center">${esc(o)}</th>`).join('')}<th></th></tr></thead><tbody>
            ${roles.map((r) => permissionRow(r, (permissions || {})[r] || [], ops)).join('')}</tbody>
            <tfoot><tr><td colspan="${ops.length + 2}"><div class="input-group input-group-sm" style="max-width:320px"><input class="form-control" placeholder="role name (or * for everybody)" data-new-role>
            <button class="btn btn-outline-secondary" type="button" data-add-role>${icon('plus')} Add role</button></div></td></tr></tfoot></table>`;
    }

    function permissionRow(role, granted, ops) {
        return `<tr data-role="${esc(role)}"><td><b>${esc(role)}</b>${role === '*' ? ' <span class="text-muted small">(everybody)</span>' : ''}</td>
            ${ops.map((o) => `<td class="text-center"><input type="checkbox" class="form-check-input" data-op="${esc(o)}" ${granted.includes(o) ? 'checked' : ''}></td>`).join('')}
            <td>${['anon', 'authenticated'].includes(role) ? '' : `<a href="#" class="text-danger" data-remove-role>${icon('xmark')}</a>`}</td></tr>`;
    }

    function bindPermissionGrid($scope) {
        $scope.on('click', '[data-add-role]', function () {
            const $grid = $(this).closest('table');
            const role = $grid.find('[data-new-role]').val().trim();
            if (!/^(\*|[A-Za-z_][A-Za-z0-9_-]{0,63})$/.test(role) || $grid.find(`tr[data-role="${CSS.escape(role)}"]`).length) { toast('Invalid or duplicate role name', 'warning'); return; }
            $grid.find('tbody').append(permissionRow(role, [], state.boot.permissions));
            $grid.find('[data-new-role]').val('');
        });
        $scope.on('click', '[data-remove-role]', function (e) { e.preventDefault(); $(this).closest('tr').remove(); });
    }

    function readPermissionGrid($grid) {
        const result = {};
        $grid.find('tbody tr[data-role]').each(function () {
            result[this.dataset.role] = $(this).find('[data-op]:checked').map(function () { return this.dataset.op; }).get();
        });
        return result;
    }

    PAGES.storage = async () => {
        setBreadcrumb([{ label: 'Storage', icon: 'box-archive' }]);
        const buckets = await api('buckets.list');
        $content().html(panel(`${icon('box-archive')} Storage buckets`, `<table class="gr-table w-100"><thead><tr><th>Name</th><th>Source</th><th>Public</th><th>Allowed types</th><th>Max size</th><th>Permissions</th><th>Description</th><th></th></tr></thead><tbody>
            ${buckets.map((b) => `<tr><td><a href="${link('bucket', b.name)}"><b>${icon('folder', 'text-warning')} ${esc(b.name)}</b></a></td><td>${sourceBadge(b.source || 'gui')}</td><td class="text-center">${yesNo(b.public)}</td>
                <td>${esc(b.allowedTypes || 'default')}</td><td>${esc(b.maxSize || 'default')}</td><td>${b.permissions ? Object.keys(b.permissions).map((r) => badge(r, 'info')).join(' ') : '<span class="text-muted small">default</span>'}</td>
                <td>${esc(b.description || '')}</td><td class="gr-actions-cell"><a href="${link('bucket', b.name)}">${icon('folder-open')} Browse</a>
                ${b.source !== 'code' ? `<a href="#" data-edit="${esc(b.name)}">${icon('pen')} Edit</a><a href="#" class="text-danger" data-del="${esc(b.name)}">${icon('trash')} Delete</a>` : ''}</td></tr>`).join('') || '<tr><td colspan="8" class="text-center text-muted">No buckets</td></tr>'}
            </tbody></table><p class="gr-help mb-0">API: <code>GET/POST ${esc(CFG.apiUrl)}/storage/{bucket}</code>, <code>GET/DELETE ${esc(CFG.apiUrl)}/storage/{bucket}/{path}</code>. Deleting a bucket keeps its files on disk.</p>`,
            `<button class="btn btn-sm btn-primary" data-add>${icon('plus')} New bucket</button>`));

        const editor = (b) => {
            const d = b || { public: false, permissions: null };
            const $m = modal({
                title: b ? `Edit bucket <b>${esc(b.name)}</b>` : 'New bucket',
                size: 'lg',
                body: `<div class="row g-2">
                    <div class="col-md-6"><label class="form-label small mb-0">Name</label><input class="form-control form-control-sm" name="name" value="${esc(d.name || '')}" ${b ? 'readonly' : ''}></div>
                    <div class="col-md-6 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="public" id="b-public" ${d.public ? 'checked' : ''}>
                        <label class="form-check-label" for="b-public">Public (anyone can read / download)</label></div></div>
                    <div class="col-md-6"><label class="form-label small mb-0">Allowed types</label><input class="form-control form-control-sm" name="allowedTypes" value="${esc(d.allowedTypes || '')}" placeholder="jpg|png|pdf (empty = global setting)"></div>
                    <div class="col-md-6"><label class="form-label small mb-0">Max size</label><input class="form-control form-control-sm" name="maxSize" value="${esc(d.maxSize || '')}" placeholder="5MB (empty = global setting)"></div>
                    <div class="col-12"><label class="form-label small mb-0">Description</label><input class="form-control form-control-sm" name="description" value="${esc(d.description || '')}"></div>
                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="b-defperm" ${d.permissions ? '' : 'checked'}>
                        <label class="form-check-label" for="b-defperm">Use the default permissions</label></div><div id="b-perms" class="${d.permissions ? '' : 'd-none'}">${permissionGrid(d.permissions || {}, 'bucket')}</div>
                        <p class="gr-help mb-0">select = list / download, insert = upload, update = overwrite (upsert), delete = delete objects.</p></div></div>`,
                buttons: [{ label: `${icon('floppy-disk')} Save`, action: async ($modal) => {
                    const v = formValues($modal);
                    const definition = { public: v.public, allowedTypes: v.allowedTypes, maxSize: v.maxSize, description: v.description,
                        permissions: $modal.find('#b-defperm').is(':checked') ? null : readPermissionGrid($modal.find('.gr-perm-grid')) };
                    await api('bucket.save', { name: v.name, definition });
                    toast('Bucket saved');
                    PAGES.storage();
                } }],
            });
            bindPermissionGrid($m.find('.modal-body'));
            $m.find('#b-defperm').on('change', function () { $m.find('#b-perms').toggleClass('d-none', this.checked); });
        };
        $content().find('[data-add]').on('click', () => editor(null));
        $content().find('[data-edit]').on('click', function (e) { e.preventDefault(); editor(buckets.find((b) => b.name === this.dataset.edit)); });
        $content().find('[data-del]').on('click', async function (e) {
            e.preventDefault();
            if (!await confirmDialog(`Delete bucket <b>${esc(this.dataset.del)}</b>? Files stay on disk.`, 'Delete')) return;
            try { await api('bucket.delete', { name: this.dataset.del }); toast('Bucket deleted'); PAGES.storage(); } catch (err) { fail(err); }
        });
    };

    PAGES.bucket = async (parts, params) => {
        const bucket = parts[0];
        const prefix = params.prefix || '';
        setBreadcrumb([{ label: 'Storage', icon: 'box-archive', href: '#/storage' }, { label: bucket, icon: 'folder' }, ...(prefix ? [{ label: prefix }] : [])]);
        const r = await api('bucket.objects', { bucket, prefix });
        const crumbs = [`<a href="${link('bucket', bucket)}">${esc(bucket)}</a>`];
        let acc = '';
        prefix.split('/').filter(Boolean).forEach((p) => { acc = acc ? acc + '/' + p : p; crumbs.push(`<a href="${link('bucket', bucket)}?prefix=${encodeURIComponent(acc)}">${esc(p)}</a>`); });
        const parent = prefix.includes('/') ? prefix.slice(0, prefix.lastIndexOf('/')) : '';
        const downloadUrl = (path) => `${CFG.adminUrl}/download?bucket=${encodeURIComponent(bucket)}&path=${encodeURIComponent(path)}`;
        $content().html(panel(`${icon('folder-open')} ${crumbs.join(' / ')}`, `<table class="gr-table w-100"><thead><tr><th>Name</th><th>Size</th><th>Type</th><th>Modified</th><th></th></tr></thead><tbody>
            ${prefix ? `<tr><td colspan="5"><a href="${link('bucket', bucket)}${parent ? '?prefix=' + encodeURIComponent(parent) : ''}">${icon('turn-up')} ..</a></td></tr>` : ''}
            ${r.folders.map((f) => `<tr><td colspan="5"><a href="${link('bucket', bucket)}?prefix=${encodeURIComponent((prefix ? prefix + '/' : '') + f)}">${icon('folder', 'text-warning')} ${esc(f)}</a></td></tr>`).join('')}
            ${r.files.map((f) => `<tr><td>${icon('file', 'text-secondary')} ${esc(f.name)}</td><td>${esc(formatBytes(f.size))}</td><td>${esc(f.mime)}</td><td>${esc(f.updated_at)}</td>
                <td class="gr-actions-cell"><a href="${esc(downloadUrl(f.path))}">${icon('download')} Download</a><a href="#" data-copy="${esc(absoluteApiUrl() + '/storage/' + encodeURIComponent(bucket) + '/' + f.path.split('/').map(encodeURIComponent).join('/'))}">${icon('link')} API URL</a>
                <a href="#" class="text-danger" data-del="${esc(f.path)}">${icon('trash')} Delete</a></td></tr>`).join('')}
            ${!r.folders.length && !r.files.length ? '<tr><td colspan="5" class="text-center text-muted">Empty</td></tr>' : ''}</tbody></table>`, '',
            `<form class="d-flex gap-2 align-items-center" id="up-form"><input type="file" class="form-control form-control-sm w-auto" name="file" required>
             <input class="form-control form-control-sm w-auto" name="path" placeholder="folder (optional)" value="${esc(prefix)}">
             <div class="form-check"><input class="form-check-input" type="checkbox" name="upsert" id="up-upsert"><label class="form-check-label small" for="up-upsert">overwrite</label></div>
             <button class="btn btn-sm btn-primary">${icon('upload')} Upload</button></form>`));
        $('#up-form').on('submit', async function (e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('bucket', bucket);
            fd.set('upsert', $('#up-upsert').is(':checked') ? '1' : '0');
            try { await upload('bucket.upload', fd); toast('File uploaded'); PAGES.bucket(parts, params); } catch (err) { fail(err); }
        });
        $content().find('[data-del]').on('click', async function (e) {
            e.preventDefault();
            if (!await confirmDialog(`Delete <b>${esc(this.dataset.del)}</b>?`, 'Delete')) return;
            try { await api('bucket.object.delete', { bucket, path: this.dataset.del }); toast('File deleted'); PAGES.bucket(parts, params); } catch (err) { fail(err); }
        });
    };

    PAGES.users = async () => {
        setBreadcrumb([{ label: 'Users', icon: 'users' }]);
        const users = await api('users.list');
        $content().html(panel(`${icon('users')} Admin users`, `<table class="gr-table w-100"><thead><tr><th>#</th><th>Username</th><th>Created</th><th>Last login</th><th></th></tr></thead><tbody>
            ${users.map((u) => `<tr><td>${esc(u.id)}</td><td><b>${esc(u.username)}</b>${u.id === state.boot.user.id ? ' ' + badge('you', 'primary') : ''}</td><td>${esc(u.created_at || '')}</td><td>${esc(u.last_login_at || '')}</td>
                <td class="gr-actions-cell"><a href="#" data-pass="${esc(u.id)}">${icon('lock')} Change password</a>${u.id !== state.boot.user.id ? `<a href="#" class="text-danger" data-del="${esc(u.id)}">${icon('trash')} Delete</a>` : ''}</td></tr>`).join('')}
            </tbody></table><p class="gr-help mb-0">Admin users can manage everything in this GUI. API consumers authenticate with API keys or JWTs instead.</p>`,
            `<button class="btn btn-sm btn-primary" data-add>${icon('user-plus')} Add user</button>`));
        $content().find('[data-add]').on('click', () => modal({
            title: 'Add admin user',
            body: `<div class="mb-2"><label class="form-label small mb-0">Username</label><input class="form-control form-control-sm" name="username" autocomplete="off"></div>
                <div><label class="form-label small mb-0">Password (min. 8)</label><input type="password" class="form-control form-control-sm" name="password" autocomplete="new-password"></div>`,
            buttons: [{ label: 'Create', action: async ($m) => { await api('user.create', formValues($m.find('.modal-body'))); toast('User created'); PAGES.users(); } }],
        }));
        $content().find('[data-pass]').on('click', function (e) {
            e.preventDefault();
            const id = Number(this.dataset.pass);
            modal({
                title: 'Change password',
                body: '<label class="form-label small mb-0">New password (min. 8)</label><input type="password" class="form-control form-control-sm" name="password" autocomplete="new-password">',
                buttons: [{ label: 'Save', action: async ($m) => { await api('user.password', { id, password: $m.find('[name=password]').val() }); toast('Password changed'); } }],
            });
        });
        $content().find('[data-del]').on('click', async function (e) {
            e.preventDefault();
            if (!await confirmDialog('Delete this admin user?', 'Delete')) return;
            try { await api('user.delete', { id: Number(this.dataset.del) }); toast('User deleted'); PAGES.users(); } catch (err) { fail(err); }
        });
    };

    PAGES.settings = async () => {
        setBreadcrumb([{ label: 'Settings', icon: 'gear' }]);
        const s = await api('settings.get');
        const sw = (name, label, help = '') => `<div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="s-${name}" name="${name}" ${s[name] ? 'checked' : ''}>
            <label class="form-check-label" for="s-${name}">${label}</label>${help ? `<div class="gr-help">${help}</div>` : ''}</div>`;
        const input = (name, label, help = '', type = 'text', value = s[name]) => `<div class="mb-2"><label class="form-label small mb-0" for="s-${name}">${label}</label>
            <input type="${type}" class="form-control form-control-sm" id="s-${name}" name="${name}" value="${esc(Array.isArray(value) ? value.join(', ') : value ?? '')}">${help ? `<div class="gr-help">${help}</div>` : ''}</div>`;

        $content().html(`<form id="settings-form"><div class="row g-3">
            <div class="col-xl-6">${panel(`${icon('circle-info')} General`, `${input('app_name', 'Application name')}${input('api_description', 'API description (OpenAPI)')}`)}
            ${panel(`${icon('table-list')} API behaviour`, `${sw('auto_expose', 'Auto expose every table of the default connection', 'Tables configured in code or in the GUI keep their own definition.')}
                ${input('auto_exclude_tables', 'Tables excluded from auto expose', 'Comma separated.')}
                <div class="row g-2"><div class="col">${input('default_per_page', 'Default page size', '', 'number')}</div><div class="col">${input('max_limit', 'Maximum page size', '', 'number')}</div>
                <div class="col">${input('schema_cache_ttl', 'Schema cache TTL (s)', '0 disables the cache.', 'number')}</div></div>
                ${sw('response_envelope', 'Wrap list responses in {"data": [...], "meta": {...}}', 'Off = PostgREST compatible bare arrays with Content-Range headers.')}
                ${sw('expose_openapi', 'Publish /openapi.json')}`)}
            ${panel(`${icon('upload')} Uploads`, `${input('upload_allowed_types', 'Allowed file types (default)', 'Separated with |. Server side scripts are always rejected; html/svg/js only when listed explicitly on a field or bucket.')}
                ${input('upload_max_size', 'Maximum file size (default)', 'e.g. 20MB, 512KB')}${sw('delete_replaced_files', 'Delete files from disk when they are replaced or their record is deleted')}`)}</div>
            <div class="col-xl-6">${panel(`${icon('shield-halved')} Authentication`, `<div class="mb-2"><label class="form-label small mb-0">Mode</label><select class="form-select form-select-sm" name="auth_mode">
                ${[['api_key', 'API keys'], ['any', 'API keys + JWT'], ['jwt', 'JWT only'], ['none', 'None (open API — development only)']].map(([v, l]) => `<option value="${v}" ${s.auth_mode === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
                ${sw('allow_query_apikey', 'Accept ?apikey= in the query string', 'Convenient for &lt;img src&gt; but keys end up in server logs.')}
                <label class="form-label small mb-0">Default permissions</label><p class="gr-help mb-1">Used by resources and buckets that do not define their own. <code>service_role</code> always has full access.</p>
                <div id="s-perms">${permissionGrid(s.default_permissions || {}, 'default')}</div>
                <hr><h3 class="fs-6">JWT</h3>
                ${s.jwt_secret_from_config ? '<div class="alert alert-info py-1 small">The JWT secret is set in the framework configuration (jwtSecret) and overrides this one.</div>' : ''}
                <div class="mb-2"><label class="form-label small mb-0">Secret (HMAC) ${s.jwt_secret_set ? badge('set', 'success') : badge('not set', 'secondary')}</label>
                <div class="input-group input-group-sm"><input type="password" class="form-control" name="jwt_secret" placeholder="${s.jwt_secret_set ? 'leave empty to keep the current secret' : 'at least 32 characters'}" autocomplete="new-password">
                <button class="btn btn-outline-secondary" type="button" id="gen-secret">${icon('dice')} Generate</button></div></div>
                <div class="row g-2"><div class="col"><label class="form-label small mb-0">Algorithm</label><select class="form-select form-select-sm" name="jwt_algorithm">${['HS256', 'HS384', 'HS512'].map((a) => `<option ${s.jwt_algorithm === a ? 'selected' : ''}>${a}</option>`).join('')}</select></div>
                <div class="col">${input('jwt_role_claim', 'Role claim', 'Dot notation allowed, e.g. app_metadata.role')}</div><div class="col">${input('jwt_default_role', 'Default role')}</div></div>
                <div class="row g-2"><div class="col">${input('jwt_issuer', 'Required issuer (iss)')}</div><div class="col">${input('jwt_audience', 'Required audience (aud)')}</div></div>`)}
            ${panel(`${icon('globe')} CORS`, `${sw('cors_enabled', 'Send CORS headers')}${input('cors_origins', 'Allowed origins', 'Comma separated, * for any origin.')}${input('cors_max_age', 'Preflight max age (s)', '', 'number')}`)}</div>
            </div><div class="gr-sticky-footer"><button class="btn btn-sm btn-primary" type="submit">${icon('floppy-disk')} Save settings</button>
            <button class="btn btn-sm btn-outline-secondary" type="button" id="refresh-schema">${icon('rotate')} Clear schema cache</button></div></form>`);
        bindPermissionGrid($content());
        $('#gen-secret').on('click', () => {
            const bytes = new Uint8Array(32);
            crypto.getRandomValues(bytes);
            const secret = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
            $('[name=jwt_secret]').attr('type', 'text').val(secret);
        });
        $('#refresh-schema').on('click', async () => { try { await api('schema.refresh'); toast('Schema cache cleared'); await refreshAll(); } catch (err) { fail(err); } });
        $('#settings-form').on('submit', async function (e) {
            e.preventDefault();
            const v = formValues($(this));
            v.default_permissions = readPermissionGrid($('#s-perms .gr-perm-grid'));
            try { await api('settings.save', { settings: v }); toast('Settings saved'); await refreshAll(); PAGES.settings(); } catch (err) { fail(err); }
        });
    };

    PAGES.docs = async () => {
        setBreadcrumb([{ label: 'API docs', icon: 'book' }]);
        const spec = await api('openapi');
        const groups = {};
        Object.entries(spec.paths).forEach(([path, item]) => {
            Object.entries(item).forEach(([method, op]) => {
                if (method === 'parameters') return;
                const tag = (op.tags || ['other'])[0];
                (groups[tag] = groups[tag] || []).push({ path, method, op, params: [...(item.parameters || []), ...(op.parameters || [])] });
            });
        });
        const resolveParam = (p) => (p.$ref ? spec.components.parameters[p.$ref.split('/').pop()] : p);
        const schemaOf = (op) => {
            const content = (op.requestBody || {}).content || {};
            const s = (content['application/json'] || content['multipart/form-data'] || {}).schema;
            return s && s.$ref ? { name: s.$ref.split('/').pop(), schema: spec.components.schemas[s.$ref.split('/').pop()] } : (s ? { name: 'body', schema: s } : null);
        };
        const html = Object.entries(groups).map(([tag, ops]) => {
            const info = (spec.tags || []).find((t) => t.name === tag) || {};
            return panel(`${icon('cube')} ${esc(tag)} <span class="text-muted fw-normal small">${esc(info.description || '')}</span>`, ops.map(({ path, method, op, params }) => {
                const body = schemaOf(op);
                return `<details class="gr-endpoint"><summary><span class="gr-method gr-method-${esc(method)}">${esc(method.toUpperCase())}</span><code>${esc(path)}</code><span class="text-muted">${esc(op.summary || '')}</span></summary>
                    <div class="gr-endpoint-body">
                    ${params.length ? `<table class="gr-table w-100"><thead><tr><th>Parameter</th><th>In</th><th>Description</th></tr></thead><tbody>${params.map(resolveParam).filter(Boolean).map((p) => `<tr><td><code>${esc(p.name)}</code>${p.required ? ' <span class="text-danger">*</span>' : ''}</td><td>${esc(p.in)}</td><td>${esc(p.description || '')}</td></tr>`).join('')}</tbody></table>` : ''}
                    ${body ? `<p class="small mb-1"><b>Body</b> (${esc(body.name)})</p>${codeBlock(JSON.stringify(body.schema, null, 2), 'json')}` : ''}
                    ${method === 'get' && !path.includes('{') ? `<a class="btn btn-sm btn-outline-primary" href="#/console?method=GET&path=${encodeURIComponent(path.replace(/^\//, ''))}">${icon('play')} Try in console</a>` : ''}
                    </div></details>`;
            }).join(''));
        }).join('');
        $content().html(panel(`${icon('book')} ${esc(spec.info.title)} <span class="text-muted fw-normal small">OpenAPI ${esc(spec.openapi)}</span>`, `<p class="small mb-2">${esc(spec.info.description)}</p>
            <p class="small mb-0">Server: <code>${esc(absoluteApiUrl())}</code></p>`, `<button class="btn btn-sm btn-outline-secondary" id="dl-spec">${icon('download')} openapi.json</button>`)
            + queryReference() + (html || '<div class="gr-empty">No resources exposed yet.</div>'));
        $('#dl-spec').on('click', () => download('openapi.json', JSON.stringify(spec, null, 2)));
    };

    function queryReference() {
        const ops = [['eq', 'equals', 'status=eq.active'], ['neq', 'not equal', 'status=neq.archived'], ['gt / gte', 'greater than (or equal)', 'price=gte.10'],
            ['lt / lte', 'less than (or equal)', 'stock=lt.5'], ['like', 'LIKE (use * as wildcard)', 'name=like.*phone*'], ['ilike', 'case insensitive LIKE', 'email=ilike.*@example.com'],
            ['in', 'in a list', 'id=in.(1,2,3)'], ['is', 'is null / true / false', 'deleted_at=is.null'], ['not.', 'negate any operator', 'status=not.in.(a,b)'],
            ['or / and', 'logical groups', 'or=(age.lt.18,age.gt.65)'], ['rel.col', 'filter on a related table', 'offices.city=eq.Paris']];
        return `<details class="gr-panel mb-3"><summary class="gr-panel-title">${icon('filter')} Query syntax reference</summary><div class="gr-panel-body">
            <table class="gr-table w-100"><thead><tr><th>Operator</th><th>Meaning</th><th>Example</th></tr></thead><tbody>${ops.map(([o, m, e]) => `<tr><td><code>${esc(o)}</code></td><td>${esc(m)}</td><td><code>${esc(e)}</code></td></tr>`).join('')}</tbody></table>
            <p class="small mb-0"><b>Projection &amp; embedding:</b> <code>select=id,name,alias:relation(col,nested(*))</code> · <b>Order:</b> <code>order=col.desc.nullslast</code> ·
            <b>Paging:</b> <code>limit</code>/<code>offset</code> or <code>page</code>/<code>per_page</code> · <b>Total:</b> <code>count=exact</code> · <b>Search:</b> <code>q=text</code> · <b>CSV:</b> <code>format=csv</code></p></div></details>`;
    }

    PAGES.console = async (parts, params) => {
        setBreadcrumb([{ label: 'Console', icon: 'terminal' }]);
        const roles = uniq(['service_role', 'anon', 'authenticated', ...Object.keys(state.boot.settings.default_permissions || {})]);
        $content().html(panel(`${icon('terminal')} API console`, `<form id="console-form">
            <div class="row g-2">
                <div class="col-md-2"><select class="form-select form-select-sm" name="method">${['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => `<option ${params.method === m ? 'selected' : ''}>${m}</option>`).join('')}</select></div>
                <div class="col-md-7"><div class="input-group input-group-sm"><span class="input-group-text">${esc(CFG.apiUrl)}/</span><input class="form-control font-monospace" name="path" value="${esc(params.path || '')}" placeholder="employees?select=*&limit=5"></div></div>
                <div class="col-md-3"><div class="input-group input-group-sm"><span class="input-group-text">as</span><input class="form-control" name="role" list="console-roles" value="${esc(params.role || 'service_role')}"></div>
                    <datalist id="console-roles">${roles.map((r) => `<option value="${esc(r)}">`).join('')}</datalist></div>
                <div class="col-md-12"><input class="form-control form-control-sm font-monospace" name="prefer" placeholder="Prefer header, e.g. return=representation, count=exact"></div>
                <div class="col-12"><textarea class="form-control form-control-sm font-monospace" name="body" rows="5" placeholder='JSON body, e.g. {"name": "value"}'>${esc(params.body || '')}</textarea></div>
            </div>
            <div class="mt-2 d-flex gap-2"><button class="btn btn-sm btn-primary">${icon('paper-plane')} Send</button><button type="button" class="btn btn-sm btn-outline-secondary" id="as-curl">${icon('copy')} Copy as cURL</button></div>
            </form><p class="gr-help mt-2 mb-0">Requests run inside the server as the chosen role, without needing a key. <code>service_role</code> bypasses permissions.</p>`)
            + '<div id="console-out"></div>');
        $('#console-form').on('submit', async function (e) {
            e.preventDefault();
            const v = formValues($(this));
            $('#console-out').html(loading('Sending…'));
            try {
                const r = await api('console', v);
                let pretty = r.body;
                let lang = 'plaintext';
                try { pretty = JSON.stringify(JSON.parse(r.body), null, 2); lang = 'json'; } catch (err) { /* not JSON */ }
                const cls = r.status < 300 ? 'success' : r.status < 500 ? 'warning' : 'danger';
                $('#console-out').html(panel(`${badge(r.status, cls)} <span class="small text-muted fw-normal">${esc(r.durationMs)} ms</span>`,
                    `<details class="mb-2"><summary class="small">Response headers</summary><table class="gr-table w-100 mt-1">${Object.entries(r.headers).map(([k, val]) => `<tr><th class="small">${esc(k)}</th><td class="small">${esc(val)}</td></tr>`).join('')}</table></details>
                    ${codeBlock(pretty, lang)}`));
            } catch (err) { $('#console-out').html(`<div class="alert alert-danger">${errorMessage(err)}</div>`); }
        });
        $('#as-curl').on('click', () => {
            const v = formValues($('#console-form'));
            const keyHeader = v.role === 'anon' ? '' : ' \\\n  -H "apikey: <YOUR_KEY>"';
            const shellQuote = (s) => "'" + String(s).replace(/'/g, "'\\''") + "'";
            let cmd = `curl -X ${v.method} ${shellQuote(absoluteApiUrl() + '/' + v.path)}${keyHeader}`;
            if (v.prefer) cmd += ` \\\n  -H ${shellQuote('Prefer: ' + v.prefer)}`;
            if (v.body && v.method !== 'GET') cmd += ` \\\n  -H "Content-Type: application/json" \\\n  -d ${shellQuote(v.body)}`;
            copy(cmd);
        });
        if (params.run) $('#console-form').trigger('submit');
    };

    PAGES.io = async () => {
        setBreadcrumb([{ label: 'Export / Import', icon: 'right-left' }]);
        $content().html(`<div class="row g-3"><div class="col-lg-6">${panel(`${icon('file-export')} Export configuration`, `<p class="small">Downloads settings, connections (without passwords), GUI resources and buckets as JSON. Users, API keys and secrets are never exported.</p>
            <button class="btn btn-sm btn-primary" id="do-export">${icon('download')} Download JSON</button>`)}</div>
            <div class="col-lg-6">${panel(`${icon('file-import')} Import configuration`, `<p class="small">Imports resources, buckets and settings from an export file. Existing items with the same name are replaced.</p>
            <input type="file" class="form-control form-control-sm mb-2" id="import-file" accept="application/json,.json"><button class="btn btn-sm btn-warning" id="do-import">${icon('upload')} Import</button>`)}</div></div>`);
        $('#do-export').on('click', async () => {
            try { const data = await api('config.export'); download('grocery-restable-config.json', JSON.stringify(data, null, 2)); } catch (err) { fail(err); }
        });
        $('#do-import').on('click', async () => {
            const file = $('#import-file')[0].files[0];
            if (!file) { toast('Choose a file', 'warning'); return; }
            try {
                const data = JSON.parse(await file.text());
                if (!await confirmDialog('Import this configuration? Items with the same name are replaced.', 'Import', 'btn-warning')) return;
                const r = await api('config.import', { data });
                toast(`Imported ${r.resources} resources, ${r.buckets} buckets, ${r.settings} settings`);
                await refreshAll();
            } catch (err) { fail(err.message ? err : { message: 'Invalid JSON file' }); }
        });
    };

    // =====================================================================
    // Table pages
    // =====================================================================

    const TABLE_TABS = [
        { id: 'browse', icon: 'table-list', label: 'Browse' },
        { id: 'structure', icon: 'sitemap', label: 'Structure' },
        { id: 'api', icon: 'plug-circle-bolt', label: 'API resource' },
        { id: 'code', icon: 'code', label: 'PHP code' },
        { id: 'try', icon: 'flask', label: 'Try it' },
    ];

    async function tablePage(connection, table, tab, resourceName, params) {
        setTabs(TABLE_TABS.map((t) => ({ ...t, href: link('table', connection, table, t.id) + (resourceName && ['api', 'code', 'try'].includes(t.id) ? '/' + encodeURIComponent(resourceName) : '') })), tab);
        setBreadcrumb([{ label: 'Database: ' + connection, icon: 'database' }, { label: 'Table: ' + table, icon: 'table', href: link('table', connection, table, 'browse') }]);
        state.expanded[connection] = true;
        const info = await structure(connection, table);
        const resources = await tableResources(connection, table);
        renderTableStatus(connection, table, resources);
        if (tab === 'browse') return browseTab(connection, info.table, params);
        if (tab === 'structure') return structureTab(connection, info);
        if (tab === 'api') return resourceTab(connection, info.table, resources, resourceName);
        if (tab === 'code') return codeTab(connection, info.table, resources, resourceName);
        if (tab === 'try') return tryTab(connection, info.table, resources, resourceName);
    }

    /** One line above the table tabs telling whether / how the table is exposed. */
    function renderTableStatus(connection, table, resources) {
        const $status = $('#gr-status');
        if (!resources.length) {
            $status.html(`<div class="gr-status gr-status-off">${icon('plug-circle-xmark')} <span><b>${esc(table)}</b> is not exposed through the API.</span>
                <button class="btn btn-sm btn-primary py-0" id="status-expose">${icon('plug')} Expose with defaults</button>
                <a href="${link('table', connection, table, 'api')}">or configure it first</a></div>`);
            $status.find('#status-expose').on('click', async () => {
                try { const saved = await exposeTable(connection, table); window.location.hash = link('table', connection, table, 'api', saved.name); } catch (err) { fail(err); }
            });
            return;
        }
        const why = { auto: 'generated automatically because auto expose is on', gui: 'configured in this GUI', code: 'defined in your application code' };
        $status.html(resources.map((r) => `<div class="gr-status gr-status-on">${icon('plug-circle-check')}
            <span>Exposed as <a href="${esc(absoluteApiUrl())}/${encodeURIComponent(r.name)}" target="_blank" rel="noopener"><code>${esc(CFG.apiUrl)}/${esc(r.name)}</code></a> ${sourceBadge(r.source)}
            <span class="text-muted">— ${esc(why[r.source] || r.source)}</span></span>
            <a href="${link('table', connection, table, 'api', r.name)}">${icon('pen')} Configure</a>
            <a href="${link('table', connection, table, 'try', r.name)}">${icon('flask')} Try it</a></div>`).join(''));
    }

    async function tableResources(connection, table) {
        const tables = await loadTables(connection);
        const t = Array.isArray(tables) ? tables.find((x) => x.name === table) : null;
        return t ? t.resources : [];
    }

    // ----------------------------------------------------------- browse
    async function browseTab(connection, table, params) {
        const page = Number(params.page || 1);
        const perPage = Number(params.perPage || 25);
        const order = params.order || '';
        const dir = params.dir || 'asc';
        const search = params.search || '';
        const data = await api('table.browse', { connection, table: table.name, page, perPage, order, dir, search });
        const pages = Math.max(1, Math.ceil(data.total / data.perPage));
        const pk = data.primaryKey;
        const go = (p) => link('table', connection, table.name, 'browse') + '?' + new URLSearchParams({ page: p, perPage, order, dir, search }).toString();
        const sortLink = (c) => link('table', connection, table.name, 'browse') + '?' + new URLSearchParams({ page: 1, perPage, order: c, dir: order === c && dir === 'asc' ? 'desc' : 'asc', search }).toString();

        const pager = `<div class="gr-pagination">
            <a class="btn btn-sm btn-outline-secondary ${page <= 1 ? 'disabled' : ''}" href="${go(1)}">${icon('backward-step')}</a>
            <a class="btn btn-sm btn-outline-secondary ${page <= 1 ? 'disabled' : ''}" href="${go(page - 1)}">${icon('chevron-left')}</a>
            <span class="small">Page <b>${page}</b> of ${pages} · ${esc(data.total)} rows</span>
            <a class="btn btn-sm btn-outline-secondary ${page >= pages ? 'disabled' : ''}" href="${go(page + 1)}">${icon('chevron-right')}</a>
            <a class="btn btn-sm btn-outline-secondary ${page >= pages ? 'disabled' : ''}" href="${go(pages)}">${icon('forward-step')}</a>
            <select class="form-select form-select-sm ms-2" id="per-page">${[10, 25, 50, 100, 250, 500].map((n) => `<option ${n === perPage ? 'selected' : ''}>${n}</option>`).join('')}</select>
            <form class="d-flex ms-auto gap-1" id="search-form"><input type="search" class="form-control form-control-sm" name="search" value="${esc(search)}" placeholder="Search all columns">
            <button class="btn btn-sm btn-outline-secondary">${icon('magnifying-glass')}</button></form>
            ${table.type === 'table' ? `<button class="btn btn-sm btn-primary" id="insert-row">${icon('plus')} Insert</button>` : ''}</div>`;

        $content().html(pager + `<div class="table-responsive"><table class="gr-table w-100"><thead><tr>${pk.length ? '<th></th>' : ''}
            ${data.columns.map((c) => `<th class="sortable" data-sort="${esc(c.name)}">${esc(c.name)}${order === c.name ? ' ' + icon(dir === 'asc' ? 'caret-up' : 'caret-down') : ''}${c.primary ? ' ' + icon('key', 'text-warning') : ''}</th>`).join('')}</tr></thead><tbody>
            ${data.rows.map((row, i) => `<tr>${pk.length ? `<td class="gr-actions-cell"><a href="#" data-edit="${i}" title="Edit">${icon('pen')}</a><a href="#" class="text-danger" data-del="${i}" title="Delete">${icon('trash')}</a></td>` : ''}
                ${data.columns.map((c) => `<td class="gr-cell" title="${esc(row[c.name] === null ? 'NULL' : typeof row[c.name] === 'object' ? JSON.stringify(row[c.name]) : row[c.name])}">${cell(row[c.name])}</td>`).join('')}</tr>`).join('')
            || `<tr><td colspan="${data.columns.length + 1}" class="text-center text-muted">No rows</td></tr>`}</tbody></table></div>` + pager);

        $content().find('[data-sort]').on('click', function () { window.location.hash = sortLink(this.dataset.sort); });
        $content().find('#per-page').on('change', function () { window.location.hash = link('table', connection, table.name, 'browse') + '?' + new URLSearchParams({ page: 1, perPage: this.value, order, dir, search }); });
        $content().find('#search-form').on('submit', function (e) { e.preventDefault(); window.location.hash = link('table', connection, table.name, 'browse') + '?' + new URLSearchParams({ page: 1, perPage, order, dir, search: $(this).find('input').val() }); });
        const keyOf = (row) => Object.fromEntries(pk.map((k) => [k, row[k]]));
        $content().find('[data-edit]').on('click', function (e) { e.preventDefault(); rowEditor(connection, table, data.rows[Number(this.dataset.edit)], keyOf(data.rows[Number(this.dataset.edit)]), params); });
        $content().find('#insert-row').on('click', () => rowEditor(connection, table, null, null, params));
        $content().find('[data-del]').on('click', async function (e) {
            e.preventDefault();
            const row = data.rows[Number(this.dataset.del)];
            if (!await confirmDialog(`Delete row <code>${esc(JSON.stringify(keyOf(row)))}</code> from <b>${esc(table.name)}</b>?`, 'Delete')) return;
            try { await api('table.row.delete', { connection, table: table.name, key: keyOf(row) }); toast('Row deleted'); browseTab(connection, table, params); } catch (err) { fail(err); }
        });
    }

    function rowEditor(connection, table, row, key, params) {
        const fields = Object.values(table.columns).filter((c) => !c.generated).map((c) => {
            const value = row ? row[c.name] : null;
            const isNull = row ? value === null : (c.nullable && c.default === null);
            const strValue = value === null || value === undefined ? '' : (typeof value === 'object' ? JSON.stringify(value) : String(value));
            const binary = typeof value === 'string' && value.startsWith('[BINARY ');
            const control = c.enumValues && c.enumValues.length
                ? `<select class="form-select form-select-sm" name="${esc(c.name)}">${c.enumValues.map((v) => `<option ${v === strValue ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select>`
                : c.type === 'text' || c.type === 'json'
                    ? `<textarea class="form-control form-control-sm font-monospace" rows="3" name="${esc(c.name)}">${esc(strValue)}</textarea>`
                    : `<input class="form-control form-control-sm" name="${esc(c.name)}" value="${esc(strValue)}" ${binary ? 'disabled' : ''} placeholder="${row ? '' : esc(c.autoIncrement ? 'auto' : (c.default ?? ''))}">`;
            return `<tr data-col="${esc(c.name)}"><td><b>${esc(c.name)}</b>${c.primary ? ' ' + icon('key', 'text-warning') : ''}<div class="small text-muted">${esc(c.nativeType)}</div></td>
                <td class="text-center">${c.nullable ? `<input type="checkbox" class="form-check-input" data-null ${isNull ? 'checked' : ''}>` : ''}</td>
                <td class="text-center"><input type="checkbox" class="form-check-input" data-include ${row ? '' : (c.autoIncrement || (c.default !== null && c.default !== undefined) || c.nullable ? '' : 'checked')} ${binary ? 'disabled' : ''}></td>
                <td style="min-width:260px">${control}</td></tr>`;
        }).join('');
        const $m = modal({
            title: row ? `Edit row in <b>${esc(table.name)}</b>` : `Insert into <b>${esc(table.name)}</b>`,
            size: 'lg',
            body: `<table class="gr-table w-100"><thead><tr><th>Column</th><th>NULL</th><th>${row ? 'Change' : 'Set'}</th><th>Value</th></tr></thead><tbody>${fields}</tbody></table>
                <p class="gr-help mb-0">Only checked columns are written. Values are sent as typed and bound as parameters.</p>`,
            buttons: [{ label: `${icon('floppy-disk')} ${row ? 'Save' : 'Insert'}`, action: async ($modal) => {
                const values = {};
                $modal.find('tr[data-col]').each(function () {
                    const $r = $(this);
                    if (!$r.find('[data-include]').is(':checked')) return;
                    values[this.dataset.col] = $r.find('[data-null]').is(':checked') ? null : $r.find('[name]').val();
                });
                await api('table.row.save', { connection, table: table.name, key, values });
                toast(row ? 'Row updated' : 'Row inserted');
                browseTab(connection, table, params);
            } }],
        });
        $m.find('tr[data-col] [name]').on('input change', function () { $(this).closest('tr').find('[data-include]').prop('checked', true).end().find('[data-null]').prop('checked', false); });
    }

    // ----------------------------------------------------------- structure
    function structureTab(connection, info) {
        const t = info.table;
        const fkOf = (col) => t.foreignKeys.find((f) => f.columns.includes(col));
        $content().html(panel(`${icon('sitemap')} Structure of <b>${esc(t.name)}</b> ${badge(t.type, t.type === 'view' ? 'warning' : 'secondary')}`, `<table class="gr-table w-100"><thead><tr><th>#</th><th>Name</th><th>Type</th><th>API type</th><th>Null</th><th>Default</th><th>Key</th><th>Extra</th><th>Comment</th></tr></thead><tbody>
            ${t.columns.map((c, i) => { const fk = fkOf(c.name); return `<tr><td>${i + 1}</td><td><b>${esc(c.name)}</b></td><td><code>${esc(c.nativeType)}</code></td><td>${badge(c.type, 'light')}${c.enumValues.length ? ` <span class="small text-muted">${esc(c.enumValues.join(', '))}</span>` : ''}</td>
                <td>${c.nullable ? 'Yes' : 'No'}</td><td>${c.default === null ? '<span class="gr-null">None</span>' : `<code>${esc(c.default)}</code>`}</td>
                <td>${c.primary ? `${icon('key', 'text-warning')} PRI` : ''}${fk ? ` ${icon('link', 'text-primary')} <a href="${link('table', connection, fk.referencedTable, 'structure')}">${esc(fk.referencedTable)}.${esc(fk.referencedColumns.join(','))}</a>` : ''}</td>
                <td>${c.autoIncrement ? 'auto_increment' : ''}${c.generated ? ' generated' : ''}</td><td>${esc(c.comment)}</td></tr>`; }).join('')}</tbody></table>`,
            `<button class="btn btn-sm btn-outline-secondary" id="refresh-structure">${icon('rotate')} Refresh</button>`)
            + panel(`${icon('link')} Foreign keys`, t.foreignKeys.length ? `<table class="gr-table w-100"><thead><tr><th>Name</th><th>Columns</th><th>References</th></tr></thead><tbody>
                ${t.foreignKeys.map((f) => `<tr><td>${esc(f.name)}</td><td>${esc(f.columns.join(', '))}</td><td><a href="${link('table', connection, f.referencedTable, 'structure')}">${esc(f.referencedTable)}</a> (${esc(f.referencedColumns.join(', '))})</td></tr>`).join('')}</tbody></table>` : '<p class="text-muted small mb-0">None</p>')
            + panel(`${icon('arrow-right-to-bracket')} Referenced by`, info.referencedBy.length ? `<table class="gr-table w-100"><thead><tr><th>Table</th><th>Columns</th><th>References</th></tr></thead><tbody>
                ${info.referencedBy.map((f) => `<tr><td><a href="${link('table', connection, f.table, 'structure')}">${esc(f.table)}</a></td><td>${esc(f.columns.join(', '))}</td><td>${esc(f.referencedColumns.join(', '))}</td></tr>`).join('')}</tbody></table>` : '<p class="text-muted small mb-0">None</p>'));
        $('#refresh-structure').on('click', async () => {
            try { await api('schema.refresh', { connection }); state.structure = {}; await loadTables(connection, true); route(); } catch (err) { fail(err); }
        });
    }

    // ----------------------------------------------------------- resource editor
    const HIDDEN_TYPES = ['hidden', 'invisible', 'password'];
    const NOT_WRITABLE_TYPES = ['readonly', 'invisible', 'hidden'];

    function normalizeDefinition(d) {
        const lists = ['columns', 'unsetColumns', 'readFields', 'unsetReadFields', 'fields', 'addFields', 'editFields', 'unsetFields', 'unsetAddFields', 'unsetEditFields', 'requiredFields', 'uniqueFields', 'searchable', 'relations', 'uploads', 'where', 'orderBy'];
        const def = { ...d };
        lists.forEach((k) => { def[k] = Array.isArray(def[k]) ? [...def[k]] : []; });
        ['displayAs', 'fieldTypes', 'rules', 'primaryKeys'].forEach((k) => { def[k] = def[k] && !Array.isArray(def[k]) ? { ...def[k] } : {}; });
        def.operations = { list: true, read: true, create: true, update: true, delete: true, clone: false, export: true, ...(def.operations || {}) };
        return def;
    }

    /** Port of Resource::writableFields() so the grid shows the effective state. */
    function writable(def, table, mode) {
        const specific = mode === 'create' ? def.addFields : def.editFields;
        const explicit = specific.length ? specific : def.fields;
        const unset = [...def.unsetFields, ...(mode === 'create' ? def.unsetAddFields : def.unsetEditFields)];
        const pk = def.primaryKey || (table.primaryKey.length === 1 ? table.primaryKey[0] : null);
        const cols = Object.fromEntries(table.columns.map((c) => [c.name, c]));
        let candidates;
        if (explicit.length) candidates = explicit;
        else {
            candidates = table.columns.filter((c) => !c.generated && !(c.name === pk && (mode === 'update' || c.autoIncrement))).map((c) => c.name);
            def.relations.filter((r) => r.type === 'many_to_many').forEach((r) => candidates.push(r.name));
        }
        return candidates.filter((f) => {
            if (unset.includes(f)) return false;
            if (NOT_WRITABLE_TYPES.includes((def.fieldTypes[f] || {}).type)) return false;
            const isM2m = def.relations.some((r) => r.name === f && r.type === 'many_to_many');
            return isM2m || (cols[f] && !cols[f].generated);
        });
    }

    function visibleList(def, table, base, unset) {
        const names = table.columns.map((c) => c.name);
        const list = base.length ? base.filter((c) => names.includes(c)) : names;
        return list.filter((c) => !unset.includes(c) && !HIDDEN_TYPES.includes((def.fieldTypes[c] || {}).type));
    }

    async function resourceTab(connection, table, resources, resourceName) {
        const chosen = resourceName || (resources.find((r) => r.name === table.name) || resources[0] || {}).name;
        let loaded;
        if (chosen) {
            loaded = await api('resource.get', { name: chosen });
        }
        const $c = $content();
        if (!loaded) {
            $c.html(`<div class="gr-empty"><p class="mb-2">${icon('plug-circle-xmark', 'fs-3 text-secondary')}</p>
                <p><b>${esc(table.name)}</b> is not exposed through the API.</p>
                <button class="btn btn-sm btn-primary" id="expose-now">${icon('plug')} Expose with defaults</button>
                <button class="btn btn-sm btn-outline-primary" id="create-resource">${icon('wand-magic-sparkles')} Configure before exposing</button>
                <p class="gr-help mt-2 mb-0">Both generate the definition from the schema (relations from foreign keys) and store it in SQLite.</p></div>`);
            $('#expose-now').on('click', async () => {
                try { const saved = await exposeTable(connection, table.name); window.location.hash = link('table', connection, table.name, 'api', saved.name); } catch (err) { fail(err); }
            });
            $('#create-resource').on('click', async () => {
                try {
                    const def = await api('resource.generate', { connection, table: table.name });
                    resourceEditor(connection, table, { definition: def, source: 'new', enabled: true, readOnly: false, problems: [] }, resources);
                } catch (err) { fail(err); }
            });
            return;
        }
        resourceEditor(connection, table, loaded, resources);
    }

    function resourceEditor(connection, table, loaded, resources) {
        $content().off();
        const def = normalizeDefinition(loaded.definition);
        const ro = loaded.readOnly;
        const original = loaded.source === 'gui' ? def.name : null;
        const ft = state.boot.fieldTypes;
        const columns = table.columns;
        const relations = () => def.relations;
        const dis = ro ? 'disabled' : '';
        const cols = columns.map((c) => c.name);

        const listSet = visibleList(def, table, def.columns, def.unsetColumns);
        const readSet = visibleList(def, table, def.readFields, def.unsetReadFields);
        const createSet = writable(def, table, 'create');
        const updateSet = writable(def, table, 'update');
        const searchDefault = listSet.filter((c) => ['string', 'text', 'enum', 'uuid'].includes(columns.find((x) => x.name === c).type));
        const searchSet = def.searchable.length ? def.searchable : searchDefault;

        const fieldRow = (name, column, relation) => {
            const t = def.fieldTypes[name] || {};
            const extras = Array.isArray(t.extras) ? t.extras.join(', ') : (t.extras && typeof t.extras === 'object' ? Object.keys(t.extras).join(', ') : (t.extras ?? ''));
            const isM2m = relation && relation.type === 'many_to_many';
            const chk = (key, checked, enabled = true) => `<td class="text-center"><input type="checkbox" class="form-check-input" data-k="${key}" ${checked ? 'checked' : ''} ${enabled && !ro ? '' : 'disabled'}></td>`;
            return `<tr data-field="${esc(name)}" class="${relation ? 'gr-virtual' : ''}"><td>${esc(name)}${column && column.primary ? ' ' + icon('key', 'text-warning') : ''}</td>
                <td class="small text-muted">${column ? esc(column.nativeType) : esc(relation.type.replace(/_/g, ' '))}</td>
                <td><input class="form-control form-control-sm" data-k="label" value="${esc(def.displayAs[name] || '')}" placeholder="${esc(name)}" ${dis}></td>
                ${chk('list', relation ? def.columns.includes(name) : listSet.includes(name))}
                ${chk('read', relation ? def.readFields.includes(name) : readSet.includes(name))}
                ${chk('create', createSet.includes(name), !relation || isM2m)}
                ${chk('update', updateSet.includes(name), !relation || isM2m)}
                ${chk('required', def.requiredFields.includes(name), !relation || isM2m)}
                ${chk('unique', def.uniqueFields.includes(name), !relation)}
                ${chk('search', !relation && searchSet.includes(name), !relation)}
                <td>${relation ? '' : `<select class="form-select form-select-sm" data-k="type" ${dis}><option value="">auto (${esc(column.type)})</option>${ft.map((x) => `<option ${t.type === x ? 'selected' : ''}>${esc(x)}</option>`).join('')}</select>`}</td>
                <td>${relation ? '' : `<input class="form-control form-control-sm" data-k="extras" value="${esc(extras)}" placeholder="values / forced value" ${dis}>`}</td>
                <td><input class="form-control form-control-sm font-monospace" data-k="rules" value="${esc((def.rules[name] || {}).rules || '')}" placeholder="required|max_length[50]" ${dis}></td></tr>`;
        };

        const relationRows = () => relations().map((r, i) => `<tr><td><b>${esc(r.name)}</b></td><td>${badge(r.type.replace(/_/g, ' '), r.type === 'belongs_to' ? 'primary' : r.type === 'has_many' ? 'success' : 'warning')}</td>
            <td>${r.type === 'belongs_to' ? `<code>${esc(r.field)}</code> → ` : r.type === 'has_many' ? `← <code>${esc(r.foreignKey)}</code> of ` : `via <code>${esc(r.junctionTable)}</code> → `}<a href="${link('table', connection, r.table, 'api')}">${esc(r.table)}</a></td>
            <td><code>${esc(r.titleField || '')}</code></td><td class="small">${r.priorityField ? 'priority: ' + esc(r.priorityField) + ' ' : ''}${r.orderBy ? 'order: ' + esc(r.orderBy.join(' ')) + ' ' : ''}${(r.where || []).length ? 'where: ' + (r.where.length) : ''}</td>
            <td class="gr-actions-cell">${ro ? '' : `<a href="#" data-rel-edit="${i}">${icon('pen')} Edit</a><a href="#" class="text-danger" data-rel-del="${i}">${icon('trash')}</a>`}</td></tr>`).join('') || '<tr><td colspan="6" class="text-center text-muted">No relations</td></tr>';

        const uploadRows = () => def.uploads.map((u, i) => `<tr data-upload="${i}"><td><select class="form-select form-select-sm" data-k="field" ${dis}>${cols.map((c) => `<option ${u.field === c ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select></td>
            <td><input class="form-control form-control-sm" data-k="path" value="${esc(u.path || '')}" placeholder="relative to the upload path" ${dis}></td>
            <td><input class="form-control form-control-sm" data-k="allowedTypes" value="${esc(u.allowedTypes || '')}" placeholder="jpg|png|pdf" ${dis}></td>
            <td><input class="form-control form-control-sm" data-k="maxSize" value="${esc(u.maxSize || '')}" placeholder="5MB" ${dis}></td>
            <td>${ro ? '' : `<a href="#" class="text-danger" data-upload-del="${i}">${icon('trash')}</a>`}</td></tr>`).join('') || '<tr><td colspan="5" class="text-center text-muted">No upload fields</td></tr>';

        const resourcePicker = resources.length > 1 || (resources.length && loaded.source === 'new')
            ? `<select class="form-select form-select-sm w-auto" id="pick-resource">${resources.map((r) => `<option value="${esc(r.name)}" ${r.name === def.name ? 'selected' : ''}>${esc(r.name)} (${esc(r.source)})</option>`).join('')}${loaded.source === 'new' ? '<option selected>new…</option>' : ''}</select>` : '';

        $content().html(`
            ${loaded.readOnly ? `<div class="alert alert-info py-2 small">${icon('lock', 'me-1')}This resource is <b>defined in code</b>${loaded.codeFeatures && loaded.codeFeatures.actions.length ? ' (actions: ' + esc(loaded.codeFeatures.actions.join(', ')) + ')' : ''}. Edit it in your application; the GUI shows it read-only.</div>` : ''}
            ${loaded.source === 'auto' ? `<div class="alert alert-primary py-2 small">${icon('wand-magic-sparkles', 'me-1')}This resource is <b>auto exposed</b>. Saving stores a customized copy in SQLite.</div>` : ''}
            ${loaded.source === 'new' ? `<div class="alert alert-success py-2 small">${icon('wand-magic-sparkles', 'me-1')}Generated from the schema. Review it and click <b>Save</b> to expose the table.</div>` : ''}
            ${(loaded.problems || []).length ? `<div class="alert alert-warning py-2 small">${icon('triangle-exclamation', 'me-1')}${loaded.problems.map(esc).join('<br>')}</div>` : ''}
            <div class="row g-3">
            <div class="col-xl-7">${panel(`${icon('cube')} General`, `<div class="row g-2">
                <div class="col-md-4"><label class="form-label small mb-0">Endpoint name</label><div class="input-group input-group-sm"><span class="input-group-text">/</span><input class="form-control" id="r-name" value="${esc(def.name)}" ${dis}></div></div>
                <div class="col-md-4"><label class="form-label small mb-0">Subject</label><input class="form-control form-control-sm" id="r-subject" value="${esc(def.subject || '')}" placeholder="${esc(humanize(def.name))}" ${dis}></div>
                <div class="col-md-4"><label class="form-label small mb-0">Subject (plural)</label><input class="form-control form-control-sm" id="r-plural" value="${esc(def.subjectPlural || '')}" placeholder="${esc(humanize(def.name))}" ${dis}></div>
                <div class="col-md-12"><label class="form-label small mb-0">Description</label><input class="form-control form-control-sm" id="r-desc" value="${esc(def.description || '')}" ${dis}></div>
                <div class="col-md-4"><label class="form-label small mb-0">Primary key</label><select class="form-select form-select-sm" id="r-pk" ${dis}><option value="">auto (${esc(table.primaryKey.join(', ') || 'none')})</option>${cols.map((c) => `<option ${def.primaryKey === c ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select></div>
                <div class="col-md-4"><label class="form-label small mb-0">Page size</label><input type="number" min="1" class="form-control form-control-sm" id="r-perpage" value="${esc(def.perPage || '')}" placeholder="default" ${dis}></div>
                <div class="col-md-4"><label class="form-label small mb-0">Max page size</label><input type="number" min="1" class="form-control form-control-sm" id="r-maxlimit" value="${esc(def.maxLimit || '')}" placeholder="default" ${dis}></div>
                <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="r-enabled" ${loaded.enabled ? 'checked' : ''} ${dis}><label class="form-check-label" for="r-enabled">Enabled</label></div></div>
                </div>`, resourcePicker)}</div>
            <div class="col-xl-5">${panel(`${icon('toggle-on')} Operations`, `<div class="gr-check-grid">${state.boot.operations.map((o) => `<label><input type="checkbox" class="form-check-input me-1" data-op="${esc(o)}" ${def.operations[o] ? 'checked' : ''} ${dis}>${esc(o)}</label>`).join('')}</div>
                <p class="gr-help mb-0 mt-1">Grocery CRUD's unset_add / unset_edit / unset_delete / unset_read / unset_export / set_clone.</p>`)}
                ${panel(`${icon('shield-halved')} Permissions`, `<div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="r-defperm" ${def.permissions ? '' : 'checked'} ${dis}><label class="form-check-label" for="r-defperm">Use the default permissions</label></div>
                <div id="r-perms" class="${def.permissions ? '' : 'd-none'}">${permissionGrid(def.permissions || {}, 'resource')}</div>`)}</div>
            </div>
            ${panel(`${icon('table-columns')} Fields`, `<div class="table-responsive"><table class="gr-table w-100 gr-fields-grid"><thead><tr><th>Field</th><th>Type</th><th>Label (display_as)</th><th title="Returned by the list endpoint">List</th><th title="Returned by the single record endpoint">Read</th>
                <th title="Writable on create">Create</th><th title="Writable on update">Update</th><th>Required</th><th>Unique</th><th title="Used by ?q=">Search</th><th>Field type</th><th>Extras</th><th>Rules (CodeIgniter syntax)</th></tr></thead>
                <tbody>${columns.map((c) => fieldRow(c.name, c, null)).join('')}${relations().map((r) => fieldRow(r.name, null, r)).join('')}</tbody></table></div>
                <p class="gr-help mb-0">Checked relation rows in <b>List</b> / <b>Read</b> are embedded by default. Field types: <code>hidden</code> (not returned; extras = forced value), <code>invisible</code>, <code>readonly</code>, <code>password</code> (hashed, never returned), <code>enum</code>/<code>set</code> (extras = allowed values).</p>`)}
            ${panel(`${icon('diagram-project')} Relations`, `<table class="gr-table w-100"><thead><tr><th>Name</th><th>Type</th><th>Link</th><th>Title</th><th>Options</th><th></th></tr></thead><tbody id="rel-rows">${relationRows()}</tbody></table>`,
                ro ? '' : `<div class="dropdown"><button class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">${icon('plus')} Add relation</button><ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="#" data-rel-add="belongs_to">belongs_to (set_relation)</a></li><li><a class="dropdown-item" href="#" data-rel-add="many_to_many">many_to_many (set_relation_n_n)</a></li>
                <li><a class="dropdown-item" href="#" data-rel-add="has_many">has_many (reverse foreign key)</a></li><li><hr class="dropdown-divider"></li><li><a class="dropdown-item" href="#" data-rel-detect>${icon('magnifying-glass')} Detect from foreign keys</a></li></ul></div>`)}
            <div class="row g-3"><div class="col-xl-6">${panel(`${icon('upload')} Upload fields`, `<table class="gr-table w-100"><thead><tr><th>Field</th><th>Directory</th><th>Allowed types</th><th>Max size</th><th></th></tr></thead><tbody id="upload-rows">${uploadRows()}</tbody></table>
                <p class="gr-help mb-0">Endpoints: <code>POST /{resource}/{id}/upload/{field}</code>, <code>GET /{resource}/{id}/file/{field}</code>, or multipart create / update.</p>`,
                ro ? '' : `<button class="btn btn-sm btn-outline-primary" id="upload-add">${icon('plus')} Add</button>`)}</div>
            <div class="col-xl-6">${panel(`${icon('filter')} Default conditions &amp; order`, `<p class="gr-help">Applied to every read, update and delete (Grocery CRUD where / like / order_by).</p>
                <div id="where-editor">${conditionsEditor(def.where, cols, ro)}</div><hr>
                <div id="order-rows">${def.orderBy.map(([c, d]) => orderRow(cols, c, d, ro)).join('')}</div>${ro ? '' : `<button class="btn btn-sm btn-outline-secondary mt-1" id="order-add">${icon('plus')} Order by</button>`}`)}</div></div>
            <div class="gr-sticky-footer">${ro ? `<span class="text-muted small">${icon('lock')} Read only</span>` : `<button class="btn btn-sm btn-primary" id="r-save">${icon('floppy-disk')} Save resource</button>`}
                <button class="btn btn-sm btn-outline-secondary" id="r-json">${icon('file-code')} JSON</button>
                <button class="btn btn-sm btn-outline-secondary" id="r-code">${icon('code')} PHP code</button>
                ${loaded.source === 'gui' ? `<button class="btn btn-sm btn-outline-danger ms-auto" id="r-delete">${icon('trash')} Delete resource</button>` : ''}</div>`);

        const $c = $content();
        bindPermissionGrid($c);
        bindConditionsEditor($c.find('#where-editor'), cols);
        $c.find('#pick-resource').on('change', function () { if (this.value !== 'new…') window.location.hash = link('table', connection, table.name, 'api', this.value); });
        $c.find('#r-defperm').on('change', function () { $c.find('#r-perms').toggleClass('d-none', this.checked); });

        const collect = () => {
            const d = normalizeDefinition(def);
            d.name = $('#r-name').val().trim();
            d.subject = $('#r-subject').val().trim() || null;
            d.subjectPlural = $('#r-plural').val().trim() || null;
            d.description = $('#r-desc').val().trim();
            d.primaryKey = $('#r-pk').val() || null;
            d.perPage = Number($('#r-perpage').val()) || null;
            d.maxLimit = Number($('#r-maxlimit').val()) || null;
            $c.find('[data-op]').each(function () { if (!$(this).closest('.gr-perm-grid').length) d.operations[this.dataset.op] = this.checked; });
            d.permissions = $('#r-defperm').is(':checked') ? null : readPermissionGrid($c.find('#r-perms .gr-perm-grid'));

            const checked = { list: [], read: [], create: [], update: [], required: [], unique: [], search: [] };
            const relList = [];
            const relRead = [];
            d.displayAs = {};
            d.fieldTypes = {};
            d.rules = {};
            $c.find('.gr-fields-grid tr[data-field]').each(function () {
                const $r = $(this);
                const name = this.dataset.field;
                const isRel = $r.hasClass('gr-virtual');
                Object.keys(checked).forEach((k) => {
                    if ($r.find(`[data-k=${k}]`).is(':checked')) {
                        if (isRel && k === 'list') relList.push(name);
                        else if (isRel && k === 'read') relRead.push(name);
                        else checked[k].push(name);
                    }
                });
                const label = $r.find('[data-k=label]').val().trim();
                if (label) d.displayAs[name] = label;
                const type = $r.find('[data-k=type]').val();
                if (type) {
                    const raw = ($r.find('[data-k=extras]').val() || '').trim();
                    let extras = null;
                    if (raw !== '') extras = ['enum', 'dropdown', 'set', 'multiselect'].includes(type) ? raw.split(',').map((s) => s.trim()).filter(Boolean) : raw;
                    d.fieldTypes[name] = { type, extras };
                }
                const rules = ($r.find('[data-k=rules]').val() || '').trim();
                if (rules) d.rules[name] = { rules, label: null };
            });

            // Store explicit lists only when they differ from the defaults (keeps definitions future proof).
            const blank = normalizeDefinition({ ...d, columns: [], unsetColumns: [], readFields: [], unsetReadFields: [], fields: [], addFields: [], editFields: [], unsetFields: [], unsetAddFields: [], unsetEditFields: [] });
            const defaults = {
                list: visibleList(blank, table, [], []), read: visibleList(blank, table, [], []),
                create: writable(blank, table, 'create'), update: writable(blank, table, 'update'),
            };
            ['unsetColumns', 'unsetReadFields', 'fields', 'unsetFields', 'unsetAddFields', 'unsetEditFields'].forEach((k) => { d[k] = []; });
            d.columns = sameSet(checked.list, defaults.list) && !relList.length ? [] : [...checked.list, ...relList];
            d.readFields = sameSet(checked.read, defaults.read) && !relRead.length ? [] : [...checked.read, ...relRead];
            d.addFields = sameSet(checked.create, defaults.create) ? [] : checked.create;
            d.editFields = sameSet(checked.update, defaults.update) ? [] : checked.update;
            if (d.addFields.length === 0 && !sameSet(checked.create, defaults.create)) d.operations.create = false;
            d.requiredFields = checked.required;
            d.uniqueFields = checked.unique;
            d.searchable = sameSet(checked.search, searchDefault) ? [] : checked.search;

            d.uploads = [];
            $c.find('#upload-rows tr[data-upload]').each(function () {
                const $r = $(this);
                d.uploads.push({ field: $r.find('[data-k=field]').val(), path: $r.find('[data-k=path]').val().trim(), allowedTypes: $r.find('[data-k=allowedTypes]').val().trim() || null, maxSize: $r.find('[data-k=maxSize]').val().trim() || null });
            });
            d.where = readConditions($c.find('#where-editor'));
            d.orderBy = $c.find('#order-rows .gr-order-row').map(function () { return [[$(this).find('[data-k=column]').val(), $(this).find('[data-k=dir]').val()]]; }).get();
            return d;
        };

        const syncFromForm = () => { Object.assign(def, collect()); };

        $c.find('#upload-add').on('click', () => { syncFromForm(); def.uploads.push({ field: cols[0], path: def.table, allowedTypes: null, maxSize: null }); $c.find('#upload-rows').html(uploadRows()); });
        $c.on('click', '[data-upload-del]', function (e) { e.preventDefault(); syncFromForm(); def.uploads.splice(Number(this.dataset.uploadDel), 1); $c.find('#upload-rows').html(uploadRows()); });
        $c.find('#order-add').on('click', () => $c.find('#order-rows').append(orderRow(cols, cols[0], 'asc', false)));
        $c.on('click', '[data-order-del]', function (e) { e.preventDefault(); $(this).closest('.gr-order-row').remove(); });

        const rerender = () => { syncFromForm(); resourceEditor(connection, table, { ...loaded, definition: def }, resources); };
        $c.find('[data-rel-add]').on('click', function (e) { e.preventDefault(); relationEditor(connection, table, def, null, this.dataset.relAdd, rerender, syncFromForm); });
        $c.on('click', '[data-rel-edit]', function (e) { e.preventDefault(); const r = def.relations[Number(this.dataset.relEdit)]; relationEditor(connection, table, def, r, r.type, rerender, syncFromForm); });
        $c.on('click', '[data-rel-del]', function (e) { e.preventDefault(); syncFromForm(); def.relations.splice(Number(this.dataset.relDel), 1); rerender(); });
        $c.find('[data-rel-detect]').on('click', async (e) => {
            e.preventDefault();
            syncFromForm();
            try {
                const generated = await api('resource.generate', { connection, table: table.name });
                let added = 0;
                generated.relations.forEach((r) => { if (!def.relations.some((x) => x.name === r.name)) { def.relations.push(r); added++; } });
                toast(added ? `${added} relation(s) added` : 'No new relations found', added ? 'success' : 'info');
                rerender();
            } catch (err) { fail(err); }
        });

        $c.find('#r-json').on('click', () => modal({ title: 'Resource definition (JSON)', size: 'lg', body: codeBlock(JSON.stringify(collect(), null, 2), 'json'),
            buttons: [{ label: `${icon('copy')} Copy`, cls: 'btn-outline-primary', action: () => { copy(JSON.stringify(collect(), null, 2)); return false; } }] }));
        $c.find('#r-code').on('click', async () => {
            try {
                const r = await api('resource.code', { definition: collect() });
                modal({ title: 'Equivalent PHP code', size: 'lg', body: codeBlock('<?php\n\n' + r.code, 'php'), buttons: [{ label: `${icon('copy')} Copy`, cls: 'btn-outline-primary', action: () => { copy(r.code); return false; } }] });
            } catch (err) { fail(err); }
        });
        $c.find('#r-save').on('click', async () => {
            const d = collect();
            if (!/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/.test(d.name)) { toast('Invalid endpoint name', 'warning'); return; }
            try {
                const r = await api('resource.save', { definition: d, original: original || d.name, enabled: $('#r-enabled').is(':checked') });
                toast('Resource saved');
                await loadTables(connection, true);
                state.boot = await loadBoot();
                const target = link('table', connection, table.name, 'api', r.name);
                if (window.location.hash === target) route(); else window.location.hash = target;
            } catch (err) { fail(err); }
        });
        $c.find('#r-delete').on('click', async () => {
            if (!await confirmDialog(`Delete resource <b>${esc(def.name)}</b>? The table is not touched.`, 'Delete')) return;
            try { await api('resource.delete', { name: original }); toast('Resource deleted'); await loadTables(connection, true); window.location.hash = link('table', connection, table.name, 'api'); route(); } catch (err) { fail(err); }
        });
    }

    const OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'in', 'not in', 'is null', 'is not null', 'like', 'not like'];

    function conditionRow(cols, c, ro) {
        const value = Array.isArray(c.value) ? c.value.join(', ') : (c.value ?? '');
        return `<div class="gr-cond-row d-flex gap-1 mb-1"><select class="form-select form-select-sm w-auto" data-k="boolean" ${ro ? 'disabled' : ''}><option value="and" ${c.boolean !== 'or' ? 'selected' : ''}>AND</option><option value="or" ${c.boolean === 'or' ? 'selected' : ''}>OR</option></select>
            <select class="form-select form-select-sm" data-k="column" ${ro ? 'disabled' : ''}>${cols.map((x) => `<option ${x === c.column ? 'selected' : ''}>${esc(x)}</option>`).join('')}</select>
            <select class="form-select form-select-sm w-auto" data-k="operator" ${ro ? 'disabled' : ''}>${OPERATORS.map((o) => `<option ${o === (c.operator || '=') ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select>
            <input class="form-control form-control-sm" data-k="value" value="${esc(value)}" placeholder="value (in: a, b, c · like: %text%)" ${ro ? 'disabled' : ''}>
            ${ro ? '' : `<button type="button" class="btn btn-sm btn-outline-danger" data-cond-del>${icon('xmark')}</button>`}</div>`;
    }

    function conditionsEditor(conditions, cols, ro) {
        return `<div class="gr-conds">${(conditions || []).map((c) => conditionRow(cols, c, ro)).join('')}</div>${ro ? '' : `<button type="button" class="btn btn-sm btn-outline-secondary" data-cond-add>${icon('plus')} Condition</button>`}`;
    }

    function bindConditionsEditor($scope, cols) {
        $scope.on('click', '[data-cond-add]', () => $scope.find('.gr-conds').append(conditionRow(cols, { column: cols[0], operator: '=' }, false)));
        $scope.on('click', '[data-cond-del]', function () { $(this).closest('.gr-cond-row').remove(); });
    }

    function readConditions($scope) {
        return $scope.find('.gr-cond-row').map(function () {
            const $r = $(this);
            const operator = $r.find('[data-k=operator]').val();
            let value = $r.find('[data-k=value]').val();
            if (['in', 'not in'].includes(operator)) value = value.split(',').map((s) => s.trim()).filter((s) => s !== '');
            if (['is null', 'is not null'].includes(operator)) value = null;
            return { boolean: $r.find('[data-k=boolean]').val(), column: $r.find('[data-k=column]').val(), operator, value };
        }).get();
    }

    const orderRow = (cols, column, dir, ro) => `<div class="gr-order-row d-flex gap-1 mb-1"><span class="small text-muted align-self-center" style="width:70px">ORDER BY</span>
        <select class="form-select form-select-sm" data-k="column" ${ro ? 'disabled' : ''}>${cols.map((x) => `<option ${x === column ? 'selected' : ''}>${esc(x)}</option>`).join('')}</select>
        <select class="form-select form-select-sm w-auto" data-k="dir" ${ro ? 'disabled' : ''}><option ${dir === 'asc' ? 'selected' : ''}>asc</option><option ${dir === 'desc' ? 'selected' : ''}>desc</option></select>
        ${ro ? '' : `<button type="button" class="btn btn-sm btn-outline-danger" data-order-del>${icon('xmark')}</button>`}</div>`;

    async function relationEditor(connection, table, def, existing, type, done, sync) {
        sync();
        const tables = (await loadTables(connection)).map((t) => t.name);
        const r = existing ? { ...existing } : { type, name: '', table: '' };
        const cols = table.columns.map((c) => c.name);
        const opt = (list, selected, empty = '') => `${empty !== null ? `<option value="">${esc(empty)}</option>` : ''}${list.map((x) => `<option ${x === selected ? 'selected' : ''}>${esc(x)}</option>`).join('')}`;
        const titles = { belongs_to: 'belongs_to — set_relation()', many_to_many: 'many_to_many — set_relation_n_n()', has_many: 'has_many — reverse foreign key' };

        const fields = {
            belongs_to: `<div class="col-md-6"><label class="form-label small mb-0">Local field (foreign key)</label><select class="form-select form-select-sm" name="field">${opt(cols, r.field, null)}</select></div>
                <div class="col-md-6"><label class="form-label small mb-0">Related table</label><select class="form-select form-select-sm" name="table" data-load-cols>${opt(tables, r.table, '— choose —')}</select></div>
                <div class="col-md-6"><label class="form-label small mb-0">Referenced column</label><select class="form-select form-select-sm" name="ownerKey" data-cols="target">${opt([], r.ownerKey, 'primary key')}</select></div>`,
            has_many: `<div class="col-md-6"><label class="form-label small mb-0">Child table</label><select class="form-select form-select-sm" name="table" data-load-cols>${opt(tables, r.table, '— choose —')}</select></div>
                <div class="col-md-6"><label class="form-label small mb-0">Foreign key in the child table</label><select class="form-select form-select-sm" name="foreignKey" data-cols="target">${opt([], r.foreignKey, '— choose —')}</select></div>
                <div class="col-md-6"><label class="form-label small mb-0">Local key</label><select class="form-select form-select-sm" name="ownerKey">${opt(cols, r.ownerKey, 'primary key')}</select></div>`,
            many_to_many: `<div class="col-md-6"><label class="form-label small mb-0">Junction table</label><select class="form-select form-select-sm" name="junctionTable" data-load-junction>${opt(tables, r.junctionTable, '— choose —')}</select></div>
                <div class="col-md-6"><label class="form-label small mb-0">Selection table</label><select class="form-select form-select-sm" name="table" data-load-cols>${opt(tables, r.table, '— choose —')}</select></div>
                <div class="col-md-4"><label class="form-label small mb-0">Junction → this table</label><select class="form-select form-select-sm" name="junctionThisKey" data-cols="junction">${opt([], r.junctionThisKey, '— choose —')}</select></div>
                <div class="col-md-4"><label class="form-label small mb-0">Junction → selection</label><select class="form-select form-select-sm" name="junctionOtherKey" data-cols="junction">${opt([], r.junctionOtherKey, '— choose —')}</select></div>
                <div class="col-md-4"><label class="form-label small mb-0">Priority field (junction)</label><select class="form-select form-select-sm" name="priorityField" data-cols="junction">${opt([], r.priorityField, 'none')}</select></div>`,
        }[type];

        const $m = modal({
            title: `${existing ? 'Edit' : 'Add'} relation <span class="text-muted small">${esc(titles[type])}</span>`,
            size: 'lg',
            body: `<div class="row g-2" id="rel-form">
                <div class="col-md-6"><label class="form-label small mb-0">Relation name (used in select=name(*)${type === 'many_to_many' ? ' and as body key' : ''})</label><input class="form-control form-control-sm" name="name" value="${esc(r.name)}"></div>
                <div class="col-md-6"><label class="form-label small mb-0">Title field / template</label><div class="input-group input-group-sm"><input class="form-control" name="titleField" value="${esc(r.titleField || '')}" placeholder="name or {lastName} {firstName}">
                    <select class="form-select" style="max-width:130px" data-cols="target" data-insert-title>${opt([], null, '+ column')}</select></div></div>
                ${fields}
                ${type !== 'many_to_many' ? `<div class="col-md-6"><label class="form-label small mb-0">Order related rows by</label><div class="input-group input-group-sm"><select class="form-select" name="orderCol" data-cols="target">${opt([], r.orderBy ? r.orderBy[0] : null, 'default')}</select>
                    <select class="form-select" name="orderDir" style="max-width:90px"><option ${r.orderBy && r.orderBy[1] === 'desc' ? '' : 'selected'}>asc</option><option ${r.orderBy && r.orderBy[1] === 'desc' ? 'selected' : ''}>desc</option></select></div></div>` : ''}
                <div class="col-12"><label class="form-label small mb-0">Conditions on the related rows</label><div id="rel-where"></div></div>
                </div>`,
            buttons: [{ label: `${icon('check')} Apply`, action: ($modal) => {
                const v = formValues($modal.find('#rel-form'));
                const rel = { type, name: (v.name || '').trim(), table: v.table, titleField: v.titleField || null };
                if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(rel.name)) { toast('Invalid relation name', 'warning'); return false; }
                if (!rel.table) { toast('Choose the related table', 'warning'); return false; }
                if (type === 'belongs_to') Object.assign(rel, { field: v.field, ownerKey: v.ownerKey || null });
                if (type === 'has_many') Object.assign(rel, { foreignKey: v.foreignKey, ownerKey: v.ownerKey || null });
                if (type === 'many_to_many') Object.assign(rel, { junctionTable: v.junctionTable, junctionThisKey: v.junctionThisKey, junctionOtherKey: v.junctionOtherKey, priorityField: v.priorityField || null });
                if (v.orderCol) rel.orderBy = [v.orderCol, v.orderDir];
                const where = readConditions($modal.find('#rel-where'));
                if (where.length) rel.where = where;
                if ((type === 'has_many' && !rel.foreignKey) || (type === 'many_to_many' && (!rel.junctionTable || !rel.junctionThisKey || !rel.junctionOtherKey))) { toast('Fill in the keys', 'warning'); return false; }
                if (cols.includes(rel.name)) { toast('The relation name collides with a column name', 'warning'); return false; }
                const index = existing ? def.relations.indexOf(existing) : -1;
                if (def.relations.some((x, i) => x.name === rel.name && i !== index)) { toast('Duplicate relation name', 'warning'); return false; }
                if (index >= 0) def.relations[index] = rel; else def.relations.push(rel);
                done();
            } }],
        });

        const fillCols = async (selector, tableName, selected) => {
            if (!tableName) return [];
            const s = await structure(connection, tableName);
            const names = s.table.columns.map((c) => c.name);
            $m.find(`[data-cols=${selector}]`).each(function () {
                const $sel = $(this);
                const keep = $sel.find('option').first().prop('outerHTML');
                const current = $sel.attr('name') ? (r[$sel.attr('name')] ?? ($sel.attr('name') === 'orderCol' && r.orderBy ? r.orderBy[0] : null)) : null;
                $sel.html(keep + names.map((n) => `<option ${n === (selected || current) ? 'selected' : ''}>${esc(n)}</option>`).join(''));
            });
            return names;
        };
        const loadTarget = async () => {
            const names = await fillCols('target', $m.find('[name=table]').val());
            const $where = $m.find('#rel-where');
            $where.off().html(conditionsEditor(r.where || [], names, false));
            bindConditionsEditor($where, names);
            if (!$m.find('[name=name]').val() && $m.find('[name=table]').val()) $m.find('[name=name]').val($m.find('[name=table]').val().replace(/[^A-Za-z0-9_]/g, '_'));
        };
        $m.find('[data-load-cols]').on('change', loadTarget);
        $m.find('[data-load-junction]').on('change', () => fillCols('junction', $m.find('[name=junctionTable]').val()));
        $m.find('[data-insert-title]').on('change', function () {
            if (!this.value) return;
            const $t = $m.find('[name=titleField]');
            $t.val($t.val() ? `${$t.val()} {${this.value}}` : this.value);
            this.selectedIndex = 0;
        });
        if (r.table) loadTarget(); else { const $w = $m.find('#rel-where'); $w.html(conditionsEditor([], [], false)); }
        if (r.junctionTable) fillCols('junction', r.junctionTable);
    }

    // ----------------------------------------------------------- code & try
    async function currentDefinition(connection, table, resources, resourceName) {
        const name = resourceName || (resources.find((r) => r.name === table.name) || resources[0] || {}).name;
        if (!name) return null;
        return (await api('resource.get', { name })).definition;
    }

    async function codeTab(connection, table, resources, resourceName) {
        let def = await currentDefinition(connection, table, resources, resourceName);
        const generated = !def;
        if (!def) def = await api('resource.generate', { connection, table: table.name });
        const r = await api('resource.code', { definition: def });
        const indented = r.code.split('\n').map((l) => (l ? '        ' + l : l)).join('\n');
        const configExample = `<?php

namespace Config;

use GroceryRestable\\CodeIgniter4\\Config\\GroceryRestable as BaseGroceryRestable;

class GroceryRestable extends BaseGroceryRestable
{
    public function define(\\GroceryRestable\\GroceryRestable $api): void
    {
${indented}    }
}`;
        const className = (def.name || 'resource').replace(/(^|[_-])(\w)/g, (m, sep, ch) => ch.toUpperCase());
        const controllerExample = `<?php

// app/Config/Routes.php:
//   \\GroceryRestable\\CodeIgniter4\\Routes::resource($routes, 'crud/${def.name}', '${className}::index');

namespace App\\Controllers;

use GroceryRestable\\CodeIgniter4\\Libraries\\GroceryRestable;

class ${className} extends BaseController
{
    public function index(string ...$segments)
    {
        $crud = new GroceryRestable();
${r.code.replace(/^\$api->resource\(([^)]*)\)/, (m, args) => `        $crud->setTable(${args.split(',').pop().trim()})`).split('\n').map((l, i) => (i === 0 ? l : '        ' + l)).join('\n')}
        return $crud->render($segments);
    }
}`;
        $content().html((generated ? `<div class="alert alert-info py-2 small">${icon('circle-info', 'me-1')}This table has no resource yet; the code below is generated from the schema.</div>` : '')
            + panel(`${icon('code')} Fluent definition`, codeBlock('<?php\n\n' + r.code, 'php'), `<button class="btn btn-sm btn-outline-secondary" data-copy-code="0">${icon('copy')} Copy</button>`)
            + panel(`${icon('fire')} CodeIgniter 4 — central definition <span class="text-muted fw-normal small">app/Config/GroceryRestable.php</span>`, codeBlock(configExample, 'php'), `<button class="btn btn-sm btn-outline-secondary" data-copy-code="1">${icon('copy')} Copy</button>`)
            + panel(`${icon('fire')} CodeIgniter 4 — Grocery CRUD style controller`, codeBlock(controllerExample, 'php'), `<button class="btn btn-sm btn-outline-secondary" data-copy-code="2">${icon('copy')} Copy</button>`));
        const blocks = [r.code, configExample, controllerExample];
        $content().find('[data-copy-code]').on('click', function () { copy(blocks[Number(this.dataset.copyCode)]); });
    }

    async function tryTab(connection, table, resources, resourceName) {
        const def = await currentDefinition(connection, table, resources, resourceName);
        if (!def) {
            $content().html(`<div class="gr-empty">Expose <b>${esc(table.name)}</b> first (API resource tab).</div>`);
            return;
        }
        const name = def.name;
        const pk = def.primaryKey || table.primaryKey[0] || 'id';
        const rel = (def.relations || [])[0];
        const firstCol = table.columns.find((c) => !c.primary) || table.columns[0];
        const sampleBody = {};
        table.columns.filter((c) => !c.autoIncrement && !c.generated).slice(0, 4).forEach((c) => { sampleBody[c.name] = c.type === 'integer' ? 1 : c.type === 'number' ? 1.5 : c.type === 'boolean' ? true : 'value'; });
        const examples = [
            ['GET', `${name}?limit=5`, 'First 5 rows'],
            ['GET', `${name}?select=*&order=${pk}.desc&limit=5&count=exact`, 'Latest rows with total count'],
            ...(rel ? [['GET', `${name}?select=*,${rel.name}(*)&limit=5`, `Embed relation "${rel.name}"`]] : []),
            ['GET', `${name}?${firstCol.name}=not.is.null&limit=5`, 'Filter'],
            ['GET', `${name}?q=a&limit=5`, 'Search'],
            ['GET', `${name}/_schema`, 'Field metadata'],
            ['POST', name, 'Create', JSON.stringify(sampleBody, null, 2)],
            ['PATCH', `${name}/1`, 'Update record 1', JSON.stringify({ [firstCol.name]: 'new value' }, null, 2)],
            ['DELETE', `${name}/1`, 'Delete record 1'],
        ];
        $content().html(panel(`${icon('flask')} Try <b>/${esc(name)}</b>`, `<table class="gr-table w-100"><tbody>${examples.map(([m, p, label, body], i) => `<tr><td><span class="gr-method gr-method-${m.toLowerCase()}">${m}</span></td><td><code>${esc(CFG.apiUrl)}/${esc(p)}</code></td><td class="small text-muted">${esc(label)}</td>
            <td class="gr-actions-cell"><a href="#/console?${new URLSearchParams({ method: m, path: p, ...(body ? { body } : {}), ...(m === 'GET' ? { run: 1 } : {}) })}">${icon('play')} Open in console</a><a href="#" data-curl="${i}">${icon('copy')} cURL</a></td></tr>`).join('')}</tbody></table>
            <p class="gr-help mb-0">Write examples open in the console without running, so you can review the body first.</p>`));
        $content().find('[data-curl]').on('click', function (e) {
            e.preventDefault();
            const [m, p, , body] = examples[Number(this.dataset.curl)];
            copy(`curl -X ${m} '${absoluteApiUrl()}/${p}' \\\n  -H 'apikey: <YOUR_KEY>'${body ? ` \\\n  -H 'Content-Type: application/json' \\\n  -d '${body.replace(/\n\s*/g, ' ')}'` : ''}`);
        });
    }

    // =====================================================================
    // Boot
    // =====================================================================

    /** Highlight every code block added to the page (content, modals) with highlight.js. */
    function startHighlighting() {
        if (!window.hljs) return; // highlighting is optional
        const run = () => document.querySelectorAll('pre.gr-code > code:not([data-highlighted])').forEach((el) => window.hljs.highlightElement(el));
        let scheduled = false;
        new MutationObserver(() => {
            if (scheduled) return;
            scheduled = true;
            requestAnimationFrame(() => { scheduled = false; run(); });
        }).observe(document.body, { childList: true, subtree: true });
        run();
    }

    async function bootApp() {
        $(document).on('click', '[data-copy]', function (e) { e.preventDefault(); copy(this.dataset.copy); });
        $(document).on('click', '[data-action=logout]', (e) => { e.preventDefault(); $('#gr-logout-form').trigger('submit'); });
        $(document).on('click', '[data-action=reload-tree]', async (e) => {
            e.preventDefault();
            try { await api('schema.refresh'); await refreshAll(); toast('Schema refreshed'); route(); } catch (err) { fail(err); }
        });
        startHighlighting();
        $(document).on('click', '[data-toggle=conn]', function (e) {
            e.preventDefault();
            const name = this.dataset.conn;
            state.expanded[name] = state.expanded[name] === false;
            renderTree();
        });
        $('#gr-tree-filter').on('input', () => renderTree());
        window.addEventListener('hashchange', route);
        try {
            await loadBoot();
            state.bootFresh = true;
        } catch (e) {
            $content().html(`<div class="alert alert-danger">${errorMessage(e)}</div>`);
            return;
        }
        renderTree();
        route();
    }

    $(function () {
        if (PAGE === 'setup') setupWizard();
        if (PAGE === 'app') bootApp();
    });
})(jQuery);
