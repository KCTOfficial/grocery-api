/*!
 * Grocery Restable admin - endpoint (resource) editor of the API area.
 *
 *   GET /api/r/:name?tab=fields      edit an endpoint
 *   GET /api/new/:conn/:table        configure a table before exposing it
 */
(function (GR, $) {
    'use strict';

    const { esc, icon, badge, panel, empty, link, withQuery, codeBlock } = GR.html;
    const { sameSet, humanize, absoluteApiUrl } = GR.util;
    const { api } = GR.http;
    const { toast, fail, modal, drawer, confirmDialog, copy } = GR.ui;
    const C = GR.components;
    const shell = GR.shell;
    const store = GR.store;
    const $content = shell.$content;

    const HIDDEN_TYPES = ['hidden', 'invisible', 'password'];
    const NOT_WRITABLE_TYPES = ['readonly', 'invisible', 'hidden'];
    const LIST_KEYS = ['columns', 'unsetColumns', 'readFields', 'unsetReadFields', 'fields', 'addFields', 'editFields', 'unsetFields', 'unsetAddFields', 'unsetEditFields', 'requiredFields', 'uniqueFields', 'searchable', 'relations', 'uploads', 'where', 'orderBy'];
    const MAP_KEYS = ['displayAs', 'fieldTypes', 'rules', 'primaryKeys'];
    const SOURCE_NOTE = {
        code: ['lock', 'Defined in your application code - shown read-only. Change it in code.'],
        auto: ['wand-magic-sparkles', 'Auto exposed (generated from the schema). Saving stores a customized copy in SQLite.'],
        gui: ['floppy-disk', 'Configured in this GUI (stored in SQLite).'],
        new: ['circle-plus', 'Not published yet - review the configuration and click Save to create the endpoint.'],
    };

    // =====================================================================
    // Definition helpers (ports of the PHP Resource rules so the grid shows the effective state)
    // =====================================================================

    function normalizeDefinition(d) {
        const def = { ...d };
        LIST_KEYS.forEach((k) => { def[k] = Array.isArray(def[k]) ? [...def[k]] : []; });
        MAP_KEYS.forEach((k) => { def[k] = def[k] && !Array.isArray(def[k]) ? { ...def[k] } : {}; });
        def.operations = { list: true, read: true, create: true, update: true, delete: true, clone: false, export: true, ...(def.operations || {}) };
        return def;
    }

    /** Port of Resource::writableFields(). */
    function writable(def, table, mode) {
        const specific = mode === 'create' ? def.addFields : def.editFields;
        const explicit = specific.length ? specific : def.fields;
        const unset = [...def.unsetFields, ...(mode === 'create' ? def.unsetAddFields : def.unsetEditFields)];
        const pk = def.primaryKey || (table.primaryKey.length === 1 ? table.primaryKey[0] : null);
        const cols = Object.fromEntries(table.columns.map((c) => [c.name, c]));
        let candidates;
        if (explicit.length) {
            candidates = explicit;
        } else {
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

    // =====================================================================
    // Routes
    // =====================================================================

    /** GET /api/r/:name */
    async function edit(req) {
        const { name } = req.params;
        const summary = (await shell.loadResources(true)).find((r) => r.name === name);
        if (!summary) throw { message: `Unknown endpoint "${esc(name)}".` };
        if (!summary.table) return broken(summary);
        const [loaded, info] = await Promise.all([api('resource.get', { name }), shell.structure(summary.connection, summary.table)]);
        editor(summary.connection, info.table, loaded, req.query.tab);
    }

    /** GET /api/new/:conn/:table - generated definition, not saved yet. */
    async function create(req, res) {
        const { conn, table } = req.params;
        const info = await shell.structure(conn, table);
        const definition = await api('resource.generate', { connection: conn, table });
        editor(conn, info.table, { definition, source: 'new', enabled: true, readOnly: false, problems: [] }, req.query.tab);
    }

    /** A stored GUI definition that can no longer be loaded (e.g. its table was dropped). */
    function broken(summary) {
        shell.setBreadcrumb([{ label: 'API', icon: 'plug', href: '#/api' }, { label: '/' + summary.name, icon: 'triangle-exclamation' }]);
        $content().html(`<div class="alert alert-warning">${icon('triangle-exclamation', 'me-1')}This endpoint cannot be loaded: ${(summary.problems || []).map(esc).join('<br>')}</div>`
            + (summary.source === 'gui' ? `<button class="btn btn-sm btn-outline-danger" id="r-delete">${icon('trash')} Delete endpoint</button>` : ''));
        $('#r-delete').on('click', async () => {
            if (!await confirmDialog(`Delete endpoint <b>${esc(summary.name)}</b>?`, 'Delete')) return;
            try { await api('resource.delete', { name: summary.name }); await shell.loadResources(true); window.location.hash = '#/api'; } catch (err) { fail(err); }
        });
    }

    // =====================================================================
    // Editor
    // =====================================================================

    function editor(connection, table, loaded, activeTab = 'general') {
        $content().off();
        const def = normalizeDefinition(loaded.definition);
        const ro = loaded.readOnly;
        const isNew = loaded.source === 'new';
        const original = loaded.source === 'gui' ? def.name : null;
        const savedName = isNew ? null : loaded.definition.name;
        const columns = table.columns;
        const cols = columns.map((c) => c.name);
        const dis = ro ? 'disabled' : '';
        const ops = store.boot.permissions;

        const listSet = visibleList(def, table, def.columns, def.unsetColumns);
        const readSet = visibleList(def, table, def.readFields, def.unsetReadFields);
        const createSet = writable(def, table, 'create');
        const updateSet = writable(def, table, 'update');
        const searchDefault = listSet.filter((c) => ['string', 'text', 'enum', 'uuid'].includes(columns.find((x) => x.name === c).type));
        const searchSet = def.searchable.length ? def.searchable : searchDefault;

        // ---------------------------------------------------------------- chrome

        const panes = [
            { id: 'general', icon: 'cube', label: 'General' },
            { id: 'fields', icon: 'table-columns', label: 'Fields', count: columns.length + def.relations.length },
            { id: 'relations', icon: 'diagram-project', label: 'Relations', count: def.relations.length },
            { id: 'permissions', icon: 'shield-halved', label: 'Permissions' },
            { id: 'uploads', icon: 'upload', label: 'Uploads', count: def.uploads.length },
            { id: 'filters', icon: 'filter', label: 'Filters & order', count: def.where.length + def.orderBy.length },
            { id: 'preview', icon: 'flask', label: 'API preview' },
            { id: 'code', icon: 'code', label: 'PHP code' },
        ];
        if (!panes.some((p) => p.id === activeTab)) activeTab = 'general';
        shell.setTabs(panes.map((p) => ({ ...p, pane: true })), activeTab);
        shell.setBreadcrumb([
            { label: 'API', icon: 'plug', href: '#/api' },
            { label: isNew ? 'New endpoint' : 'Endpoint: /' + savedName, icon: 'plug' },
            { label: `Table: ${connection}.${table.name}`, icon: 'table', href: link('data', connection, table.name) },
        ]);
        const [noteIcon, note] = SOURCE_NOTE[loaded.source] || SOURCE_NOTE.gui;
        shell.setStatus(`<div class="gr-status ${isNew ? 'gr-status-off' : 'gr-status-on'}">${icon(noteIcon)}
            ${savedName ? `<a href="${esc(absoluteApiUrl())}/${encodeURIComponent(savedName)}" target="_blank" rel="noopener"><code>${esc(GR.CFG.apiUrl)}/${esc(savedName)}</code></a>` : ''}
            ${GR.html.sourceBadge(isNew ? 'none' : loaded.source)} <span class="text-muted">${esc(note)}</span>
            <a href="${link('data', connection, table.name)}" class="ms-auto">${icon('table-list')} Browse data</a></div>`);

        // ---------------------------------------------------------------- panes

        const fieldRow = (name, column, relation) => {
            const t = def.fieldTypes[name] || {};
            const extras = Array.isArray(t.extras) ? t.extras.join(', ') : (t.extras && typeof t.extras === 'object' ? Object.keys(t.extras).join(', ') : (t.extras ?? ''));
            const isM2m = relation && relation.type === 'many_to_many';
            const chk = (key, checked, enabled = true) => `<td class="text-center"><input type="checkbox" class="form-check-input" data-k="${key}" ${checked ? 'checked' : ''} ${enabled && !ro ? '' : 'disabled'} aria-label="${key} ${esc(name)}"></td>`;
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
                <td>${relation ? '' : `<select class="form-select form-select-sm" data-k="type" ${dis}><option value="">auto (${esc(column.type)})</option>${store.boot.fieldTypes.map((x) => `<option ${t.type === x ? 'selected' : ''}>${esc(x)}</option>`).join('')}</select>`}</td>
                <td>${relation ? '' : `<input class="form-control form-control-sm" data-k="extras" value="${esc(extras)}" placeholder="values / forced value" ${dis}>`}</td>
                <td><input class="form-control form-control-sm font-monospace" data-k="rules" value="${esc((def.rules[name] || {}).rules || '')}" placeholder="required|max_length[50]" ${dis}></td></tr>`;
        };

        const relationLink = (r) => (r.type === 'belongs_to' ? `<code>${esc(r.field)}</code> → ` : r.type === 'has_many' ? `← <code>${esc(r.foreignKey)}</code> of ` : `via <code>${esc(r.junctionTable)}</code> → `)
            + `<a href="${link('data', connection, r.table)}">${esc(r.table)}</a>`;
        const relationOptions = (r) => (r.priorityField ? 'priority: ' + esc(r.priorityField) + ' ' : '') + (r.orderBy ? 'order: ' + esc(r.orderBy.join(' ')) + ' ' : '') + ((r.where || []).length ? 'where: ' + r.where.length : '');
        const relationRows = () => def.relations.map((r, i) => `<tr><td><b>${esc(r.name)}</b></td><td>${badge(r.type.replace(/_/g, ' '), r.type === 'belongs_to' ? 'primary' : r.type === 'has_many' ? 'success' : 'warning')}</td>
            <td>${relationLink(r)}</td><td><code>${esc(r.titleField || '')}</code></td><td class="small">${relationOptions(r)}</td>
            <td class="gr-actions-cell">${ro ? '' : `<a href="#" data-rel-edit="${i}">${icon('pen')} Edit</a><a href="#" class="text-danger" data-rel-del="${i}" title="Remove">${icon('trash')}</a>`}</td></tr>`).join('')
            || '<tr><td colspan="6" class="text-center text-muted">No relations</td></tr>';

        const uploadRows = () => def.uploads.map((u, i) => `<tr data-upload="${i}"><td><select class="form-select form-select-sm" data-k="field" ${dis}>${cols.map((c) => `<option ${u.field === c ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select></td>
            <td><input class="form-control form-control-sm" data-k="path" value="${esc(u.path || '')}" placeholder="relative to the upload path" ${dis}></td>
            <td><input class="form-control form-control-sm" data-k="allowedTypes" value="${esc(u.allowedTypes || '')}" placeholder="jpg|png|pdf" ${dis}></td>
            <td><input class="form-control form-control-sm" data-k="maxSize" value="${esc(u.maxSize || '')}" placeholder="5MB" ${dis}></td>
            <td>${ro ? '' : `<a href="#" class="text-danger" data-upload-del="${i}" title="Remove">${icon('trash')}</a>`}</td></tr>`).join('')
            || '<tr><td colspan="5" class="text-center text-muted">No upload fields</td></tr>';

        const general = `<div class="row g-3"><div class="col-xl-7">${panel(`${icon('cube')} General`, `<div class="row g-2">
                <div class="col-md-4"><label class="form-label small mb-0" for="r-name">Endpoint name</label><div class="input-group input-group-sm"><span class="input-group-text">/</span><input class="form-control" id="r-name" value="${esc(def.name)}" ${dis}></div></div>
                <div class="col-md-4"><label class="form-label small mb-0" for="r-subject">Subject</label><input class="form-control form-control-sm" id="r-subject" value="${esc(def.subject || '')}" placeholder="${esc(humanize(def.name))}" ${dis}></div>
                <div class="col-md-4"><label class="form-label small mb-0" for="r-plural">Subject (plural)</label><input class="form-control form-control-sm" id="r-plural" value="${esc(def.subjectPlural || '')}" placeholder="${esc(humanize(def.name))}" ${dis}></div>
                <div class="col-md-12"><label class="form-label small mb-0" for="r-desc">Description</label><input class="form-control form-control-sm" id="r-desc" value="${esc(def.description || '')}" ${dis}></div>
                <div class="col-md-4"><label class="form-label small mb-0" for="r-pk">Primary key</label><select class="form-select form-select-sm" id="r-pk" ${dis}><option value="">auto (${esc(table.primaryKey.join(', ') || 'none')})</option>${cols.map((c) => `<option ${def.primaryKey === c ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select></div>
                <div class="col-md-4"><label class="form-label small mb-0" for="r-perpage">Page size</label><input type="number" min="1" class="form-control form-control-sm" id="r-perpage" value="${esc(def.perPage || '')}" placeholder="default" ${dis}></div>
                <div class="col-md-4"><label class="form-label small mb-0" for="r-maxlimit">Max page size</label><input type="number" min="1" class="form-control form-control-sm" id="r-maxlimit" value="${esc(def.maxLimit || '')}" placeholder="default" ${dis}></div>
                <div class="col-md-4"><label class="form-label small mb-0" for="r-idgen">ID of new records</label><select class="form-select form-select-sm" id="r-idgen" ${dis}>
                    ${[['', `auto (${autoIdLabel(table, def)})`], ['uuid', 'UUID v4 (random)'], ['uuid7', 'UUID v7 (time ordered)'], ['none', 'none (database or client)']]
                        .map(([v, l]) => `<option value="${v}" ${(def.idGenerator || '') === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
                <div class="col-md-8"><label class="form-label small mb-0" for="r-rl-requests">Rate limit of this endpoint</label><div class="input-group input-group-sm">
                    <input type="number" min="0" class="form-control" id="r-rl-requests" value="${esc(def.rateLimit ? def.rateLimit.requests : '')}" placeholder="global" ${dis}>
                    <span class="input-group-text">requests per</span>
                    <input type="number" min="1" class="form-control" id="r-rl-window" value="${esc(def.rateLimit ? def.rateLimit.window : '')}" placeholder="60" ${dis}>
                    <span class="input-group-text">seconds</span></div>
                    <div class="gr-help">Empty = the global limit (<a href="#/settings/ratelimit">Settings &rsaquo; Rate limiting</a>), 0 = unlimited.</div></div>
                <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="r-enabled" ${loaded.enabled ? 'checked' : ''} ${dis}><label class="form-check-label" for="r-enabled">Enabled (published by the API)</label></div></div>
            </div>`)}</div>
            <div class="col-xl-5">${panel(`${icon('toggle-on')} Operations`, `<div class="gr-check-grid">${store.boot.operations.map((o) => `<label><input type="checkbox" class="form-check-input me-1" data-op="${esc(o)}" ${def.operations[o] ? 'checked' : ''} ${dis}>${esc(o)}</label>`).join('')}</div>
                <p class="gr-help mb-0 mt-1">Grocery CRUD's unset_add / unset_edit / unset_delete / unset_read / unset_export / set_clone.</p>`)}</div></div>`;

        const fields = panel(`${icon('table-columns')} Fields`, `<div class="table-responsive"><table class="gr-table w-100 gr-fields-grid"><thead><tr><th>Field</th><th>Type</th><th>Label (display_as)</th><th title="Returned by the list endpoint">List</th><th title="Returned by the single record endpoint">Read</th>
            <th title="Writable on create">Create</th><th title="Writable on update">Update</th><th>Required</th><th>Unique</th><th title="Used by ?q=">Search</th><th>Field type</th><th>Extras</th><th>Rules (CodeIgniter syntax)</th></tr></thead>
            <tbody>${columns.map((c) => fieldRow(c.name, c, null)).join('')}${def.relations.map((r) => fieldRow(r.name, null, r)).join('')}</tbody></table></div>
            <p class="gr-help mb-0">Checked relation rows in <b>List</b> / <b>Read</b> are embedded by default. Field types: <code>hidden</code> (not returned; extras = forced value), <code>invisible</code>, <code>readonly</code>, <code>password</code> (hashed, never returned), <code>enum</code>/<code>set</code> (extras = allowed values).</p>`);

        const relationsPane = panel(`${icon('diagram-project')} Relations`, `<table class="gr-table w-100"><thead><tr><th>Name</th><th>Type</th><th>Link</th><th>Title</th><th>Options</th><th></th></tr></thead><tbody>${relationRows()}</tbody></table>
            <p class="gr-help mb-0">Embed a relation with <code>select=*,name(*)</code>. Relations also appear as rows in the <b>Fields</b> tab.</p>`,
            ro ? '' : `<div class="dropdown"><button class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">${icon('plus')} Add relation</button><ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="#" data-rel-add="belongs_to">belongs_to (set_relation)</a></li><li><a class="dropdown-item" href="#" data-rel-add="many_to_many">many_to_many (set_relation_n_n)</a></li>
            <li><a class="dropdown-item" href="#" data-rel-add="has_many">has_many (reverse foreign key)</a></li><li><hr class="dropdown-divider"></li><li><a class="dropdown-item" href="#" data-rel-detect>${icon('magnifying-glass')} Detect from foreign keys</a></li></ul></div>`);

        const permissions = panel(`${icon('shield-halved')} Permissions`, `<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="r-defperm" ${def.permissions ? '' : 'checked'} ${dis}><label class="form-check-label" for="r-defperm">Use the default permissions <a href="#/settings/auth" class="small">(Settings › Authentication)</a></label></div>
            <div id="r-perms" class="${def.permissions ? '' : 'd-none'}">${C.permissionGrid(def.permissions || {}, ops, 'resource')}</div>
            <p class="gr-help mb-0"><code>service_role</code> always has full access. Row level rules (scopes) can be added in code.</p>`);

        const uploads = panel(`${icon('upload')} Upload fields`, `<table class="gr-table w-100"><thead><tr><th>Field</th><th>Directory</th><th>Allowed types</th><th>Max size</th><th></th></tr></thead><tbody id="upload-rows">${uploadRows()}</tbody></table>
            <p class="gr-help mb-0">Endpoints: <code>POST /{resource}/{id}/upload/{field}</code>, <code>GET /{resource}/{id}/file/{field}</code>, or multipart create / update.</p>`,
            ro ? '' : `<button class="btn btn-sm btn-outline-primary" data-upload-add>${icon('plus')} Add</button>`);

        const filters = panel(`${icon('filter')} Default conditions &amp; order`, `<p class="gr-help">Applied to every read, update and delete (Grocery CRUD where / like / order_by).</p>
            <div id="where-editor">${C.conditionsEditor(def.where, cols, ro)}</div><hr>
            <div id="order-rows">${def.orderBy.map(([c, d]) => C.orderRow(cols, c, d, ro)).join('')}</div>${ro ? '' : `<button class="btn btn-sm btn-outline-secondary mt-1" data-order-add>${icon('plus')} Order by</button>`}`);

        const pane = (id, html) => `<div class="gr-pane ${id === activeTab ? '' : 'd-none'}" data-pane="${id}">${html}</div>`;
        $content().html(
            ((loaded.problems || []).length ? `<div class="alert alert-warning py-2 small">${icon('triangle-exclamation', 'me-1')}${loaded.problems.map(esc).join('<br>')}</div>` : '')
            + (loaded.readOnly && loaded.codeFeatures && loaded.codeFeatures.actions.length ? `<div class="alert alert-info py-2 small">${icon('bolt', 'me-1')}Actions defined in code: ${esc(loaded.codeFeatures.actions.join(', '))}</div>` : '')
            + pane('general', general) + pane('fields', fields) + pane('relations', relationsPane) + pane('permissions', permissions)
            + pane('uploads', uploads) + pane('filters', filters) + pane('preview', '') + pane('code', '')
            + `<div class="gr-sticky-footer">${ro ? `<span class="text-muted small">${icon('lock')} Read only</span>` : `<button class="btn btn-sm btn-primary" id="r-save" data-save>${icon('floppy-disk')} ${isNew ? 'Create endpoint' : 'Save changes'}</button>`}
                ${shell.dirtyBadge()}
                <button class="btn btn-sm btn-outline-secondary" id="r-json">${icon('file-code')} JSON</button>
                ${loaded.source === 'gui' ? `<button class="btn btn-sm btn-outline-danger ms-auto" id="r-delete">${icon('trash')} Delete endpoint</button>` : ''}</div>`);

        // ---------------------------------------------------------------- read the form back

        const $c = $content();

        const collect = () => {
            const d = normalizeDefinition(def);
            d.name = $('#r-name').val().trim();
            d.subject = $('#r-subject').val().trim() || null;
            d.subjectPlural = $('#r-plural').val().trim() || null;
            d.description = $('#r-desc').val().trim();
            d.primaryKey = $('#r-pk').val() || null;
            d.perPage = Number($('#r-perpage').val()) || null;
            d.maxLimit = Number($('#r-maxlimit').val()) || null;
            d.idGenerator = $('#r-idgen').val() || null;
            const limit = String($('#r-rl-requests').val() ?? '').trim();
            d.rateLimit = limit === '' ? null : { requests: Math.max(0, Number(limit) || 0), window: Math.max(1, Number($('#r-rl-window').val()) || 60) };
            $c.find('[data-op]').each(function () { if (!$(this).closest('.gr-perm-grid').length) d.operations[this.dataset.op] = this.checked; });
            d.permissions = $('#r-defperm').is(':checked') ? null : C.readPermissionGrid($c.find('#r-perms .gr-perm-grid'));

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
                    if (!$r.find(`[data-k=${k}]`).is(':checked')) return;
                    if (isRel && k === 'list') relList.push(name);
                    else if (isRel && k === 'read') relRead.push(name);
                    else checked[k].push(name);
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
            d.where = C.readConditions($c.find('#where-editor'));
            d.orderBy = $c.find('#order-rows .gr-order-row').map(function () { return [[$(this).find('[data-k=column]').val(), $(this).find('[data-k=dir]').val()]]; }).get();
            return d;
        };

        const syncFromForm = () => { Object.assign(def, collect()); };
        const currentTab = () => $c.find('.gr-pane:not(.d-none)').data('pane') || 'general';
        /** Re-render after a structural change (relations, uploads) keeping the tab and the unsaved flag. */
        const rerender = () => {
            syncFromForm();
            editor(connection, table, { ...loaded, definition: def }, currentTab());
            shell.markDirty();
        };

        // ---------------------------------------------------------------- tabs

        const panesRender = {
            preview: () => preview($c.find('[data-pane=preview]'), savedName, table, normalizeDefinition(collect())),
            code: () => phpCode($c.find('[data-pane=code]'), collect()),
        };
        const showPane = (id) => {
            $c.find('.gr-pane').addClass('d-none').filter(`[data-pane=${id}]`).removeClass('d-none');
            $('#gr-tabs a').removeClass('active').filter(`[data-pane=${id}]`).addClass('active');
            shell.replaceHash(withQuery(window.location.hash.split('?')[0], { tab: id }));
            if (panesRender[id]) panesRender[id]();
        };
        $('#gr-tabs').off('click.pane').on('click.pane', '[data-pane]', function (e) {
            e.preventDefault();
            showPane(this.dataset.pane);
        });
        if (panesRender[activeTab]) panesRender[activeTab]();

        // ---------------------------------------------------------------- events

        shell.trackDirty($c);
        C.bindPermissionGrid($c, ops);
        C.bindConditionsEditor($c.find('#where-editor'), cols);
        $c.find('#r-defperm').on('change', function () { $c.find('#r-perms').toggleClass('d-none', this.checked); });

        $c.on('click', '[data-upload-add]', () => {
            syncFromForm();
            def.uploads.push({ field: cols[0], path: def.table, allowedTypes: null, maxSize: null });
            rerender();
        });
        $c.on('click', '[data-upload-del]', function (e) {
            e.preventDefault();
            syncFromForm();
            def.uploads.splice(Number(this.dataset.uploadDel), 1);
            rerender();
        });
        $c.on('click', '[data-order-add]', () => $c.find('#order-rows').append(C.orderRow(cols, cols[0], 'asc', false)));
        $c.on('click', '[data-order-del]', function (e) { e.preventDefault(); $(this).closest('.gr-order-row').remove(); });

        $c.on('click', '[data-rel-add]', function (e) { e.preventDefault(); syncFromForm(); relationEditor(connection, table, def, null, this.dataset.relAdd, rerender); });
        $c.on('click', '[data-rel-edit]', function (e) {
            e.preventDefault();
            syncFromForm();
            const r = def.relations[Number(this.dataset.relEdit)];
            relationEditor(connection, table, def, r, r.type, rerender);
        });
        $c.on('click', '[data-rel-del]', function (e) {
            e.preventDefault();
            syncFromForm();
            def.relations.splice(Number(this.dataset.relDel), 1);
            rerender();
        });
        $c.on('click', '[data-rel-detect]', async (e) => {
            e.preventDefault();
            syncFromForm();
            try {
                const generated = await api('resource.generate', { connection, table: table.name });
                const added = generated.relations.filter((r) => !def.relations.some((x) => x.name === r.name));
                def.relations.push(...added);
                toast(added.length ? `${added.length} relation(s) added` : 'No new relations found', added.length ? 'success' : 'info');
                if (added.length) rerender();
            } catch (err) { fail(err); }
        });

        $c.find('#r-json').on('click', () => {
            const json = JSON.stringify(collect(), null, 2);
            modal({ title: 'Endpoint definition (JSON)', size: 'lg', body: codeBlock(json, 'json'),
                buttons: [{ label: `${icon('copy')} Copy`, cls: 'btn-outline-primary', action: () => { copy(json); return false; } }] });
        });

        $c.find('#r-save').on('click', async () => {
            const d = collect();
            if (!/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/.test(d.name)) { toast('Invalid endpoint name', 'warning'); showPane('general'); return; }
            try {
                const r = await api('resource.save', { definition: d, original: original || d.name, enabled: $('#r-enabled').is(':checked') });
                shell.clearDirty();
                toast(isNew ? 'Endpoint created' : 'Endpoint saved');
                await Promise.all([shell.loadTables(connection, true), shell.loadResources(true), shell.loadBoot()]);
                const target = withQuery(link('api', 'r', r.name), { tab: currentTab() });
                if (window.location.hash === target) GR.reload(); else window.location.hash = target;
            } catch (err) { fail(err); }
        });

        $c.find('#r-delete').on('click', async () => {
            if (!await confirmDialog(`Delete endpoint <b>${esc(def.name)}</b>? The table is not touched.`, 'Delete')) return;
            try {
                await api('resource.delete', { name: original });
                shell.clearDirty();
                toast('Endpoint deleted');
                await Promise.all([shell.loadTables(connection, true), shell.loadResources(true)]);
                window.location.hash = '#/api';
            } catch (err) { fail(err); }
        });
    }

    // =====================================================================
    // Relation drawer
    // =====================================================================

    const RELATION_TITLES = { belongs_to: 'belongs_to - set_relation()', many_to_many: 'many_to_many - set_relation_n_n()', has_many: 'has_many - reverse foreign key' };

    async function relationEditor(connection, table, def, existing, type, done) {
        const tables = (await shell.loadTables(connection)).map((t) => t.name);
        const r = existing ? { ...existing } : { type, name: '', table: '' };
        const cols = table.columns.map((c) => c.name);
        const opt = (list, selected, emptyLabel = '') => `${emptyLabel !== null ? `<option value="">${esc(emptyLabel)}</option>` : ''}${list.map((x) => `<option ${x === selected ? 'selected' : ''}>${esc(x)}</option>`).join('')}`;
        const select = (label, name, html, attrs = '', width = 6) => `<div class="col-md-${width}"><label class="form-label small mb-0">${esc(label)}</label><select class="form-select form-select-sm" name="${name}" ${attrs}>${html}</select></div>`;

        const fields = {
            belongs_to: select('Local field (foreign key)', 'field', opt(cols, r.field, null))
                + select('Related table', 'table', opt(tables, r.table, '(choose)'), 'data-load-cols')
                + select('Referenced column', 'ownerKey', opt([], r.ownerKey, 'primary key'), 'data-cols="target"'),
            has_many: select('Child table', 'table', opt(tables, r.table, '(choose)'), 'data-load-cols')
                + select('Foreign key in the child table', 'foreignKey', opt([], r.foreignKey, '(choose)'), 'data-cols="target"')
                + select('Local key', 'ownerKey', opt(cols, r.ownerKey, 'primary key')),
            many_to_many: select('Junction table', 'junctionTable', opt(tables, r.junctionTable, '(choose)'), 'data-load-junction')
                + select('Selection table', 'table', opt(tables, r.table, '(choose)'), 'data-load-cols')
                + select('Junction → this table', 'junctionThisKey', opt([], r.junctionThisKey, '(choose)'), 'data-cols="junction"', 4)
                + select('Junction → selection', 'junctionOtherKey', opt([], r.junctionOtherKey, '(choose)'), 'data-cols="junction"', 4)
                + select('Priority field (junction)', 'priorityField', opt([], r.priorityField, 'none'), 'data-cols="junction"', 4),
        }[type];
        const desc = r.orderBy && r.orderBy[1] === 'desc';

        const $d = drawer({
            title: `${existing ? 'Edit' : 'Add'} relation <span class="text-muted small">${esc(RELATION_TITLES[type])}</span>`,
            size: 'lg',
            body: `<div class="row g-2" id="rel-form">
                <div class="col-md-6"><label class="form-label small mb-0">Relation name (used in select=name(*)${type === 'many_to_many' ? ' and as body key' : ''})</label><input class="form-control form-control-sm" name="name" value="${esc(r.name)}"></div>
                <div class="col-md-6"><label class="form-label small mb-0">Title field / template</label><div class="input-group input-group-sm"><input class="form-control" name="titleField" value="${esc(r.titleField || '')}" placeholder="name or {lastName} {firstName}">
                    <select class="form-select" style="max-width:130px" data-cols="target" data-insert-title aria-label="Insert column">${opt([], null, '+ column')}</select></div></div>
                ${fields}
                ${type !== 'many_to_many' ? `<div class="col-md-6"><label class="form-label small mb-0">Order related rows by</label><div class="input-group input-group-sm"><select class="form-select" name="orderCol" data-cols="target">${opt([], r.orderBy ? r.orderBy[0] : null, 'default')}</select>
                    <select class="form-select" name="orderDir" style="max-width:90px"><option ${desc ? '' : 'selected'}>asc</option><option ${desc ? 'selected' : ''}>desc</option></select></div></div>` : ''}
                <div class="col-12"><label class="form-label small mb-0">Conditions on the related rows</label><div id="rel-where"></div></div>
                </div>`,
            buttons: [{ label: `${icon('check')} Apply`, save: true, action: ($el) => {
                const rel = readRelation($el, type, cols);
                if (!rel) return false;
                const index = existing ? def.relations.indexOf(existing) : -1;
                if (def.relations.some((x, i) => x.name === rel.name && i !== index)) { toast('Duplicate relation name', 'warning'); return false; }
                if (index >= 0) def.relations[index] = rel; else def.relations.push(rel);
                done();
            } }],
        });

        const fillCols = async (selector, tableName) => {
            if (!tableName) return [];
            const s = await shell.structure(connection, tableName);
            const names = s.table.columns.map((c) => c.name);
            $d.find(`[data-cols=${selector}]`).each(function () {
                const $sel = $(this);
                const keep = $sel.find('option').first().prop('outerHTML');
                const name = $sel.attr('name');
                const current = name ? (r[name] ?? (name === 'orderCol' && r.orderBy ? r.orderBy[0] : null)) : null;
                $sel.html(keep + names.map((n) => `<option ${n === current ? 'selected' : ''}>${esc(n)}</option>`).join(''));
            });
            return names;
        };
        const loadTarget = async () => {
            const names = await fillCols('target', $d.find('[name=table]').val());
            const $where = $d.find('#rel-where');
            $where.off().html(C.conditionsEditor(r.where || [], names, false));
            C.bindConditionsEditor($where, names);
            const $name = $d.find('[name=name]');
            if (!$name.val() && $d.find('[name=table]').val()) $name.val($d.find('[name=table]').val().replace(/[^A-Za-z0-9_]/g, '_'));
        };
        $d.find('[data-load-cols]').on('change', loadTarget);
        $d.find('[data-load-junction]').on('change', () => fillCols('junction', $d.find('[name=junctionTable]').val()));
        $d.find('[data-insert-title]').on('change', function () {
            if (!this.value) return;
            const $t = $d.find('[name=titleField]');
            $t.val($t.val() ? `${$t.val()} {${this.value}}` : this.value);
            this.selectedIndex = 0;
        });
        if (r.table) loadTarget(); else $d.find('#rel-where').html(C.conditionsEditor([], [], false));
        if (r.junctionTable) fillCols('junction', r.junctionTable);
    }

    /** Validate the relation drawer; returns the relation or null (after a warning toast). */
    function readRelation($el, type, cols) {
        const v = GR.util.formValues($el.find('#rel-form'));
        const rel = { type, name: (v.name || '').trim(), table: v.table, titleField: v.titleField || null };
        const warn = (message) => { toast(message, 'warning'); return null; };
        if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(rel.name)) return warn('Invalid relation name');
        if (!rel.table) return warn('Choose the related table');
        if (cols.includes(rel.name)) return warn('The relation name collides with a column name');
        if (type === 'belongs_to') Object.assign(rel, { field: v.field, ownerKey: v.ownerKey || null });
        if (type === 'has_many') Object.assign(rel, { foreignKey: v.foreignKey, ownerKey: v.ownerKey || null });
        if (type === 'many_to_many') Object.assign(rel, { junctionTable: v.junctionTable, junctionThisKey: v.junctionThisKey, junctionOtherKey: v.junctionOtherKey, priorityField: v.priorityField || null });
        if ((type === 'has_many' && !rel.foreignKey) || (type === 'many_to_many' && (!rel.junctionTable || !rel.junctionThisKey || !rel.junctionOtherKey))) return warn('Fill in the keys');
        if (v.orderCol) rel.orderBy = [v.orderCol, v.orderDir];
        const where = C.readConditions($el.find('#rel-where'));
        if (where.length) rel.where = where;
        return rel;
    }

    // =====================================================================
    // API preview & PHP code panes
    // =====================================================================

    /** What "auto" means for the primary key of this table. */
    function autoIdLabel(table, def) {
        const pk = table.columns.find((c) => c.name === (def.primaryKey || table.primaryKey[0]));
        if (!pk) return 'no primary key';
        if (pk.autoIncrement) return 'auto increment';
        if (pk.type === 'uuid' && pk.default === null) return 'UUID v4';
        return 'database default or client';
    }

    /**
     * API preview: one topic per operation with SDK / cURL code, parameters, body fields and a sample response.
     */
    function preview($pane, name, table, def) {
        if (!name) {
            $pane.html(empty(`${icon('flask', 'fs-4 d-block mb-2 text-secondary')}Create the endpoint first, then its API preview appears here.`));
            return;
        }
        const S = GR.snippets;
        const c = S.quote(name);
        const columns = Object.fromEntries(table.columns.map((col) => [col.name, col]));
        const pk = def.primaryKey || table.primaryKey[0] || 'id';
        const pkSample = S.sampleValue(columns[pk]);
        const listCols = visibleList(def, table, def.columns, def.unsetColumns);
        const createCols = writable(def, table, 'create');
        const updateCols = writable(def, table, 'update');
        const firstText = listCols.find((col) => columns[col] && ['string', 'text'].includes(columns[col].type)) || listCols[0] || pk;
        const rel = def.relations.find((r) => r.type === 'belongs_to') || def.relations[0];
        const row = Object.fromEntries(listCols.map((col) => [col, S.sampleValue(columns[col])]));
        const body = (cols) => Object.fromEntries(cols.filter((col) => columns[col]).slice(0, 5).map((col) => [col, S.sampleValue(columns[col])]));
        const createBody = body(createCols.filter((col) => col !== pk || def.idGenerator === 'none'));
        const updateBody = body(updateCols.slice(0, 2));
        const select = rel ? `*,${rel.name}(*)` : '*';
        const id = typeof pkSample === 'string' ? `'${pkSample}'` : pkSample;
        const ops = def.operations;

        const fieldsTable = (cols, mode) => `<p class="small fw-semibold mt-3 mb-1">Body fields</p><table class="gr-table w-100"><thead><tr><th>Field</th><th>Type</th><th>Required</th><th>Notes</th></tr></thead><tbody>
            ${cols.map((col) => {
                const column = columns[col];
                const relation = def.relations.find((r) => r.name === col);
                const required = def.requiredFields.includes(col) || (mode === 'create' && column && !column.nullable && !column.autoIncrement && column.default === null && col !== pk);
                const notes = [
                    col === pk && mode === 'create' ? (def.idGenerator === 'none' || (!def.idGenerator && autoIdLabel(table, def) === 'database default or client') ? 'send it' : 'generated when omitted') : '',
                    (def.rules[col] || {}).rules ? 'rules: ' + def.rules[col].rules : '',
                    relation ? 'list of ids of ' + relation.table : '',
                    (def.uploads || []).some((u) => u.field === col) ? 'file (multipart)' : '',
                ].filter(Boolean).join(', ');
                return `<tr><td><code>${esc(col)}</code></td><td>${esc(column ? column.type : 'relation')}</td><td>${required ? `<span class="text-danger">${icon('asterisk')}</span> yes` : ''}</td><td class="small">${esc(notes)}</td></tr>`;
            }).join('') || '<tr><td colspan="4" class="text-muted">No writable fields</td></tr>'}</tbody></table>`;
        const response = (status, value) => `<p class="small fw-semibold mt-3 mb-1">Response <span class="badge text-bg-${status < 300 ? 'success' : 'secondary'}">${status}</span></p>${value === undefined ? '<p class="small text-muted mb-0">No content</p>' : codeBlock(S.json(value, 2), 'json', 'gr-code-light mb-0')}`;
        const head = (method, path, enabled = true) => `<div><span class="gr-method gr-method-${method.toLowerCase()}">${method}</span> <code>${esc(GR.CFG.apiUrl)}/${esc(path)}</code>
            ${enabled ? '' : ` ${GR.html.badge('disabled on this endpoint', 'secondary')}`}</div>`;
        const query = `<p class="small fw-semibold mt-3 mb-1">Query parameters</p><table class="gr-table w-100"><tbody>
            <tr><td><code>select</code></td><td>columns and embedded relations, e.g. <code>${esc(select)}</code></td></tr>
            <tr><td><code>{column}=op.value</code></td><td>filters: eq, neq, gt, gte, lt, lte, like, ilike, in, is, not.; <code>or=(...)</code> / <code>and=(...)</code></td></tr>
            <tr><td><code>order</code></td><td><code>${esc(firstText)}.desc,${esc(pk)}.asc</code> (SDK: <code>sort: '-${esc(firstText)},${esc(pk)}'</code>)</td></tr>
            <tr><td><code>limit</code> / <code>offset</code></td><td>paging (max ${esc(def.maxLimit || store.boot.settings.max_limit)})</td></tr>
            <tr><td><code>q</code></td><td>search in ${esc((def.searchable.length ? def.searchable : listCols.filter((col) => columns[col] && ['string', 'text'].includes(columns[col].type))).join(', ') || 'text columns')}</td></tr>
            <tr><td><code>count=exact</code></td><td>total in the <code>Content-Range</code> header</td></tr></tbody></table>`;

        const topics = [
            {
                id: 'list', icon: 'list', title: 'List / search', text: 'Pages of records with filters, sorting and relations.',
                head: head('GET', name, ops.list),
                code: {
                    javascript: `${S.sdkSetup()}

const { items, totalItems } = await gr.collection(${c}).getList(1, 20, {
    filter: { ${/^[A-Za-z_$][\w$]*$/.test(firstText) ? firstText : S.quote(firstText)}: { ilike: '*text*' } },
    sort: '-${firstText}',
    select: '${select}',
});

// every record (fetched in batches)
const all = await gr.collection(${c}).getFullList({ sort: '${pk}' });`,
                    curl: S.curl('GET', `${name}?${firstText}=ilike.*text*&order=${firstText}.desc&limit=20&count=exact&select=${select}`),
                },
                extra: query + response(200, [row]),
            },
            {
                id: 'view', icon: 'eye', title: 'View', text: 'One record by its primary key.',
                head: head('GET', `${name}/{${pk}}`, ops.read),
                code: {
                    javascript: `${S.sdkSetup()}\n\nconst record = await gr.collection(${c}).getOne(${id}, { select: '${select}' });`,
                    curl: S.curl('GET', `${name}/${pkSample}?select=${select}`),
                },
                extra: response(200, row),
            },
            {
                id: 'create', icon: 'plus', title: 'Create', text: 'A new record (or a JSON array for many).',
                head: head('POST', name, ops.create),
                code: {
                    javascript: `${S.sdkSetup()}\n\nconst record = await gr.collection(${c}).create(${S.literal(createBody)});`,
                    curl: S.curl('POST', name, { body: createBody }),
                },
                extra: fieldsTable(createCols, 'create') + response(201, row),
            },
            {
                id: 'update', icon: 'pen', title: 'Update', text: 'Change some fields of a record.',
                head: head('PATCH', `${name}/{${pk}}`, ops.update),
                code: {
                    javascript: `${S.sdkSetup()}\n\nconst record = await gr.collection(${c}).update(${id}, ${S.literal(updateBody)});`,
                    curl: S.curl('PATCH', `${name}/${pkSample}`, { body: updateBody }),
                },
                extra: fieldsTable(updateCols, 'update') + response(200, row),
            },
            {
                id: 'delete', icon: 'trash', title: 'Delete', text: 'Remove a record.',
                head: head('DELETE', `${name}/{${pk}}`, ops.delete),
                code: {
                    javascript: `${S.sdkSetup()}\n\nawait gr.collection(${c}).delete(${id});`,
                    curl: S.curl('DELETE', `${name}/${pkSample}`),
                },
                extra: response(204),
            },
            ...(def.uploads.length ? [{
                id: 'upload', icon: 'upload', title: 'Upload a file', text: `Upload fields: ${def.uploads.map((u) => u.field).join(', ')}.`,
                head: head('POST', `${name}/{${pk}}/upload/${def.uploads[0].field}`, ops.update),
                code: {
                    javascript: `${S.sdkSetup()}

await gr.collection(${c}).uploadFile(${id}, '${def.uploads[0].field}', fileInput.files[0]);
const url = gr.collection(${c}).getFileUrl(${id}, '${def.uploads[0].field}');`,
                    curl: S.curl('POST', `${name}/${pkSample}/upload/${def.uploads[0].field}`, { form: ['file=@photo.png'] }),
                },
                extra: `<p class="small mt-3 mb-0">Allowed: <code>${esc(def.uploads[0].allowedTypes || store.boot.settings.upload_allowed_types)}</code>, max ${esc(def.uploads[0].maxSize || store.boot.settings.upload_max_size)}.</p>`,
            }] : []),
            {
                id: 'errors', icon: 'triangle-exclamation', title: 'Errors', text: 'Status codes and the error format.',
                code: {
                    javascript: `${S.sdkSetup()}

try {
    await gr.collection(${c}).create({});
} catch (error) {
    error.status;   // 422
    error.code;     // "validation_failed"
    error.details;  // { fields: { ... } }
}`,
                },
                extra: `<table class="gr-table w-100 mt-3"><tbody>
                    <tr><td>400</td><td>invalid query or body</td></tr><tr><td>401 / 403</td><td>missing key or token / role not allowed (Permissions tab)</td></tr>
                    <tr><td>404</td><td>record not found (or hidden by row level security)</td></tr><tr><td>409</td><td>database constraint (duplicate, foreign key)</td></tr>
                    <tr><td>422</td><td>validation failed: <code>details.fields</code></td></tr><tr><td>429</td><td>rate limit reached: retry after <code>Retry-After</code> seconds</td></tr></tbody></table>
                    ${codeBlock(S.json({ error: { code: 'validation_failed', message: 'The given data is invalid.', details: { fields: { [firstText]: 'The field is required.' } } } }, 2), 'json', 'gr-code-light mb-0')}`,
            },
        ];

        $pane.off().html(`<div class="d-flex flex-wrap gap-2 align-items-center mb-2"><span class="small text-muted me-auto">Based on the current configuration (unsaved changes included). Base URL <code>${esc(absoluteApiUrl())}</code></span>
            <a class="btn btn-sm btn-outline-primary" href="${esc(withQuery('#/api/console', { method: 'GET', path: name + '?limit=5', run: 1 }))}">${icon('terminal')} Try in console</a>
            <a class="btn btn-sm btn-outline-secondary" href="${esc(absoluteApiUrl())}/sdk.js" target="_blank" rel="noopener">${icon('file-code')} sdk.js</a></div>`
            + S.explorer(topics));
        S.bindExplorer($pane);
    }

    async function phpCode($pane, def) {
        $pane.html(GR.html.loading());
        let r;
        try { r = await api('resource.code', { definition: def }); } catch (err) { $pane.html(`<div class="alert alert-danger">${GR.util.errorMessage(err)}</div>`); return; }
        const indented = r.code.split('\n').map((l) => (l ? '        ' + l : l)).join('\n');
        const className = (def.name || 'resource').replace(/(^|[_-])(\w)/g, (m, sep, ch) => ch.toUpperCase());
        const controllerBody = r.code
            .replace(/^\$api->resource\(([^)]*)\)/, (m, args) => `        $crud->setTable(${args.split(',').pop().trim()})`)
            .split('\n').map((l, i) => (i === 0 ? l : '        ' + l)).join('\n');
        const blocks = [
            ['code', 'Fluent definition <span class="text-muted fw-normal small">reflects unsaved changes</span>', '<?php\n\n' + r.code],
            ['fire', 'CodeIgniter 4 - central definition <span class="text-muted fw-normal small">app/Config/GroceryRestable.php</span>', `<?php

namespace Config;

use GroceryRestable\\CodeIgniter4\\Config\\GroceryRestable as BaseGroceryRestable;

class GroceryRestable extends BaseGroceryRestable
{
    public function define(\\GroceryRestable\\GroceryRestable $api): void
    {
${indented}    }
}`],
            ['fire', 'CodeIgniter 4 - Grocery CRUD style controller', `<?php

// app/Config/Routes.php:
//   \\GroceryRestable\\CodeIgniter4\\Routes::resource($routes, 'crud/${def.name}', '${className}::index');

namespace App\\Controllers;

use GroceryRestable\\CodeIgniter4\\Libraries\\GroceryRestable;

class ${className} extends BaseController
{
    public function index(string ...$segments)
    {
        $crud = new GroceryRestable();
${controllerBody}
        return $crud->render($segments);
    }
}`],
        ];
        $pane.html(blocks.map(([iconName, title, code], i) => panel(`${icon(iconName)} ${title}`, codeBlock(code, 'php'), `<button class="btn btn-sm btn-outline-secondary" data-copy-code="${i}">${icon('copy')} Copy</button>`)).join(''));
        $pane.off('click').on('click', '[data-copy-code]', function () { copy(blocks[Number(this.dataset.copyCode)][2]); });
    }

    GR.pages = GR.pages || {};
    GR.pages.resource = { edit, create };
})(window.GR = window.GR || {}, jQuery);
