/*!
 * Grocery Restable admin - Data area: phpMyAdmin-style database tree, table list,
 * row browser with a side drawer editor, and table structure.
 */
(function (GR, $) {
    'use strict';

    const { esc, icon, badge, sourceBadge, panel, empty, link, withQuery, cell } = GR.html;
    const { absoluteApiUrl } = GR.util;
    const { api } = GR.http;
    const { toast, fail, drawer, confirmDialog } = GR.ui;
    const shell = GR.shell;
    const store = GR.store;
    const $content = shell.$content;

    const PER_PAGE = [10, 25, 50, 100, 250, 500];
    const EXPOSED_BECAUSE = { auto: 'generated automatically because auto expose is on', gui: 'configured in the API area', code: 'defined in your application code' };

    // =====================================================================
    // Sidebar: connections and their tables
    // =====================================================================

    const dots = (t) => (t.resources.length
        ? t.resources.map((r) => `<span class="gr-dot gr-dot-${esc(r.source)}" title="${esc(GR.CFG.apiUrl)}/${esc(r.name)} (${esc(r.source)})"></span>`).join('')
        : '<span class="gr-dot gr-dot-none" title="not exposed"></span>');

    function tableNodes(connection, filter) {
        const tables = store.tables[connection];
        if (!tables) {
            shell.loadTables(connection);
            return `<ul><li class="text-muted small ps-3">${icon('spinner', 'fa-spin')} loading…</li></ul>`;
        }
        if (tables.error) return `<ul><li class="text-danger small ps-3">${icon('triangle-exclamation')} ${esc(tables.error)}</li></ul>`;
        const visible = tables.filter((t) => !filter || t.name.toLowerCase().includes(filter));
        if (!visible.length) return `<ul><li class="text-muted small ps-3">${filter ? 'No match' : 'No tables'}</li></ul>`;
        return '<ul>' + visible.map((t) => {
            const href = link('data', connection, t.name);
            return `<li><a class="gr-node ${shell.isCurrent(href, true) ? 'active' : ''}" href="${href}">
                ${icon(t.type === 'view' ? 'eye' : 'table')}<span class="gr-label">${esc(t.name)}</span>${dots(t)}</a></li>`;
        }).join('') + '</ul>';
    }

    shell.defineNav('data', {
        head: () => shell.navHead('Databases', 'database', `<a href="#" class="gr-nav-action" data-action="reload-tree" title="Refresh schema (detect new tables and columns)">${icon('rotate')}</a>`, 'Filter tables'),
        body: (filter) => '<ul class="gr-tree">' + store.boot.connections.map((c) => {
            const open = store.expanded[c.name] !== false;
            const href = link('data', c.name);
            return `<li><div class="gr-node ${shell.isCurrent(href) ? 'active' : ''}">
                <a href="#" class="gr-toggle" data-toggle-conn="${esc(c.name)}" title="Expand / collapse">${icon(open ? 'minus' : 'plus')}</a>
                <a class="gr-node-link" href="${href}">${icon('database')}<span class="gr-label">${esc(c.name)}</span></a>
                ${c.default ? badge('default', 'primary') : ''}</div>${open ? tableNodes(c.name, filter) : ''}</li>`;
        }).join('') + '</ul>',
        foot: () => `<div class="gr-nav-legend small">API: <span class="gr-dot gr-dot-code"></span> code <span class="gr-dot gr-dot-gui"></span> GUI
            <span class="gr-dot gr-dot-auto"></span> auto <span class="gr-dot gr-dot-none"></span> none</div>`,
    });

    // =====================================================================
    // Routes
    // =====================================================================

    /** GET /data → the default connection. */
    function index(req, res) {
        const connection = shell.defaultConnection();
        if (!connection) {
            shell.setBreadcrumb([{ label: 'Data', icon: 'table' }]);
            $content().html(empty(`No database connection yet. <a href="#/settings/connections">Add one in Settings</a>.`));
            return;
        }
        return res.redirect(link('data', connection));
    }

    /** GET /data/:conn - tables of a connection (phpMyAdmin "database structure"). */
    async function database(req) {
        const { conn } = req.params;
        const c = store.boot.connections.find((x) => x.name === conn);
        if (!c) throw { message: `Unknown connection "${esc(conn)}".` };
        store.expanded[conn] = true;
        shell.setBreadcrumb([{ label: 'Database: ' + conn, icon: 'database' }]);
        shell.setTabs([{ id: 'tables', icon: 'table-list', label: 'Tables', href: link('data', conn) }], 'tables');

        const tables = await shell.loadTables(conn, true);
        if (!Array.isArray(tables)) throw { message: esc(tables.error) };
        const engine = (store.boot.engines[c.driver] || {}).label || c.driver || c.client || '';
        const endpoint = (t) => (t.resources.length
            ? t.resources.map((r) => `<a href="${link('api', 'r', r.name)}"><code>/${esc(r.name)}</code></a> ${sourceBadge(r.source)}`).join('<br>')
            : `<span class="badge gr-badge-none">not exposed</span> <a href="${link('api', 'new', conn, t.name)}" class="small">expose…</a>`);
        const rows = tables.map((t) => `<tr><td>${icon(t.type === 'view' ? 'eye' : 'table', 'text-secondary')} <a href="${link('data', conn, t.name)}"><b>${esc(t.name)}</b></a></td>
            <td>${esc(t.type)}</td><td class="text-end">${esc(t.columns)}</td><td>${esc((t.primaryKey || []).join(', '))}</td><td>${endpoint(t)}</td>
            <td class="gr-actions-cell"><a href="${link('data', conn, t.name, 'browse')}">${icon('table-list')} Browse</a><a href="${link('data', conn, t.name, 'structure')}">${icon('sitemap')} Structure</a></td></tr>`).join('');

        $content().html(panel(`${icon('database')} ${esc(conn)} ${c.default ? badge('default', 'primary') : ''} <span class="text-muted fw-normal small">${esc(engine)} ${esc(c.database || '')} · ${esc(tables.length)} tables</span>`,
            tables.length
                ? `<div class="table-responsive"><table class="gr-table w-100 mb-0"><thead><tr><th>Table</th><th>Type</th><th>Columns</th><th>Primary key</th><th>API endpoint</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`
                : empty('This database has no tables yet. Create one with your usual tools, then click <b>Refresh schema</b> in the sidebar.'),
            `<a class="btn btn-sm btn-outline-secondary" href="#/settings/connections">${icon('gear')} Connections</a>`));
    }

    /** GET /data/:conn/:table/:tab? - browse (default) or structure. */
    async function table(req, res) {
        const { conn, table: name } = req.params;
        const tab = req.params.tab || 'browse';
        if (!['browse', 'structure'].includes(tab)) return res.redirect(link('data', conn, name));
        store.expanded[conn] = true;
        shell.setBreadcrumb([{ label: 'Database: ' + conn, icon: 'database', href: link('data', conn) }, { label: 'Table: ' + name, icon: 'table', href: link('data', conn, name) }]);
        shell.setTabs([
            { id: 'browse', icon: 'table-list', label: 'Browse', href: link('data', conn, name, 'browse') },
            { id: 'structure', icon: 'sitemap', label: 'Structure', href: link('data', conn, name, 'structure') },
        ], tab);

        const info = await shell.structure(conn, name);
        renderStatus(conn, name, await shell.tableResources(conn, name));
        return tab === 'browse' ? browse(conn, info.table, req.query) : structure(conn, info);
    }

    /** One line above the content telling whether / how the table is published by the API. */
    function renderStatus(connection, table, resources) {
        if (!resources.length) {
            shell.setStatus(`<div class="gr-status gr-status-off">${icon('plug-circle-xmark')} <span><b>${esc(table)}</b> is not available through the API.</span>
                <button class="btn btn-sm btn-primary py-0" id="status-expose">${icon('plug')} Expose with defaults</button>
                <a href="${link('api', 'new', connection, table)}">${icon('sliders')} Configure first</a></div>`);
            $('#status-expose').on('click', async () => {
                try { await shell.exposeTable(connection, table); GR.reload(); } catch (err) { fail(err); }
            });
            return;
        }
        shell.setStatus(resources.map((r) => `<div class="gr-status gr-status-on">${icon('plug-circle-check')}
            <span>API endpoint <a href="${esc(absoluteApiUrl())}/${encodeURIComponent(r.name)}" target="_blank" rel="noopener"><code>${esc(GR.CFG.apiUrl)}/${esc(r.name)}</code></a> ${sourceBadge(r.source)}
            <span class="text-muted">- ${esc(EXPOSED_BECAUSE[r.source] || r.source)}</span></span>
            <a href="${link('api', 'r', r.name)}">${icon('sliders')} API settings</a>
            <a href="${withQuery(link('api', 'r', r.name), { tab: 'preview' })}">${icon('flask')} API preview</a></div>`).join(''));
    }

    // =====================================================================
    // Browse
    // =====================================================================

    async function browse(connection, table, query) {
        const params = {
            page: Number(query.page || 1),
            perPage: Number(query.perPage || 25),
            order: query.order || '',
            dir: query.dir || 'asc',
            search: query.search || '',
        };
        const data = await api('table.browse', { connection, table: table.name, ...params });
        const pages = Math.max(1, Math.ceil(data.total / data.perPage));
        const pk = data.primaryKey;
        const editable = pk.length > 0;
        const base = link('data', connection, table.name, 'browse');
        const go = (changes) => withQuery(base, { ...params, ...changes });
        const reload = () => browse(connection, table, query);

        const pageButton = (target, iconName, disabled) => `<a class="btn btn-sm btn-outline-secondary ${disabled ? 'disabled' : ''}" href="${go({ page: target })}">${icon(iconName)}</a>`;
        const pager = `<div class="gr-pagination">
            ${pageButton(1, 'backward-step', params.page <= 1)}${pageButton(params.page - 1, 'chevron-left', params.page <= 1)}
            <span class="small">Page <b>${esc(params.page)}</b> of ${esc(pages)} · ${esc(data.total)} rows</span>
            ${pageButton(params.page + 1, 'chevron-right', params.page >= pages)}${pageButton(pages, 'forward-step', params.page >= pages)}
            <select class="form-select form-select-sm ms-2" data-per-page aria-label="Rows per page">${PER_PAGE.map((n) => `<option ${n === params.perPage ? 'selected' : ''}>${n}</option>`).join('')}</select></div>`;

        const toolbar = `<div class="gr-toolbar">
            <form class="d-flex gap-1" id="search-form"><input type="search" class="form-control form-control-sm" name="search" value="${esc(params.search)}" placeholder="Search all columns" aria-label="Search">
                <button class="btn btn-sm btn-outline-secondary" title="Search">${icon('magnifying-glass')}</button></form>
            ${editable ? `<button class="btn btn-sm btn-outline-danger d-none" id="delete-picked">${icon('trash')} Delete selected (<span>0</span>)</button>` : ''}
            <span class="ms-auto"></span>
            <button class="btn btn-sm btn-outline-secondary" id="reload-rows" title="Reload">${icon('rotate')}</button>
            ${table.type === 'table' && editable ? `<button class="btn btn-sm btn-primary" id="insert-row">${icon('plus')} New row</button>` : ''}</div>`;

        const header = data.columns.map((c) => `<th class="sortable" data-sort="${esc(c.name)}">${esc(c.name)}${params.order === c.name ? ' ' + icon(params.dir === 'asc' ? 'caret-up' : 'caret-down') : ''}${c.primary ? ' ' + icon('key', 'text-warning') : ''}</th>`).join('');
        const rows = data.rows.map((row, i) => `<tr data-row="${i}" class="gr-clickable">
            ${editable ? `<td class="gr-actions-cell"><input type="checkbox" class="form-check-input me-2" data-pick="${i}" aria-label="Select row"><a href="#" data-edit="${i}" title="Edit">${icon('pen')}</a><a href="#" class="text-danger" data-del="${i}" title="Delete">${icon('trash')}</a></td>` : ''}
            ${data.columns.map((c) => `<td class="gr-cell" title="${esc(row[c.name] === null ? 'NULL' : typeof row[c.name] === 'object' ? JSON.stringify(row[c.name]) : row[c.name])}">${cell(row[c.name])}</td>`).join('')}</tr>`).join('');

        $content().off().html(toolbar + pager + `<div class="table-responsive"><table class="gr-table w-100"><thead><tr>
            ${editable ? '<th><input type="checkbox" class="form-check-input" data-pick-all aria-label="Select all"></th>' : ''}${header}</tr></thead>
            <tbody>${rows || `<tr><td colspan="${data.columns.length + 1}" class="text-center text-muted py-3">${params.search ? 'No rows match the search' : 'No rows yet'}</td></tr>`}</tbody></table></div>`
            + (data.rows.length > 10 ? pager : '')
            + `<p class="gr-help">${editable ? 'Click a row to edit it. ' : ''}Edits here go straight to the database (API rules and callbacks are not involved).</p>`);

        const $c = $content();
        const keyOf = (row) => Object.fromEntries(pk.map((k) => [k, row[k]]));
        const open = (i) => (editable ? rowEditor(connection, table, data.rows[i], keyOf(data.rows[i]), reload) : rowViewer(table, data.rows[i]));
        const picked = () => $c.find('[data-pick]:checked').map(function () { return data.rows[Number(this.dataset.pick)]; }).get();
        const syncPicked = () => {
            const count = picked().length;
            $c.find('#delete-picked').toggleClass('d-none', count === 0).find('span').text(count);
        };

        $c.on('click', '[data-sort]', function () {
            const order = this.dataset.sort;
            window.location.hash = go({ page: 1, order, dir: params.order === order && params.dir === 'asc' ? 'desc' : 'asc' });
        });
        $c.on('change', '[data-per-page]', function () { window.location.hash = go({ page: 1, perPage: this.value }); });
        $c.on('submit', '#search-form', function (e) {
            e.preventDefault();
            window.location.hash = go({ page: 1, search: $(this).find('input').val() });
        });
        $c.on('click', '#reload-rows', reload);
        $c.on('click', '#insert-row', () => rowEditor(connection, table, null, null, reload));
        $c.on('click', 'tr[data-row]', function (e) {
            if ($(e.target).closest('a, button, input, label').length) return;
            open(Number(this.dataset.row));
        });
        $c.on('click', '[data-edit]', function (e) { e.preventDefault(); open(Number(this.dataset.edit)); });
        $c.on('change', '[data-pick]', syncPicked);
        $c.on('change', '[data-pick-all]', function () { $c.find('[data-pick]').prop('checked', this.checked); syncPicked(); });

        $c.on('click', '[data-del]', async function (e) {
            e.preventDefault();
            const row = data.rows[Number(this.dataset.del)];
            if (!await confirmDialog(`Delete row <code>${esc(JSON.stringify(keyOf(row)))}</code> from <b>${esc(table.name)}</b>?`, 'Delete')) return;
            try { await api('table.row.delete', { connection, table: table.name, key: keyOf(row) }); toast('Row deleted'); reload(); } catch (err) { fail(err); }
        });
        $c.on('click', '#delete-picked', async () => {
            const rows = picked();
            if (!await confirmDialog(`Delete <b>${esc(rows.length)}</b> selected row(s) from <b>${esc(table.name)}</b>?`, 'Delete')) return;
            let deleted = 0;
            try {
                for (const row of rows) {
                    await api('table.row.delete', { connection, table: table.name, key: keyOf(row) });
                    deleted++;
                }
                toast(`${deleted} row(s) deleted`);
            } catch (err) {
                fail(err);
            }
            reload();
        });
    }

    // ---------------------------------------------------------------- row drawer

    /** Random UUID v4 (crypto.randomUUID needs a secure context, getRandomValues does not). */
    function uuid4() {
        if (window.crypto.randomUUID && window.isSecureContext) return window.crypto.randomUUID();
        const b = window.crypto.getRandomValues(new Uint8Array(16));
        b[6] = (b[6] & 0x0f) | 0x40;
        b[8] = (b[8] & 0x3f) | 0x80;
        const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
        return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
    }

    function fieldControl(c, id, value, binary) {
        if (c.enumValues && c.enumValues.length) {
            return `<select class="form-select form-select-sm" id="${id}" name="${esc(c.name)}">${c.enumValues.map((v) => `<option ${v === value ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select>`;
        }
        if (c.type === 'text' || c.type === 'json') {
            return `<textarea class="form-control form-control-sm font-monospace" rows="3" id="${id}" name="${esc(c.name)}">${esc(value)}</textarea>`;
        }
        return `<input class="form-control form-control-sm" id="${id}" name="${esc(c.name)}" value="${esc(value)}" ${binary ? 'disabled' : ''}>`;
    }

    function rowField(c, row, i) {
        const value = row ? row[c.name] : null;
        const isNull = row ? value === null : (c.nullable && c.default === null);
        const text = value === null || value === undefined ? '' : (typeof value === 'object' ? JSON.stringify(value) : String(value));
        const binary = typeof value === 'string' && value.startsWith('[BINARY ');
        // On insert, columns without a default must be written; the others fall back to the database default.
        const required = !row && !c.autoIncrement && (c.default === null || c.default === undefined) && !c.nullable;
        const hint = row ? '' : c.autoIncrement ? 'auto increment' : c.default !== null && c.default !== undefined ? `default: ${c.default}` : '';
        const id = `gr-field-${i}`;
        return `<div class="gr-field ${required ? 'gr-included' : ''}" data-col="${esc(c.name)}">
            <div class="gr-field-head"><label for="${id}">${esc(c.name)}</label>${c.primary ? icon('key', 'text-warning') : ''}
                <span class="gr-field-type">${esc(c.nativeType)}</span>${hint ? `<span class="gr-field-type">· ${esc(hint)}</span>` : ''}
                <span class="gr-field-flag">${row ? 'changed' : 'set'}</span>
                ${!row && c.primary && !c.autoIncrement ? `<button type="button" class="btn btn-link btn-sm p-0 ms-1" data-gen-uuid title="Fill in a random UUID (v4)">${icon('wand-magic-sparkles')} UUID</button>` : ''}
                ${c.nullable ? `<label class="gr-null-toggle"><input type="checkbox" class="form-check-input" data-null ${isNull ? 'checked' : ''} ${binary ? 'disabled' : ''}> NULL</label>` : ''}</div>
            ${fieldControl(c, id, text, binary)}</div>`;
    }

    function rowEditor(connection, table, row, key, reload) {
        const columns = Object.values(table.columns).filter((c) => !c.generated);
        const $d = drawer({
            title: row ? `${icon('pen')} Edit row in <b>${esc(table.name)}</b> <code class="small">${esc(JSON.stringify(key))}</code>` : `${icon('plus')} New row in <b>${esc(table.name)}</b>`,
            size: 'lg',
            body: `<form class="gr-row-form" novalidate>${columns.map((c, i) => rowField(c, row, i)).join('')}<button type="submit" class="d-none"></button></form>
                <p class="gr-help mb-0">Only highlighted fields are written: change a value to include it. Values are bound as parameters.</p>`,
            buttons: [
                ...(row ? [{ label: `${icon('trash')} Delete`, cls: 'btn-outline-danger', start: true, action: async () => {
                    if (!await confirmDialog(`Delete this row from <b>${esc(table.name)}</b>?`, 'Delete')) return false;
                    await api('table.row.delete', { connection, table: table.name, key });
                    toast('Row deleted');
                    reload();
                } }] : []),
                { label: `${icon('floppy-disk')} ${row ? 'Save changes' : 'Create'}`, save: true, action: async ($el) => {
                    const values = {};
                    $el.find('.gr-field.gr-included').each(function () {
                        const $f = $(this);
                        values[this.dataset.col] = $f.find('[data-null]').is(':checked') ? null : $f.find('[name]').val();
                    });
                    if (row && !Object.keys(values).length) { toast('Nothing changed', 'info'); return false; }
                    await api('table.row.save', { connection, table: table.name, key, values });
                    toast(row ? 'Row updated' : 'Row created');
                    reload();
                } },
            ],
        });
        $d.find('.gr-field [name]').on('input change', function () {
            $(this).closest('.gr-field').addClass('gr-included').find('[data-null]').prop('checked', false);
        });
        $d.find('[data-null]').on('change', function () { $(this).closest('.gr-field').addClass('gr-included'); });
        $d.find('[data-gen-uuid]').on('click', function () { $(this).closest('.gr-field').find('[name]').val(uuid4()).trigger('input'); });
        $d.find('.gr-row-form').on('submit', (e) => { e.preventDefault(); $d.find('[data-save]').trigger('click'); });
    }

    /** Read-only view of a row of a table without primary key (or a view). */
    function rowViewer(table, row) {
        drawer({
            title: `${icon('eye')} Row of <b>${esc(table.name)}</b>`,
            size: 'lg',
            body: `<table class="gr-table gr-kv w-100">${Object.entries(row).map(([k, v]) => `<tr><th>${esc(k)}</th><td class="text-break">${cell(v)}</td></tr>`).join('')}</table>
                <p class="gr-help mb-0">Rows can only be edited when the table has a primary key.</p>`,
        });
    }

    // =====================================================================
    // Structure
    // =====================================================================

    function structure(connection, info) {
        const t = info.table;
        const fkOf = (col) => t.foreignKeys.find((f) => f.columns.includes(col));
        const tableLink = (name) => `<a href="${link('data', connection, name, 'structure')}">${esc(name)}</a>`;
        const columnRow = (c, i) => {
            const fk = fkOf(c.name);
            return `<tr><td>${i + 1}</td><td><b>${esc(c.name)}</b></td><td><code>${esc(c.nativeType)}</code></td>
                <td>${badge(c.type, 'light')}${c.enumValues.length ? ` <span class="small text-muted">${esc(c.enumValues.join(', '))}</span>` : ''}</td>
                <td>${c.nullable ? 'Yes' : 'No'}</td><td>${c.default === null ? '<span class="gr-null">None</span>' : `<code>${esc(c.default)}</code>`}</td>
                <td>${c.primary ? `${icon('key', 'text-warning')} PRI` : ''}${fk ? ` ${icon('link', 'text-primary')} ${tableLink(fk.referencedTable)}.${esc(fk.referencedColumns.join(','))}` : ''}</td>
                <td>${c.autoIncrement ? 'auto_increment' : ''}${c.generated ? ' generated' : ''}</td><td>${esc(c.comment)}</td></tr>`;
        };

        $content().html(panel(`${icon('sitemap')} Structure of <b>${esc(t.name)}</b> ${badge(t.type, t.type === 'view' ? 'warning' : 'secondary')}`,
            `<div class="table-responsive"><table class="gr-table w-100"><thead><tr><th>#</th><th>Name</th><th>Type</th><th>API type</th><th>Null</th><th>Default</th><th>Key</th><th>Extra</th><th>Comment</th></tr></thead>
            <tbody>${t.columns.map(columnRow).join('')}</tbody></table></div>`,
            `<button class="btn btn-sm btn-outline-secondary" id="refresh-structure">${icon('rotate')} Refresh</button>`)
            + panel(`${icon('link')} Foreign keys`, t.foreignKeys.length ? `<table class="gr-table w-100"><thead><tr><th>Name</th><th>Columns</th><th>References</th></tr></thead><tbody>
                ${t.foreignKeys.map((f) => `<tr><td>${esc(f.name)}</td><td>${esc(f.columns.join(', '))}</td><td>${tableLink(f.referencedTable)} (${esc(f.referencedColumns.join(', '))})</td></tr>`).join('')}</tbody></table>` : '<p class="text-muted small mb-0">None</p>')
            + panel(`${icon('arrow-right-to-bracket')} Referenced by`, info.referencedBy.length ? `<table class="gr-table w-100"><thead><tr><th>Table</th><th>Columns</th><th>References</th></tr></thead><tbody>
                ${info.referencedBy.map((f) => `<tr><td>${tableLink(f.table)}</td><td>${esc(f.columns.join(', '))}</td><td>${esc(f.referencedColumns.join(', '))}</td></tr>`).join('')}</tbody></table>` : '<p class="text-muted small mb-0">None</p>'));

        $('#refresh-structure').on('click', async () => {
            try {
                await api('schema.refresh', { connection });
                store.structure = {};
                await shell.loadTables(connection, true);
                GR.reload();
            } catch (err) { fail(err); }
        });
    }

    // =====================================================================
    // Events of the sidebar
    // =====================================================================

    function init() {
        $(document).on('click', '[data-toggle-conn]', function (e) {
            e.preventDefault();
            const name = this.dataset.toggleConn;
            store.expanded[name] = store.expanded[name] === false;
            shell.renderNav();
        });
    }

    GR.pages = GR.pages || {};
    GR.pages.data = { init, index, database, table };
})(window.GR = window.GR || {}, jQuery);
