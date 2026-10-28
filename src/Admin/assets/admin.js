/*!
 * Grocery Restable admin GUI - entry point. jQuery + Bootstrap 5, no build step.
 *
 * Areas (left rail):  Overview, Data (browse tables), API (endpoint configuration), Storage, Logs, Settings
 * Modules (assets/app): core → router → components → shell → pages/* → this file (see loader.js).
 */
(function (GR, $) {
    'use strict';

    const { home, data, api, resource, storage, logs, settings, setup } = GR.pages;
    const { guardUnsaved, layout, catchErrors, notFound } = GR.shell.middleware;
    const { link } = GR.html;
    const { fail, toast, copy } = GR.ui;
    const shell = GR.shell;
    const store = GR.store;

    // =====================================================================
    // Routes
    // =====================================================================

    const router = GR.Router()
        .use(guardUnsaved)
        .use(layout)
        .use(catchErrors)

        .redirect('/', '/home')
        .get('/home', home.overview)

        .get('/data', data.index)
        .get('/data/:conn', data.database)
        .get('/data/:conn/:table/:tab?', data.table)

        .get('/api', api.index)
        .get('/api/keys', api.keys)
        .get('/api/docs', api.docs)
        .get('/api/console', api.console)
        .get('/api/r/:name', resource.edit)
        .get('/api/new/:conn/:table', resource.create)

        .get('/storage', storage.index)
        .get('/storage/:bucket', storage.bucket)

        .get('/logs', logs.index)
        .get('/logs/:day', logs.day)

        .get('/settings/:page?', settings.page)

        // Routes of the previous layout (bookmarks)
        .redirect('/resources', '/api')
        .redirect('/keys', '/api/keys')
        .redirect('/docs', '/api/docs')
        .redirect('/console', (req) => GR.html.withQuery('/api/console', req.query))
        .redirect('/connections', '/settings/connections')
        .redirect('/users', '/settings/admins')
        .redirect('/io', '/settings/io')
        .redirect('/bucket/:bucket', (req) => link('storage', req.params.bucket))
        .redirect('/table/:conn/:table/:tab?/:name?', legacyTable)

        .otherwise(notFound);

    /** #/table/{conn}/{table}/{browse|structure|api|code|try}/{resource} of the previous layout. */
    function legacyTable(req) {
        const { conn, table, tab = 'browse', name } = req.params;
        if (['browse', 'structure'].includes(tab)) return link('data', conn, table, tab);
        if (!name) return link('data', conn, table);
        const pane = { code: 'code', try: 'preview' }[tab];
        return pane ? GR.html.withQuery(link('api', 'r', name), { tab: pane }) : link('api', 'r', name);
    }

    GR.reload = () => router.dispatch();

    // =====================================================================
    // Global behaviour
    // =====================================================================

    /** Highlight every code block added to the page (content, dialogs) with highlight.js. */
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

    /** Ctrl/Cmd + S clicks the primary save button of the open dialog, or of the page. */
    function saveShortcut(e) {
        if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 's') return;
        const $button = $('.offcanvas.show [data-save], .modal.show [data-save]').first();
        const $target = $button.length ? $button : $('#gr-content [data-save]:visible').first();
        if (!$target.length) return;
        e.preventDefault();
        $target.trigger('click'); // a click on a submit button submits its form
    }

    function bindGlobalEvents() {
        $(document)
            .on('click', '[data-copy]', function (e) { e.preventDefault(); copy(this.dataset.copy); })
            .on('click', '[data-action=logout]', (e) => { e.preventDefault(); $('#gr-logout-form').trigger('submit'); })
            .on('click', '[data-action=reload-tree]', async (e) => {
                e.preventDefault();
                try {
                    await GR.http.api('schema.refresh');
                    await shell.refreshAll();
                    toast('Schema refreshed');
                    GR.reload();
                } catch (err) { fail(err); }
            })
            .on('input', '#gr-nav-filter', () => shell.renderNav())
            .on('keydown', saveShortcut);

        window.addEventListener('beforeunload', (e) => {
            if (!store.dirty) return;
            e.preventDefault();
            e.returnValue = '';
        });
        [data, api, storage, logs].forEach((module) => module.init());
    }

    async function bootApp() {
        bindGlobalEvents();
        startHighlighting();
        try {
            await shell.loadBoot();
            store.bootFresh = true;
        } catch (e) {
            shell.$content().html(`<div class="alert alert-danger">${GR.util.errorMessage(e)}</div>`);
            return;
        }
        router.start();
    }

    $(function () {
        if (GR.page === 'setup') setup.wizard();
        if (GR.page === 'app') bootApp();
    });
})(window.GR, jQuery);
