/*!
 * Grocery Restable admin - API area: endpoint list, "new endpoint" flow, API keys, docs and console.
 * The editor of a single endpoint lives in resource.js.
 */
(function (GR, $) {
    'use strict';

    const { esc, icon, badge, sourceBadge, panel, empty, link, withQuery, codeBlock } = GR.html;
    const { uniq, absoluteApiUrl, formValues, errorMessage } = GR.util;
    const { api } = GR.http;
    const { toast, fail, modal, drawer, confirmDialog, copy, download } = GR.ui;
    const shell = GR.shell;
    const store = GR.store;
    const $content = shell.$content;

    // =====================================================================
    // Sidebar: endpoints + tools
    // =====================================================================

    function endpointItem(r) {
        const extra = (r.enabled ? '' : ` <span class="text-muted small" title="disabled">${icon('ban')}</span>`)
            + ((r.problems || []).length ? ` <span class="text-danger" title="${esc(r.problems.join('\n'))}">${icon('triangle-exclamation')}</span>` : '')
            + `<span class="gr-dot gr-dot-${esc(r.source)}" title="${esc(r.source)}"></span>`;
        return shell.navItem({ href: link('api', 'r', r.name), iconName: 'plug', label: '/' + r.name, extra, cls: r.enabled ? '' : 'gr-node-muted' });
    }

    shell.defineNav('api', {
        head: () => shell.navHead('API', 'plug', `<a href="#" class="gr-nav-action" data-action="new-endpoint" title="New endpoint">${icon('plus')} New</a>`, 'Filter endpoints'),
        body: (filter) => {
            const all = shell.navItem({ href: '#/api', iconName: 'table-cells', label: 'All tables' });
            if (!store.resources) {
                shell.loadResources().then(shell.renderNav, () => {});
                return `<ul class="gr-menu">${all}</ul><div class="text-muted small ps-3">${icon('spinner', 'fa-spin')} loading…</div>`;
            }
            const list = store.resources.filter((r) => !filter || r.name.toLowerCase().includes(filter) || String(r.table || '').toLowerCase().includes(filter));
            return `<ul class="gr-menu">${all}</ul>
                <div class="gr-nav-caption">Endpoints <span class="badge text-bg-light">${esc(store.resources.length)}</span></div>
                <ul class="gr-menu">${list.map(endpointItem).join('') || `<li class="text-muted small ps-3">${filter ? 'No match' : 'No endpoints yet'}</li>`}</ul>`;
        },
        foot: () => `<ul class="gr-menu">
            ${shell.navItem({ href: '#/api/keys', iconName: 'key', label: 'API keys' })}
            ${shell.navItem({ href: '#/api/docs', iconName: 'book', label: 'API docs' })}
            ${shell.navItem({ href: '#/api/console', iconName: 'terminal', label: 'Console' })}</ul>`,
    });

    // =====================================================================
    // GET /api - every table and its endpoint
    // =====================================================================

    async function index() {
        shell.setBreadcrumb([{ label: 'API', icon: 'plug' }, { label: 'All tables' }]);
        const b = await shell.loadBoot();
        const s = b.settings;
        const list = await shell.loadResources(true);
        const byTable = {};
        list.forEach((r) => { (byTable[`${r.connection}\u0000${r.table}`] = byTable[`${r.connection}\u0000${r.table}`] || []).push(r); });
        const seen = new Set();
        let totalTables = 0;
        let exposedTables = 0;

        const tableRow = (c, t) => {
            totalTables++;
            const resources = byTable[`${c.name}\u0000${t.name}`] || [];
            resources.forEach((r) => seen.add(r.name));
            if (resources.length) exposedTables++;
            const r0 = resources[0];
            const endpoint = resources.length
                ? resources.map((r) => `<div><a href="${link('api', 'r', r.name)}"><code>/${esc(r.name)}</code></a> ${sourceBadge(r.source)}${r.enabled ? '' : ' ' + badge('disabled', 'secondary')}${r.shadowed ? ' ' + badge('shadowed by code', 'warning') : ''}${(r.problems || []).length ? ` <span class="text-danger" title="${esc(r.problems.join('\n'))}">${icon('triangle-exclamation')}</span>` : ''}</div>`).join('')
                : '<span class="badge gr-badge-none">not exposed</span>';
            const ops = r0 && r0.operations ? Object.entries(r0.operations).filter(([, v]) => v).map(([k]) => badge(k, 'light')).join(' ') : '';
            const perms = r0 ? (r0.permissions ? Object.keys(r0.permissions).map((k) => badge(k, 'info')).join(' ') : '<span class="text-muted small">default</span>') : '';
            const actions = resources.length
                ? `<a href="${link('api', 'r', r0.name)}">${icon('sliders')} Configure</a><a href="${withQuery(link('api', 'r', r0.name), { tab: 'preview' })}">${icon('flask')} Preview</a>`
                  + (r0.source === 'gui' ? `<a href="#" class="text-danger" data-delete-resource="${esc(r0.name)}">${icon('trash')} Remove</a>` : '')
                : `<button class="btn btn-sm btn-primary py-0" data-expose="${esc(t.name)}" data-conn="${esc(c.name)}">${icon('plug')} Expose</button>
                   <a href="${link('api', 'new', c.name, t.name)}">${icon('sliders')} Configure first</a>`;
            return `<tr class="${resources.length ? '' : 'gr-row-muted'}"><td>${icon(t.type === 'view' ? 'eye' : 'table', 'text-secondary')} <a href="${link('data', c.name, t.name)}" title="Browse data"><b>${esc(t.name)}</b></a></td>
                <td>${endpoint}</td><td>${ops}</td><td>${perms}</td><td class="gr-actions-cell">${actions}</td></tr>`;
        };

        const sections = [];
        for (const c of b.connections) {
            const tables = await shell.loadTables(c.name, true);
            if (!Array.isArray(tables)) {
                sections.push(panel(`${icon('database')} ${esc(c.name)}`, `<div class="alert alert-danger mb-0 small">${esc(tables.error)}</div>`));
                continue;
            }
            sections.push(panel(`${icon('database')} ${esc(c.name)} ${c.default ? badge('default', 'primary') : ''} <span class="text-muted fw-normal small">${esc(tables.length)} tables</span>`,
                tables.length
                    ? `<div class="table-responsive"><table class="gr-table w-100 mb-0"><thead><tr><th>Table</th><th>API endpoint</th><th>Operations</th><th>Permissions</th><th></th></tr></thead><tbody>${tables.map((t) => tableRow(c, t)).join('')}</tbody></table></div>`
                    : empty('This database has no tables yet. Create one with your usual tools, then click <b>Refresh schema</b>.')));
        }
        const orphans = list.filter((r) => !seen.has(r.name));
        if (orphans.length) {
            sections.push(panel(`${icon('triangle-exclamation', 'text-warning')} Endpoints without a table`, `<table class="gr-table w-100 mb-0"><thead><tr><th>Endpoint</th><th>Source</th><th>Problem</th><th></th></tr></thead><tbody>
                ${orphans.map((r) => `<tr><td><code>/${esc(r.name)}</code></td><td>${sourceBadge(r.source)}</td><td class="small text-danger">${esc((r.problems || []).join(' '))}</td>
                <td class="gr-actions-cell">${r.source === 'gui' ? `<a href="#" class="text-danger" data-delete-resource="${esc(r.name)}">${icon('trash')} Remove</a>` : ''}</td></tr>`).join('')}</tbody></table>`));
        }

        $content().html(`<div class="gr-explain mb-3">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2"><b class="me-auto">${icon('circle-info', 'text-primary')} ${esc(exposedTables)} of ${esc(totalTables)} tables are API endpoints under <code>${esc(GR.CFG.apiUrl)}/{name}</code></b>
                <button class="btn btn-sm btn-outline-secondary" data-action="reload-tree">${icon('rotate')} Refresh schema</button>
                <button class="btn btn-sm ${s.auto_expose ? 'btn-outline-danger' : 'btn-outline-primary'}" id="toggle-auto">${icon(s.auto_expose ? 'toggle-on' : 'toggle-off')} Auto expose: ${s.auto_expose ? 'on' : 'off'}</button>
                <button class="btn btn-sm btn-primary" data-action="new-endpoint">${icon('plus')} New endpoint</button></div>
            <div class="small">A table becomes an endpoint in one of three ways (the first one wins):
                ${sourceBadge('code')} defined in your application code · ${sourceBadge('gui')} exposed / customized here (stored in SQLite) ·
                ${sourceBadge('auto')} generated from the schema while <b>auto expose</b> is on (default connection only).
                New tables and columns are detected automatically on the next request; <b>Refresh schema</b> forces it.</div></div>`
            + sections.join(''));

        const $c = $content();
        $c.find('[data-expose]').on('click', async function () {
            $(this).prop('disabled', true);
            try { await shell.exposeTable(this.dataset.conn, this.dataset.expose); GR.reload(); } catch (err) { fail(err); $(this).prop('disabled', false); }
        });
        $c.find('#toggle-auto').on('click', async () => {
            const enable = !s.auto_expose;
            if (!enable && !await confirmDialog('Turn auto expose off? Tables that are only <b>auto</b> exposed stop being API endpoints until you expose them.', 'Turn off', 'btn-warning')) return;
            try { await api('settings.save', { settings: { auto_expose: enable } }); toast(`Auto expose ${enable ? 'enabled' : 'disabled'}`); await shell.refreshAll(); GR.reload(); } catch (err) { fail(err); }
        });
        $c.find('[data-delete-resource]').on('click', async function (e) {
            e.preventDefault();
            const name = this.dataset.deleteResource;
            if (!await confirmDialog(`Remove the GUI endpoint <b>${esc(name)}</b>? The table is not touched${s.auto_expose ? ' (it falls back to its auto definition while auto expose is on)' : ''}.`, 'Remove')) return;
            try { await api('resource.delete', { name }); toast('Endpoint removed'); await shell.refreshAll(); GR.reload(); } catch (err) { fail(err); }
        });
    }

    // ---------------------------------------------------------------- new endpoint dialog

    async function newEndpoint() {
        const connections = store.boot.connections;
        const $m = modal({
            title: `${icon('plus')} New API endpoint`,
            body: `<p class="gr-help">Pick the table to publish. Expose it straight away with generated defaults (relations from foreign keys), or review fields and permissions first.</p>
                <div class="row g-2"><div class="col-md-5"><label class="form-label small mb-0" for="ne-conn">Connection</label>
                <select class="form-select form-select-sm" id="ne-conn">${connections.map((c) => `<option ${c.default ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div>
                <div class="col-md-7"><label class="form-label small mb-0" for="ne-table">Table or view</label><select class="form-select form-select-sm" id="ne-table"><option>loading…</option></select></div></div>`,
            buttons: [
                { label: `${icon('sliders')} Configure first`, cls: 'btn-outline-primary', action: ($el) => { window.location.hash = link('api', 'new', $el.find('#ne-conn').val(), $el.find('#ne-table').val()); } },
                { label: `${icon('plug')} Expose with defaults`, action: async ($el) => {
                    const saved = await shell.exposeTable($el.find('#ne-conn').val(), $el.find('#ne-table').val());
                    window.location.hash = link('api', 'r', saved.name);
                } },
            ],
        });
        const fill = async () => {
            const tables = await shell.loadTables($m.find('#ne-conn').val());
            if (!Array.isArray(tables)) { $m.find('#ne-table').html(`<option value="">${esc(tables.error)}</option>`); return; }
            const group = (label, list) => (list.length ? `<optgroup label="${esc(label)}">${list.map((t) => `<option value="${esc(t.name)}">${esc(t.name)}${t.type === 'view' ? ' (view)' : ''}</option>`).join('')}</optgroup>` : '');
            $m.find('#ne-table').html(group('Not exposed yet', tables.filter((t) => !t.resources.length)) + group('Already exposed (adds another endpoint)', tables.filter((t) => t.resources.length)));
        };
        $m.find('#ne-conn').on('change', fill);
        fill();
    }

    // =====================================================================
    // GET /api/keys
    // =====================================================================

    async function keys() {
        shell.setBreadcrumb([{ label: 'API', icon: 'plug', href: '#/api' }, { label: 'API keys', icon: 'key' }]);
        const list = await api('apikeys.list');
        const jwtOn = ['jwt', 'any'].includes(store.boot.settings.auth_mode);
        const keyRow = (k) => `<tr class="${k.revoked_at ? 'text-muted' : ''}"><td>${esc(k.id)}</td><td>${esc(k.name)}</td><td><code>${esc(k.prefix)}…</code></td><td>${badge(k.role, k.role === 'service_role' ? 'danger' : 'info')}</td>
            <td>${esc(k.created_at || '')}</td><td>${esc(k.last_used_at || 'never')}</td><td>${k.revoked_at ? badge('revoked', 'secondary') : badge('active', 'success')}</td>
            <td class="gr-actions-cell">${!k.revoked_at ? `<a href="#" data-revoke="${esc(k.id)}" class="text-warning">${icon('ban')} Revoke</a>` : ''}<a href="#" data-del="${esc(k.id)}" class="text-danger">${icon('trash')} Delete</a></td></tr>`;

        $content().html(panel(`${icon('key')} API keys`, `<table class="gr-table w-100"><thead><tr><th>#</th><th>Name</th><th>Prefix</th><th>Role</th><th>Created</th><th>Last used</th><th>Status</th><th></th></tr></thead><tbody>
            ${list.map(keyRow).join('') || '<tr><td colspan="8" class="text-center text-muted">No keys</td></tr>'}</tbody></table>
            <p class="gr-help mb-0">Send a key in the <code>apikey</code> header, <code>X-API-Key</code> header or <code>Authorization: Bearer</code>. Only a SHA-256 hash of each key is stored. The <code>service_role</code> role bypasses every permission: never ship it to browsers or mobile apps.</p>`,
            `<button class="btn btn-sm btn-primary" data-add>${icon('plus')} New API key</button>`)
            + panel(`${icon('id-badge')} JWT test token`, jwtOn ? `<div class="row g-2 align-items-end">
                <div class="col-md-3"><label class="form-label small mb-0" for="jwt-role">Role</label><input class="form-control form-control-sm" id="jwt-role" value="authenticated"></div>
                <div class="col-md-3"><label class="form-label small mb-0" for="jwt-sub">Subject (sub)</label><input class="form-control form-control-sm" id="jwt-sub" value="user-1"></div>
                <div class="col-md-3"><label class="form-label small mb-0" for="jwt-exp">Expires in (seconds)</label><input type="number" class="form-control form-control-sm" id="jwt-exp" value="3600"></div>
                <div class="col-md-3"><button class="btn btn-sm btn-outline-primary w-100" id="jwt-gen">${icon('wand-magic-sparkles')} Generate</button></div></div>
                <div id="jwt-out" class="mt-2"></div>`
                : `<p class="mb-0 small text-muted">JWT authentication is disabled. Set the authentication mode to "API keys + JWT" or "JWT only" in <a href="#/settings/auth">Settings › Authentication</a>.</p>`));

        const $c = $content();
        $c.find('[data-add]').on('click', () => drawer({
            title: `${icon('key')} New API key`,
            body: `<div class="mb-2"><label class="form-label small mb-0" for="k-name">Name</label><input class="form-control form-control-sm" id="k-name" name="name" placeholder="Mobile app"></div>
                <label class="form-label small mb-0" for="k-role">Role</label><input class="form-control form-control-sm" id="k-role" name="role" value="authenticated" list="gr-roles">
                <datalist id="gr-roles"><option value="anon"><option value="authenticated"><option value="service_role"></datalist>
                <p class="gr-help mt-1 mb-0">Permissions are granted per role on each endpoint (or in the default permissions).</p>`,
            buttons: [{ label: `${icon('key')} Create`, save: true, action: async ($el) => {
                const r = await api('apikey.create', { name: $el.find('[name=name]').val(), role: $el.find('[name=role]').val() });
                setTimeout(() => modal({
                    title: `${icon('key')} Your new API key`,
                    body: `<p class="small">Copy it now - it will not be shown again.</p><div class="gr-key">${esc(r.key)}</div>`,
                    buttons: [{ label: `${icon('copy')} Copy`, cls: 'btn-outline-primary', action: () => { copy(r.key); return false; } }],
                }), 400);
                GR.reload();
            } }],
        }));
        $c.find('[data-revoke]').on('click', async function (e) {
            e.preventDefault();
            if (!await confirmDialog('Revoke this key? Clients using it will receive 401.', 'Revoke', 'btn-warning')) return;
            try { await api('apikey.revoke', { id: Number(this.dataset.revoke) }); toast('Key revoked'); GR.reload(); } catch (err) { fail(err); }
        });
        $c.find('[data-del]').on('click', async function (e) {
            e.preventDefault();
            if (!await confirmDialog('Delete this key permanently?', 'Delete')) return;
            try { await api('apikey.delete', { id: Number(this.dataset.del) }); toast('Key deleted'); GR.reload(); } catch (err) { fail(err); }
        });
        $c.find('#jwt-gen').on('click', async () => {
            try {
                const r = await api('jwt.generate', { role: $('#jwt-role').val(), sub: $('#jwt-sub').val(), expiresIn: Number($('#jwt-exp').val()) });
                $('#jwt-out').html(`<div class="gr-key small">${esc(r.token)}</div>${codeBlock(JSON.stringify(r.claims, null, 2), 'json', 'mt-2 mb-0')}`);
            } catch (err) { fail(err); }
        });
    }

    // =====================================================================
    // GET /api/docs
    // =====================================================================

    const QUERY_OPERATORS = [['eq', 'equals', 'status=eq.active'], ['neq', 'not equal', 'status=neq.archived'], ['gt / gte', 'greater than (or equal)', 'price=gte.10'],
        ['lt / lte', 'less than (or equal)', 'stock=lt.5'], ['like', 'LIKE (use * as wildcard)', 'name=like.*phone*'], ['ilike', 'case insensitive LIKE', 'email=ilike.*@example.com'],
        ['in', 'in a list', 'id=in.(1,2,3)'], ['is', 'is null / true / false', 'deleted_at=is.null'], ['not.', 'negate any operator', 'status=not.in.(a,b)'],
        ['or / and', 'logical groups', 'or=(age.lt.18,age.gt.65)'], ['rel.col', 'filter on a related table', 'offices.city=eq.Paris']];

    const queryReference = () => `<details class="gr-panel mb-3"><summary class="gr-panel-title">${icon('filter')} Query syntax reference</summary><div class="gr-panel-body">
        <table class="gr-table w-100"><thead><tr><th>Operator</th><th>Meaning</th><th>Example</th></tr></thead><tbody>${QUERY_OPERATORS.map(([o, m, e]) => `<tr><td><code>${esc(o)}</code></td><td>${esc(m)}</td><td><code>${esc(e)}</code></td></tr>`).join('')}</tbody></table>
        <p class="small mb-0"><b>Projection &amp; embedding:</b> <code>select=id,name,alias:relation(col,nested(*))</code> · <b>Order:</b> <code>order=col.desc.nullslast</code> ·
        <b>Paging:</b> <code>limit</code>/<code>offset</code> or <code>page</code>/<code>per_page</code> · <b>Total:</b> <code>count=exact</code> · <b>Search:</b> <code>q=text</code> · <b>CSV:</b> <code>format=csv</code></p></div></details>`;

    async function docs() {
        shell.setBreadcrumb([{ label: 'API', icon: 'plug', href: '#/api' }, { label: 'API docs', icon: 'book' }]);
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
        const bodyOf = (op) => {
            const content = (op.requestBody || {}).content || {};
            const s = (content['application/json'] || content['multipart/form-data'] || {}).schema;
            if (!s) return null;
            return s.$ref ? { name: s.$ref.split('/').pop(), schema: spec.components.schemas[s.$ref.split('/').pop()] } : { name: 'body', schema: s };
        };
        const endpoint = ({ path, method, op, params }) => {
            const body = bodyOf(op);
            const parameters = params.map(resolveParam).filter(Boolean);
            return `<details class="gr-endpoint"><summary><span class="gr-method gr-method-${esc(method)}">${esc(method.toUpperCase())}</span><code>${esc(path)}</code><span class="text-muted">${esc(op.summary || '')}</span></summary>
                <div class="gr-endpoint-body">
                ${parameters.length ? `<table class="gr-table w-100"><thead><tr><th>Parameter</th><th>In</th><th>Description</th></tr></thead><tbody>${parameters.map((p) => `<tr><td><code>${esc(p.name)}</code>${p.required ? ' <span class="text-danger">*</span>' : ''}</td><td>${esc(p.in)}</td><td>${esc(p.description || '')}</td></tr>`).join('')}</tbody></table>` : ''}
                ${body ? `<p class="small mb-1"><b>Body</b> (${esc(body.name)})</p>${codeBlock(JSON.stringify(body.schema, null, 2), 'json')}` : ''}
                ${method === 'get' && !path.includes('{') ? `<a class="btn btn-sm btn-outline-primary" href="${withQuery('#/api/console', { method: 'GET', path: path.replace(/^\//, '') })}">${icon('play')} Try in console</a>` : ''}
                </div></details>`;
        };
        const html = Object.entries(groups).map(([tag, ops]) => {
            const info = (spec.tags || []).find((t) => t.name === tag) || {};
            return panel(`${icon('cube')} ${esc(tag)} <span class="text-muted fw-normal small">${esc(info.description || '')}</span>`, ops.map(endpoint).join(''));
        }).join('');

        $content().html(panel(`${icon('book')} ${esc(spec.info.title)} <span class="text-muted fw-normal small">OpenAPI ${esc(spec.openapi)}</span>`, `<p class="small mb-2">${esc(spec.info.description)}</p>
            <p class="small mb-0">Server: <code>${esc(absoluteApiUrl())}</code></p>`, `<button class="btn btn-sm btn-outline-secondary" id="dl-spec">${icon('download')} openapi.json</button>`)
            + queryReference() + (html || empty('No endpoints exposed yet.')));
        $('#dl-spec').on('click', () => download('openapi.json', JSON.stringify(spec, null, 2)));
    }

    // =====================================================================
    // GET /api/console
    // =====================================================================

    const shellQuote = (s) => "'" + String(s).replace(/'/g, "'\\''") + "'";

    function curl(v) {
        let cmd = `curl -X ${v.method} ${shellQuote(absoluteApiUrl() + '/' + v.path)}${v.role === 'anon' ? '' : ' \\\n  -H "apikey: <YOUR_KEY>"'}`;
        if (v.prefer) cmd += ` \\\n  -H ${shellQuote('Prefer: ' + v.prefer)}`;
        if (v.body && v.method !== 'GET') cmd += ` \\\n  -H "Content-Type: application/json" \\\n  -d ${shellQuote(v.body)}`;
        return cmd;
    }

    async function consolePage(req) {
        const params = req.query;
        shell.setBreadcrumb([{ label: 'API', icon: 'plug', href: '#/api' }, { label: 'Console', icon: 'terminal' }]);
        const roles = uniq(['service_role', 'anon', 'authenticated', ...Object.keys(store.boot.settings.default_permissions || {})]);
        $content().html(panel(`${icon('terminal')} API console`, `<form id="console-form">
            <div class="row g-2">
                <div class="col-md-2"><select class="form-select form-select-sm" name="method" aria-label="Method">${['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => `<option ${params.method === m ? 'selected' : ''}>${m}</option>`).join('')}</select></div>
                <div class="col-md-7"><div class="input-group input-group-sm"><span class="input-group-text">${esc(GR.CFG.apiUrl)}/</span><input class="form-control font-monospace" name="path" value="${esc(params.path || '')}" placeholder="employees?select=*&limit=5" aria-label="Path"></div></div>
                <div class="col-md-3"><div class="input-group input-group-sm"><span class="input-group-text">as</span><input class="form-control" name="role" list="console-roles" value="${esc(params.role || 'service_role')}" aria-label="Role"></div>
                    <datalist id="console-roles">${roles.map((r) => `<option value="${esc(r)}">`).join('')}</datalist></div>
                <div class="col-md-12"><input class="form-control form-control-sm font-monospace" name="prefer" placeholder="Prefer header, e.g. return=representation, count=exact" aria-label="Prefer header"></div>
                <div class="col-12"><textarea class="form-control form-control-sm font-monospace" name="body" rows="5" placeholder='JSON body, e.g. {"name": "value"}' aria-label="Body">${esc(params.body || '')}</textarea></div>
            </div>
            <div class="mt-2 d-flex gap-2"><button class="btn btn-sm btn-primary">${icon('paper-plane')} Send</button><button type="button" class="btn btn-sm btn-outline-secondary" id="as-curl">${icon('copy')} Copy as cURL</button></div>
            </form><p class="gr-help mt-2 mb-0">Requests run inside the server as the chosen role, without needing a key. <code>service_role</code> bypasses permissions.</p>`)
            + '<div id="console-out"></div>');

        $('#console-form').on('submit', async function (e) {
            e.preventDefault();
            $('#console-out').html(GR.html.loading('Sending…'));
            try {
                const r = await api('console', formValues($(this)));
                let pretty = r.body;
                let lang = 'plaintext';
                try { pretty = JSON.stringify(JSON.parse(r.body), null, 2); lang = 'json'; } catch (err) { /* not JSON */ }
                const cls = r.status < 300 ? 'success' : r.status < 500 ? 'warning' : 'danger';
                $('#console-out').html(panel(`${badge(r.status, cls)} <span class="small text-muted fw-normal">${esc(r.durationMs)} ms</span>`,
                    `<details class="mb-2"><summary class="small">Response headers</summary><table class="gr-table w-100 mt-1">${Object.entries(r.headers).map(([k, val]) => `<tr><th class="small">${esc(k)}</th><td class="small">${esc(val)}</td></tr>`).join('')}</table></details>
                    ${codeBlock(pretty, lang)}`));
            } catch (err) { $('#console-out').html(`<div class="alert alert-danger">${errorMessage(err)}</div>`); }
        });
        $('#as-curl').on('click', () => copy(curl(formValues($('#console-form')))));
        if (params.run) $('#console-form').trigger('submit');
    }

    function init() {
        $(document).on('click', '[data-action=new-endpoint]', (e) => {
            e.preventDefault();
            newEndpoint();
        });
    }

    GR.pages = GR.pages || {};
    GR.pages.api = { init, index, keys, docs, console: consolePage };
})(window.GR = window.GR || {}, jQuery);
