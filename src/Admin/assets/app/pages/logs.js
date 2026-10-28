/*!
 * Grocery Restable admin - Logs area: JSON logs (one file per day) of API requests and admin actions.
 *
 *   GET /logs              today
 *   GET /logs/:day?status=4xx&type=request&search=...&page=2
 */
(function (GR, $) {
    'use strict';

    const { esc, icon, badge, panel, empty, link, withQuery, codeBlock, cell } = GR.html;
    const { formatBytes } = GR.util;
    const { api } = GR.http;
    const { toast, fail, drawer, confirmDialog } = GR.ui;
    const shell = GR.shell;
    const store = GR.store;
    const $content = shell.$content;

    const REFRESH_MS = 5000;
    const PER_PAGE = 50;
    const FILTERS = ['type', 'level', 'status', 'method', 'search'];
    const LEVEL = { info: ['circle-info', 'secondary'], warning: ['triangle-exclamation', 'warning'], error: ['circle-xmark', 'danger'] };

    const today = () => {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    };

    // =====================================================================
    // Sidebar: one entry per day
    // =====================================================================

    shell.defineNav('logs', {
        head: () => shell.navHead('Logs', 'list-ul', `<a href="#" class="gr-nav-action" data-action="logs-cleanup" title="Delete old log files">${icon('broom')} Clean up</a>`, 'Filter days'),
        body: (filter) => {
            if (!store.logDays) {
                api('logs.days').then((days) => { store.logDays = days; shell.renderNav(); }, () => {});
                return `<div class="text-muted small ps-3">${icon('spinner', 'fa-spin')} loading…</div>`;
            }
            const days = store.logDays.filter((d) => !filter || d.day.includes(filter));
            const hasToday = store.logDays.some((d) => d.day === today());
            return `<ul class="gr-menu">${hasToday ? '' : shell.navItem({ href: link('logs', today()), iconName: 'calendar-day', label: today() + ' (today)' })}
                ${days.map((d) => shell.navItem({ href: link('logs', d.day), iconName: d.day === today() ? 'calendar-day' : 'calendar', label: d.day + (d.day === today() ? ' (today)' : ''), extra: `<span class="gr-nav-meta">${esc(formatBytes(d.size))}</span>` })).join('')}</ul>
                ${store.logDays.length ? '' : '<div class="text-muted small ps-3 mt-1">No log files yet</div>'}`;
        },
        foot: () => `<ul class="gr-menu">${shell.navItem({ href: '#/settings/logging', iconName: 'sliders', label: 'Logging settings' })}</ul>`,
    });

    // =====================================================================
    // Page
    // =====================================================================

    function index(req, res) {
        return res.redirect(link('logs', today()));
    }

    async function day(req) {
        const date = req.params.day;
        const filters = Object.fromEntries(FILTERS.map((k) => [k, req.query[k] || '']));
        const page = Math.max(1, Number(req.query.page || 1));
        const live = req.query.live === '1' && date === today();
        shell.setBreadcrumb([{ label: 'Logs', icon: 'list-ul', href: '#/logs' }, { label: date, icon: 'calendar' }]);

        const view = { entries: [] };
        const render = async () => {
            const result = await api('logs.read', { day: date, filters, page, perPage: PER_PAGE });
            view.entries = result.entries;
            $content().find('#log-results').html(results(date, result, filters, page));
        };

        $content().html(toolbar(date, filters, live) + '<div id="log-results">' + GR.html.loading() + '</div>');
        await render();
        bind(date, filters, view);
        store.logDays = null; // file sizes changed
        shell.renderNav();

        clearInterval(store.logTimer);
        if (live) {
            const hash = window.location.hash;
            store.logTimer = setInterval(() => {
                if (window.location.hash !== hash) { clearInterval(store.logTimer); return; }
                render().catch(() => {});
            }, REFRESH_MS);
        }
    }

    const go = (date, changes) => {
        const params = Object.fromEntries(Object.entries(changes).filter(([, v]) => v !== '' && v !== undefined && v !== null));
        window.location.hash = Object.keys(params).length ? withQuery(link('logs', date), params) : link('logs', date);
    };

    function toolbar(date, filters, live) {
        const select = (name, label, values) => `<select class="form-select form-select-sm w-auto" name="${name}" aria-label="${esc(label)}">
            <option value="">${esc(label)}: all</option>${values.map(([v, l]) => `<option value="${esc(v)}" ${filters[name] === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
        return `<form class="gr-toolbar" id="log-filters">
            ${select('type', 'Type', [['request', 'API requests'], ['admin', 'Admin actions']])}
            ${select('level', 'Level', [['info', 'Info'], ['warning', 'Warning'], ['error', 'Error']])}
            ${select('status', 'Status', [['2xx', '2xx success'], ['3xx', '3xx'], ['4xx', '4xx client error'], ['5xx', '5xx server error']])}
            ${select('method', 'Method', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => [m, m]))}
            <input type="search" class="form-control form-control-sm" name="search" value="${esc(filters.search)}" placeholder="Search path, IP, role, message" aria-label="Search" style="max-width:16rem">
            <button class="btn btn-sm btn-outline-secondary" title="Apply">${icon('magnifying-glass')}</button>
            ${date === today() ? `<div class="form-check form-switch ms-1 mb-0"><input class="form-check-input" type="checkbox" id="log-live" ${live ? 'checked' : ''}>
                <label class="form-check-label small" for="log-live">Live (every ${REFRESH_MS / 1000}s)</label></div>` : ''}
            <span class="ms-auto"></span>
            <a class="btn btn-sm btn-outline-secondary" href="${esc(GR.CFG.adminUrl)}/log?day=${encodeURIComponent(date)}" title="Download the JSON lines file">${icon('download')} Download</a>
            <button type="button" class="btn btn-sm btn-outline-danger" id="log-delete">${icon('trash')} Delete day</button></form>`;
    }

    // ---------------------------------------------------------------- results

    function results(date, result, filters, page) {
        if (!result.total) {
            const filtered = FILTERS.some((k) => filters[k]);
            return empty(`${icon('list-ul', 'fs-4 d-block mb-2 text-secondary')}${filtered ? 'No entries match these filters.' : `No log entries for ${esc(date)}.`}
                <div class="small mt-1">API requests and admin actions are written to <code>${esc(store.boot.logPath)}</code>.</div>`);
        }
        const s = result.stats;
        const pages = Math.max(1, Math.ceil(result.total / result.perPage));
        return tiles(s) + `<div class="row g-3">
            <div class="col-xl-8">${panel(`${icon('chart-column')} Entries per hour`, hourChart(s.hours))}</div>
            <div class="col-xl-4">${panel(`${icon('ranking-star')} Top`, topList('Endpoints', s.topResources) + topList('Clients (IP)', s.topClients))}</div></div>`
            + panel(`${icon('list')} Entries <span class="text-muted fw-normal small">${esc(result.total)} matching, newest first</span>`, entriesTable(result.entries),
                '', pages > 1 ? pager(date, filters, page, pages) : '');
    }

    const ms = (value) => (value >= 10 ? Math.round(value) : Number(value).toFixed(1)) + ' ms';

    function tiles(s) {
        const statuses = s.statuses || {};
        const tile = (value, label, iconName, tone = '') => `<div class="col-6 col-lg"><div class="gr-stat ${tone}">
            <div class="value">${icon(iconName, 'fs-5 me-1 text-secondary')}${esc(value)}</div><div class="label">${esc(label)}</div></div></div>`;
        return `<div class="row g-2 mb-3">
            ${tile(s.requests, 'API requests', 'arrow-right-arrow-left')}
            ${tile(statuses['4xx'] || 0, 'Client errors (4xx)', 'triangle-exclamation', statuses['4xx'] ? 'gr-stat-warning' : '')}
            ${tile(statuses['5xx'] || 0, 'Server errors (5xx)', 'circle-xmark', statuses['5xx'] ? 'gr-stat-danger' : '')}
            ${tile(ms(s.avgMs), 'Average duration', 'stopwatch')}
            ${tile(ms(s.p95Ms), '95th percentile', 'gauge-high')}
            ${tile(s.total - s.requests, 'Admin actions', 'user-gear')}</div>`;
    }

    /** One bar per hour (single series: one hue, no legend; values on hover). */
    function hourChart(hours) {
        const max = Math.max(1, ...hours);
        const ticks = [0, 6, 12, 18, 23];
        return `<div class="gr-bars" role="img" aria-label="Entries per hour">${hours.map((n, h) => `<div class="gr-bar" tabindex="0" data-tip="${String(h).padStart(2, '0')}:00 - ${n} ${n === 1 ? 'entry' : 'entries'}">
                <span style="height:${n ? Math.max(2, Math.round((n / max) * 100)) : 0}%"></span></div>`).join('')}</div>
            <div class="gr-bars-axis">${hours.map((n, h) => `<span>${ticks.includes(h) ? String(h).padStart(2, '0') + 'h' : ''}</span>`).join('')}</div>
            <details class="small mt-1"><summary>Table view</summary><table class="gr-table mt-1"><thead><tr><th>Hour</th><th>Entries</th></tr></thead>
            <tbody>${hours.map((n, h) => (n ? `<tr><td>${String(h).padStart(2, '0')}:00</td><td class="text-end">${n}</td></tr>` : '')).join('')}</tbody></table></details>`;
    }

    function topList(title, entries) {
        const list = Object.entries(entries || {});
        return `<div class="small fw-semibold text-muted mb-1">${esc(title)}</div>${list.length
            ? `<table class="gr-table w-100 mb-2"><tbody>${list.map(([k, n]) => `<tr><td class="text-truncate" style="max-width:220px">${esc(k)}</td><td class="text-end">${esc(n)}</td></tr>`).join('')}</tbody></table>`
            : '<p class="small text-muted">None</p>'}`;
    }

    function entriesTable(entries) {
        const level = (l) => { const [i, cls] = LEVEL[l] || LEVEL.info; return `<span class="text-${cls}">${icon(i)}</span> ${esc(l)}`; };
        const what = (e) => (e.type === 'admin'
            ? `${icon('user-gear', 'text-secondary')} <b>${esc(e.action)}</b> ${e.target ? `<code>${esc(e.target)}</code>` : ''}`
            : `<span class="gr-method gr-method-${esc(String(e.method).toLowerCase())}">${esc(e.method)}</span> <code>${esc(e.path)}${e.query ? '?' + esc(e.query) : ''}</code>${e.via === 'console' ? ' ' + badge('console', 'light') : ''}`);
        const status = (e) => (e.status ? `<span class="gr-status-code gr-status-${String(e.status)[0]}xx">${esc(e.status)}</span>` : '');
        return `<div class="table-responsive"><table class="gr-table w-100 mb-0"><thead><tr><th>Time</th><th>Level</th><th>Request / action</th><th>Status</th><th class="text-end">ms</th><th>Who</th><th>IP</th></tr></thead><tbody>
            ${entries.map((e, i) => `<tr class="gr-clickable" data-entry="${i}"><td class="text-nowrap">${esc(String(e.time).slice(11, 19))}</td><td class="text-nowrap">${level(e.level)}</td>
                <td class="gr-cell" style="max-width:480px">${what(e)}${e.error ? `<div class="small text-danger text-truncate">${esc(e.error.message || '')}</div>` : ''}</td>
                <td>${status(e)}</td><td class="text-end">${e.duration_ms !== undefined ? esc(e.duration_ms) : ''}</td>
                <td>${esc(e.user || e.role || '')}</td><td>${esc(e.ip || '')}</td></tr>`).join('')}</tbody></table></div>`;
    }

    function pager(date, filters, page, pages) {
        const to = (p) => withQuery(link('logs', date), Object.fromEntries(Object.entries({ ...filters, page: p }).filter(([, v]) => v !== '')));
        return `<div class="gr-pagination mb-0">
            <a class="btn btn-sm btn-outline-secondary ${page <= 1 ? 'disabled' : ''}" href="${to(page - 1)}">${icon('chevron-left')}</a>
            <span class="small">Page <b>${esc(page)}</b> of ${esc(pages)}</span>
            <a class="btn btn-sm btn-outline-secondary ${page >= pages ? 'disabled' : ''}" href="${to(page + 1)}">${icon('chevron-right')}</a></div>`;
    }

    // ---------------------------------------------------------------- events

    function bind(date, filters, view) {
        const $c = $content();

        $c.on('change', '#log-filters select', () => $('#log-filters').trigger('submit'));
        $c.on('submit', '#log-filters', function (e) {
            e.preventDefault();
            const values = Object.fromEntries(FILTERS.map((k) => [k, $(this).find(`[name=${k}]`).val() || '']));
            go(date, { ...values, live: $('#log-live').is(':checked') ? '1' : '' });
        });
        $c.on('change', '#log-live', function () { go(date, { ...filters, live: this.checked ? '1' : '' }); });
        $c.on('click', 'tr[data-entry]', function () {
            const entry = view.entries[Number(this.dataset.entry)];
            if (entry) entryDrawer(entry);
        });
        $c.on('click', '#log-delete', async () => {
            if (!await confirmDialog(`Delete the log of <b>${esc(date)}</b>? This cannot be undone.`, 'Delete')) return;
            try {
                await api('logs.delete', { day: date });
                toast('Log deleted');
                store.logDays = null;
                GR.reload();
            } catch (err) { fail(err); }
        });
    }

    function entryDrawer(entry) {
        const rows = Object.entries(entry).filter(([, v]) => typeof v !== 'object' || v === null);
        drawer({
            title: `${icon('file-lines')} Log entry <span class="text-muted small">${esc(entry.time)}</span>`,
            size: 'lg',
            body: `<table class="gr-table gr-kv w-100">${rows.map(([k, v]) => `<tr><th>${esc(k)}</th><td class="text-break">${cell(v)}</td></tr>`).join('')}</table>
                ${entry.error ? `<p class="small fw-semibold mb-1">Error</p>${codeBlock(JSON.stringify(entry.error, null, 2), 'json', 'mb-3')}` : ''}
                <p class="small fw-semibold mb-1">JSON</p>${codeBlock(JSON.stringify(entry, null, 2), 'json')}`,
        });
    }

    async function cleanup() {
        const keep = Number(store.boot.settings.log_retention_days || 0);
        const $m = GR.ui.modal({
            title: `${icon('broom')} Clean up logs`,
            body: `<p class="small">Log files are deleted automatically after <b>${keep ? esc(keep) + ' days' : 'never (retention 0)'}</b>
                (<a href="#/settings/logging">change</a>). Delete files now:</p>
                <div class="input-group input-group-sm" style="max-width:340px"><span class="input-group-text">Keep the last</span>
                <input type="number" min="0" class="form-control" id="keep-days" value="${esc(keep || 7)}"><span class="input-group-text">days</span></div>
                <p class="gr-help mt-1 mb-0">0 deletes every log file.</p>`,
            buttons: [{ label: `${icon('trash')} Delete`, cls: 'btn-danger', action: async ($el) => {
                const r = await api('logs.delete', { keepDays: Number($el.find('#keep-days').val()) || 0 });
                toast(`${r.deleted} log file(s) deleted`);
                store.logDays = null;
                GR.reload();
            } }],
        });
        return $m;
    }

    function init() {
        $(document).on('click', '[data-action=logs-cleanup]', (e) => {
            e.preventDefault();
            cleanup();
        });
    }

    GR.pages = GR.pages || {};
    GR.pages.logs = { init, index, day };
})(window.GR = window.GR || {}, jQuery);
