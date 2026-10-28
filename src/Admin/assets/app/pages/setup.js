/*!
 * Grocery Restable admin - setup wizard (framework → database → admin account → API options → keys).
 */
(function (GR, $) {
    'use strict';

    const { esc, icon } = GR.html;
    const { formValues, errorMessage } = GR.util;
    const { setup: call } = GR.http;
    const { defaultEngine, connectionFields, bindConnectionFields, connectionFieldValues } = GR.components;
    const CFG = GR.CFG;

    const alertBox = (html, type = 'danger') => `<div class="alert alert-${type} py-2 small mt-3 mb-0">${html}</div>`;

    const STEPS = {
        1: (state) => `<h2 class="gr-section-title">${icon('layer-group')}Choose your PHP framework</h2>
            <p class="gr-help">With CodeIgniter 4, Grocery Restable reuses its routing, database groups, validation and sessions. Without a framework it runs on plain PHP with its own PDO / MySQLi connection.</p>
            <div class="row g-2">${Object.entries(CFG.frameworks).map(([key, f]) => `
                <div class="col-md-6"><label class="gr-choice ${f.available ? '' : 'disabled'}">
                    <span class="gr-choice-icon">${icon(key === 'codeigniter4' ? 'fire' : 'code')}</span>
                    <input type="radio" name="framework" value="${esc(key)}" ${state.framework === key ? 'checked' : ''} ${f.available ? '' : 'disabled'}>
                    <b>${esc(f.label)}</b>
                    <div class="small text-muted">${f.detected ? 'Detected - running now' : f.available ? 'Use your own database connection' : 'Not detected: install Grocery Restable inside a CodeIgniter 4 app'}</div>
                </label></div>`).join('')}</div>`,

        2: (state, fw) => {
            const d = state.database;
            return `<h2 class="gr-section-title">${icon('database')}Database connection</h2>
                ${fw.length ? `<div class="mb-3">
                    <div class="form-check"><input class="form-check-input" type="radio" name="mode" id="m-fw" value="framework" ${d.mode === 'framework' ? 'checked' : ''}>
                    <label class="form-check-label" for="m-fw">Use the CodeIgniter database configuration (app/Config/Database.php)</label></div>
                    <select class="form-select form-select-sm ms-4 mt-1 w-auto" name="connection">${fw.map((c) => `<option value="${esc(c.name)}" ${c.name === d.connection ? 'selected' : ''}>${esc(c.name)} - ${esc(c.label || c.driver || '')} ${esc(c.database || '')}</option>`).join('')}</select>
                    <div class="form-check mt-2"><input class="form-check-input" type="radio" name="mode" id="m-custom" value="custom" ${d.mode === 'custom' ? 'checked' : ''}>
                    <label class="form-check-label" for="m-custom">Configure a connection here (stored encrypted in SQLite)</label></div></div>` : ''}
                <div id="custom-db" class="${d.mode === 'custom' ? '' : 'd-none'}">${connectionFields(d, CFG.engines)}</div>
                <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="test-db">${icon('plug')} Test connection</button>
                <div id="db-result"></div>`;
        },

        3: (state) => `<h2 class="gr-section-title">${icon('user-shield')}Administrator account</h2>
            <p class="gr-help">This account logs into this GUI. Passwords are hashed with PHP's password_hash().</p>
            <div class="row g-2" style="max-width:480px">
                <div class="col-12"><label class="form-label small mb-0">Username</label><input class="form-control form-control-sm" name="username" value="${esc(state.account.username)}" autocomplete="username"></div>
                <div class="col-6"><label class="form-label small mb-0">Password (min. 8)</label><input type="password" class="form-control form-control-sm" name="password" value="${esc(state.account.password)}" autocomplete="new-password"></div>
                <div class="col-6"><label class="form-label small mb-0">Confirm password</label><input type="password" class="form-control form-control-sm" name="password_confirm" value="${esc(state.account.password_confirm)}" autocomplete="new-password"></div>
            </div><div id="acc-result"></div>`,

        4: (state) => {
            const o = state.options;
            const mode = (value, label) => `<option value="${value}" ${o.auth_mode === value ? 'selected' : ''}>${label}</option>`;
            return `<h2 class="gr-section-title">${icon('sliders')}API options</h2>
                <div class="mb-2"><label class="form-label small mb-0">Application name</label><input class="form-control form-control-sm w-50" name="app_name" value="${esc(o.app_name)}"></div>
                <label class="form-label small mb-0">Authentication</label>
                <select class="form-select form-select-sm w-50 mb-1" name="auth_mode">
                    ${mode('api_key', 'API keys (recommended)')}${mode('any', 'API keys + JWT bearer tokens')}${mode('jwt', 'JWT bearer tokens only')}${mode('none', 'None - open API (development only!)')}
                </select>
                <p class="gr-help">An <code>anon</code> key and a <code>service_role</code> key are generated at the end (like Supabase).</p>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="auto" name="auto_expose" ${o.auto_expose ? 'checked' : ''}>
                <label class="form-check-label" for="auto">Auto expose every table (relations detected from foreign keys)</label></div>
                <p class="gr-help">You can fine tune each table afterwards in the GUI or in code. Auto exposed tables follow the default permissions.</p>
                <div id="opt-result"></div>`;
        },

        5: (state) => `<div class="text-center mb-3"><span class="text-success fs-1">${icon('circle-check')}</span>
            <h2 class="fs-5">Setup complete</h2><p class="text-muted">Store these keys now - they are shown only once.</p></div>
            <label class="small fw-bold">anon key <span class="text-muted fw-normal">(public, role "anon")</span></label><div class="gr-key mb-2">${esc(state.keys.anon)}</div>
            <label class="small fw-bold">service_role key <span class="text-muted fw-normal">(secret, bypasses every permission - server side only)</span></label><div class="gr-key mb-3">${esc(state.keys.service_role)}</div>
            <div class="text-center"><a class="btn btn-primary btn-sm" href="${esc(CFG.adminUrl)}/login">${icon('right-to-bracket')} Go to login</a></div>`,
    };

    function wizard() {
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

        // With CodeIgniter 4 the database group of app/Config/Database.php is the natural default.
        const useFrameworkDb = () => state.framework === 'codeigniter4' && CFG.frameworkConnections.length > 0;
        if (useFrameworkDb()) state.database.mode = 'framework';

        async function testDatabase() {
            const r = await call('test', { database: state.database });
            state.tested = r.ok;
            $('#db-result').html(alertBox(esc(r.message), r.ok ? 'success' : 'danger'));
            return r.ok;
        }

        function bindStep() {
            if (state.step === 1) {
                $body.find('input[name=framework]').on('change', function () {
                    state.framework = this.value;
                    state.database.mode = useFrameworkDb() ? 'framework' : 'custom';
                    state.tested = false;
                });
            }
            if (state.step === 2) {
                const d = state.database;
                bindConnectionFields($body);
                $body.find('input[name=mode]').on('change', function () {
                    d.mode = this.value;
                    $('#custom-db').toggleClass('d-none', d.mode !== 'custom');
                    state.tested = false;
                });
                $body.find('select[name=connection]').on('change', function () { d.connection = this.value; state.tested = false; });
                $body.find('#test-db').on('click', async () => {
                    Object.assign(d, connectionFieldValues($body));
                    $('#db-result').html(alertBox(`${icon('spinner', 'fa-spin')} Testing…`, 'secondary'));
                    try { await testDatabase(); } catch (e) { $('#db-result').html(alertBox(errorMessage(e))); }
                });
            }
        }

        function render() {
            $('#gr-steps li').each(function () {
                const s = Number(this.dataset.step);
                $(this).toggleClass('active', s === state.step).toggleClass('done', s < state.step);
            });
            $back.prop('disabled', state.step === 1 || state.step === 5);
            $next.toggle(state.step !== 5).html(state.step === 4 ? `${icon('check')} Finish setup` : `Next ${icon('chevron-right', 'ms-1')}`);
            $body.html(STEPS[state.step](state, useFrameworkDb() ? CFG.frameworkConnections : []));
            bindStep();
        }

        function collect() {
            if (state.step === 2 && state.database.mode === 'custom') Object.assign(state.database, connectionFieldValues($body));
            if (state.step === 3) Object.assign(state.account, formValues($body));
            if (state.step === 4) Object.assign(state.options, formValues($body));
        }

        /** Validate the current step; resolves false to stay on it. */
        async function validate() {
            if (state.step === 2 && !state.tested) {
                try { return await testDatabase(); } catch (e) { $('#db-result').html(alertBox(errorMessage(e))); return false; }
            }
            if (state.step === 3) {
                const a = state.account;
                if (a.password.length < 8) { $('#acc-result').html(alertBox('The password must be at least 8 characters.')); return false; }
                if (a.password !== a.password_confirm) { $('#acc-result').html(alertBox('The passwords do not match.')); return false; }
            }
            if (state.step === 4) {
                try {
                    const r = await call('finish', { framework: state.framework, database: state.database, account: state.account, options: state.options });
                    state.keys = r.keys;
                } catch (e) {
                    $('#opt-result').html(alertBox(errorMessage(e)));
                    return false;
                }
            }
            return true;
        }

        $next.on('click', async () => {
            collect();
            $next.prop('disabled', true);
            const ok = await validate();
            $next.prop('disabled', false);
            if (!ok) return;
            state.step++;
            render();
        });
        $back.on('click', () => {
            collect();
            state.step--;
            render();
        });
        render();
    }

    GR.pages = GR.pages || {};
    GR.pages.setup = { wizard };
})(window.GR = window.GR || {}, jQuery);
