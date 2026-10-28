/*!
 * Grocery Restable admin - application shell: shared state, data loaders,
 * layout (breadcrumb, tabs, status bar, section sidebar) and router middleware.
 */
(function (GR, $) {
    'use strict';

    const { esc, icon, link, loading } = GR.html;
    const { api } = GR.http;
    const { toast, confirmDialog, closeOverlays } = GR.ui;

    const TABLE_REFRESH_MS = 10000;
    const $content = () => $('#gr-content');

    const store = {
        boot: null,         // "bootstrap" action: settings, connections, catalogs, counters
        tables: {},         // connection → table list (or { error })
        tablesLoadedAt: {},
        structure: {},      // connection \0 table → structure
        resources: null,    // resources.list
        buckets: null,      // buckets.list
        expanded: {},       // data tree: connection → expanded
        section: 'home',
        path: '/',
        currentHash: '',
        dirty: false,
    };

    // =====================================================================
    // Data loaders
    // =====================================================================

    async function loadBoot() {
        store.boot = await api('bootstrap');
        return store.boot;
    }

    const defaultConnection = () => (store.boot.connections.find((c) => c.default) || store.boot.connections[0] || {}).name;

    async function loadTables(connection, force = false) {
        if (store.tables[connection] && !force) return store.tables[connection];
        const previous = store.tables[connection];
        try {
            store.tables[connection] = await api('tables', { connection });
        } catch (e) {
            store.tables[connection] = { error: $('<div>').html(e.message).text() };
        }
        store.tablesLoadedAt[connection] = Date.now();
        if (Array.isArray(previous) && Array.isArray(store.tables[connection])) announceChanges(connection, previous, store.tables[connection]);
        renderNav();
        return store.tables[connection];
    }

    /** Toast tables that appeared / disappeared since the last load (e.g. after a migration). */
    function announceChanges(connection, before, after) {
        const old = before.map((t) => t.name);
        const now = after.map((t) => t.name);
        after.filter((t) => !old.includes(t.name)).forEach((t) => {
            const status = t.resources.length
                ? `available at <code>${esc(GR.CFG.apiUrl)}/${esc(t.resources[0].name)}</code> (${esc(t.resources[0].source)})`
                : `not exposed yet - <a href="${link('data', connection, t.name)}" class="text-white">open it</a> to expose it`;
            toast(`${icon('table')} New table <b>${esc(t.name)}</b> detected: ${status}.`, 'info', true);
        });
        old.filter((name) => !now.includes(name)).forEach((name) => toast(`Table <b>${esc(name)}</b> no longer exists.`, 'warning', true));
    }

    /** Share one in-flight request per key (the sidebar may ask again while it is loading). */
    const inFlight = {};
    const once = (key, fn) => inFlight[key] || (inFlight[key] = fn().finally(() => { delete inFlight[key]; }));

    async function loadResources(force = false) {
        if (!store.resources || force) store.resources = await once('resources', () => api('resources.list'));
        return store.resources;
    }

    async function loadBuckets(force = false) {
        if (!store.buckets || force) store.buckets = await once('buckets', () => api('buckets.list'));
        return store.buckets;
    }

    async function structure(connection, table, force = false) {
        const key = connection + '\u0000' + table;
        if (!store.structure[key] || force) store.structure[key] = await api('table.structure', { connection, table });
        return store.structure[key];
    }

    async function tableResources(connection, table) {
        const tables = await loadTables(connection);
        const t = Array.isArray(tables) ? tables.find((x) => x.name === table) : null;
        return t ? t.resources : [];
    }

    /** Expose a table with its generated definition (GUI resource) in one click. */
    async function exposeTable(connection, table) {
        const definition = await api('resource.generate', { connection, table });
        const saved = await api('resource.save', { definition, enabled: true });
        await Promise.all([loadTables(connection, true), loadResources(true)]);
        toast(`<b>${esc(table)}</b> is now available at <code>${esc(GR.CFG.apiUrl)}/${esc(saved.name)}</code>.`, 'success', true);
        return saved;
    }

    /** Reload everything after a change that affects several areas (settings, schema, import …). */
    async function refreshAll() {
        store.structure = {};
        store.resources = null;
        store.buckets = null;
        await loadBoot();
        await Promise.all(Object.keys(store.tables).map((connection) => loadTables(connection, true)));
        renderNav();
    }

    // =====================================================================
    // Layout
    // =====================================================================

    function setBreadcrumb(items) {
        const html = [`<span>${icon('server')}</span> <a href="#/home">Server: ${esc(store.boot ? store.boot.settings.app_name : '')}</a>`];
        items.forEach((i) => html.push(`<span class="sep">»</span>${i.icon ? icon(i.icon) + ' ' : ''}${i.href ? `<a href="${esc(i.href)}">${esc(i.label)}</a>` : `<span>${esc(i.label)}</span>`}`));
        $('#gr-breadcrumb').html(html.join(' '));
    }

    /** tabs: [{ id, icon, label, href }] or in-page panes: [{ id, icon, label, pane: true }] */
    function setTabs(tabs, active) {
        $('#gr-tabs').html(tabs.map((t) => `<li><a href="${esc(t.href || '#')}" class="${t.id === active ? 'active' : ''}" ${t.pane ? `data-pane="${esc(t.id)}"` : ''}>
            ${icon(t.icon)} ${esc(t.label)}${t.count ? ` <span class="gr-tab-count">${esc(t.count)}</span>` : ''}</a></li>`).join(''));
    }

    const setStatus = (html) => $('#gr-status').html(html);

    /** Change the URL without dispatching the router (in-page tabs). */
    function replaceHash(hash) {
        history.replaceState(null, '', hash);
        store.currentHash = window.location.hash;
    }

    // ---------------------------------------------------------------- section sidebar

    const navs = {};

    /** Register the sidebar of a section: { head(), body(filter), foot() }. */
    function defineNav(section, spec) {
        navs[section] = spec;
    }

    function renderNav() {
        const section = store.section;
        $('.gr-rail [data-section]').each(function () { $(this).toggleClass('active', this.dataset.section === section); });
        const spec = navs[section];
        $('.gr-layout').toggleClass('gr-no-nav', !spec);
        if (!spec || !store.boot) return;
        if (store.navSection !== section) {
            store.navSection = section;
            $('#gr-nav-head').html(spec.head());
        }
        $('#gr-nav-body').html(spec.body(($('#gr-nav-filter').val() || '').toLowerCase()));
        const foot = spec.foot ? spec.foot() : '';
        $('#gr-nav-foot').html(foot).toggleClass('d-none', !foot);
    }

    /** True when the current route is `href` (or below it with `nested`). */
    function isCurrent(href, nested = false) {
        const path = href.replace(/^#/, '').split('?')[0];
        return store.path === path || (nested && store.path.startsWith(path + '/'));
    }

    const navItem = ({ href, iconName, label, extra = '', nested = false, cls = '' }) => `<li><a class="gr-node ${cls} ${isCurrent(href, nested) ? 'active' : ''}" href="${esc(href)}">
        ${icon(iconName)}<span class="gr-label">${esc(label)}</span>${extra}</a></li>`;

    const navHead = (title, iconName, action = '', filter = '') => `<div class="gr-nav-title">${icon(iconName)} <span>${esc(title)}</span>${action}</div>`
        + (filter ? `<div class="gr-nav-filter">${icon('filter')}<input type="search" id="gr-nav-filter" class="form-control form-control-sm" placeholder="${esc(filter)}" aria-label="${esc(filter)}"></div>` : '');

    // ---------------------------------------------------------------- unsaved changes

    const DIRTY_CLICKS = '[data-add-role], [data-remove-role], [data-cond-add], [data-cond-del], [data-order-add], [data-order-del], [data-upload-add], [data-upload-del]';
    const dirtyBadge = () => `<span class="gr-dirty-badge">${icon('circle', 'me-1')}Unsaved changes</span>`;

    function markDirty() {
        store.dirty = true;
        $content().addClass('gr-dirty');
    }

    function clearDirty() {
        store.dirty = false;
        $content().removeClass('gr-dirty');
    }

    /** Flag the page as modified when a form control inside $scope changes. */
    function trackDirty($scope) {
        $scope.on('input change', 'input:not([data-no-dirty]), select:not([data-no-dirty]), textarea:not([data-no-dirty])', markDirty);
        $scope.on('click', DIRTY_CLICKS, markDirty);
    }

    // =====================================================================
    // Router middleware
    // =====================================================================

    /** Ask before leaving a page with unsaved changes. */
    async function guardUnsaved(req, res, next) {
        if (store.dirty && req.hash !== store.currentHash) {
            history.replaceState(null, '', store.currentHash);
            if (!await confirmDialog('You have unsaved changes. Leave this page and discard them?', 'Discard changes', 'btn-warning')) return;
            clearDirty();
            history.replaceState(null, '', req.hash);
        }
        await next();
    }

    /** Reset the page frame, select the section and keep the table lists fresh. */
    async function layout(req, res, next) {
        store.path = req.path;
        store.currentHash = window.location.hash;
        store.section = req.path.split('/')[1] || 'home';
        clearDirty();
        closeOverlays();
        $content().off().html(loading());
        $('#gr-status, #gr-tabs, #gr-breadcrumb').empty();
        Object.keys(store.tables)
            .filter((c) => Date.now() - (store.tablesLoadedAt[c] || 0) > TABLE_REFRESH_MS)
            .forEach((c) => loadTables(c, true));
        renderNav();
        await next();
        renderNav();
    }

    async function catchErrors(req, res, next) {
        try {
            await next();
        } catch (e) {
            $content().html(`<div class="alert alert-danger">${GR.util.errorMessage(e)}</div>`);
        }
    }

    function notFound() {
        setBreadcrumb([{ label: 'Not found' }]);
        $content().html(GR.html.empty('Page not found. <a href="#/home">Back to the overview</a>'));
    }

    GR.store = store;
    GR.shell = {
        $content, loadBoot, defaultConnection, loadTables, loadResources, loadBuckets, structure, tableResources, exposeTable, refreshAll,
        setBreadcrumb, setTabs, setStatus, replaceHash, defineNav, renderNav, navItem, navHead, isCurrent,
        dirtyBadge, markDirty, clearDirty, trackDirty,
        middleware: { guardUnsaved, layout, catchErrors, notFound },
    };
})(window.GR = window.GR || {}, jQuery);
