/*!
 * Grocery Restable admin - Overview: the work areas, getting started checklist and the API explorer.
 */
(function (GR) {
    'use strict';

    const { esc, icon, panel } = GR.html;
    const { absoluteApiUrl } = GR.util;
    const { $content, loadBoot, setBreadcrumb } = GR.shell;
    const store = GR.store;

    const button = (href, label, iconName) => `<a class="btn btn-sm btn-outline-primary me-1 mt-1" href="${href}">${icon(iconName)} ${esc(label)}</a>`;

    const stat = (value, label, href, iconName) => `<div class="col-6 col-lg"><a class="d-block gr-stat text-decoration-none" href="${href}">
        <div class="value">${icon(iconName, 'fs-5 me-1 text-secondary')}${esc(value)}</div><div class="label">${esc(label)}</div></a></div>`;

    const area = (href, iconName, title, text) => `<div class="col-md-4"><a class="gr-area" href="${href}">
        <span class="gr-area-icon">${icon(iconName)}</span><b>${esc(title)}</b><span class="small text-muted">${esc(text)}</span></a></div>`;

    const step = (status, title, text, actions = '') => `<li class="gr-step gr-step-${status}">
        <span class="gr-step-icon">${icon({ done: 'circle-check', warn: 'triangle-exclamation', todo: 'circle' }[status])}</span>
        <div><b>${title}</b><div class="small text-muted">${text}</div>${actions ? `<div>${actions}</div>` : ''}</div></li>`;

    function checklist(b) {
        const s = b.settings;
        const o = b.overview || {};
        return [
            step(o.error ? 'warn' : 'done', 'Connect a database', o.error
                ? `The default connection <b>${esc(o.connection)}</b> fails: ${esc(o.error)}`
                : `Default connection <b>${esc(o.connection)}</b> - ${esc(o.tables)} tables / views.`,
            button('#/settings/connections', 'Connections', 'database') + button('#/data', 'Browse data', 'table')),
            step(o.exposed > 0 ? 'done' : 'todo', 'Expose tables as API endpoints',
                `${esc(o.exposed ?? 0)} of ${esc(o.tables ?? 0)} tables are endpoints. Auto expose is <b>${s.auto_expose ? 'on' : 'off'}</b>: `
                + (s.auto_expose ? 'every new table becomes an endpoint by itself (detected on the next page load).' : 'expose tables one by one in the API area.'),
            button('#/api', 'Endpoints', 'plug')),
            step(s.auth_mode === 'none' ? 'warn' : 'done', 'Secure the API', s.auth_mode === 'none'
                ? 'Authentication is <b>off</b>: anybody can read and write every endpoint. Fine for a local test, never in production.'
                : `Authentication mode <b>${esc(s.auth_mode)}</b>; grant permissions per role on each endpoint.`, button('#/settings/auth', 'Authentication', 'shield-halved')),
            step(b.counts.apiKeys > 0 ? 'done' : 'todo', 'Give clients a key', `${esc(b.counts.apiKeys)} active API key(s). Send one in the <code>apikey</code> header.`, button('#/api/keys', 'API keys', 'key')),
            step('todo', 'Call the API', `Base URL <code>${esc(absoluteApiUrl())}</code>. Try requests as any role in the console or browse the generated docs.`,
                button('#/api/console', 'Console', 'terminal') + button('#/api/docs', 'API docs', 'book')),
        ];
    }

    async function overview() {
        // The first render reuses the data loaded at boot; later visits refresh the counters.
        const b = store.bootFresh ? store.boot : await loadBoot();
        store.bootFresh = false;
        const resources = await GR.shell.loadResources().catch(() => []);
        const sample = (resources.find((r) => r.enabled) || {}).name;
        setBreadcrumb([{ label: 'Overview', icon: 'gauge' }]);

        $content().html(`
            ${b.warnings.map((w) => `<div class="alert alert-warning py-2 small">${icon('triangle-exclamation', 'me-1')}${esc(w)}</div>`).join('')}
            <div class="row g-2 mb-3">
                ${area('#/data', 'table', 'Data', 'Browse, search and edit the rows of every table - like phpMyAdmin.')}
                ${area('#/api', 'plug', 'API', 'Choose which tables are endpoints: fields, relations, permissions, keys, docs and console.')}
                ${area('#/settings', 'gear', 'Settings', 'Authentication, JWT, CORS, uploads, database connections, admins and backups.')}
            </div>
            <div class="row g-2 mb-3">
                ${stat(b.counts.resources, 'Endpoints', '#/api', 'plug')}
                ${stat(b.connections.length, 'Connections', '#/settings/connections', 'database')}
                ${stat(b.settings.rate_limit_enabled ? `${b.settings.rate_limit_requests}/${b.settings.rate_limit_window}s` : 'off', 'Rate limit', '#/settings/ratelimit', 'gauge-high')}
                ${stat(b.counts.apiKeys, 'Active API keys', '#/api/keys', 'key')}
                ${stat(b.counts.buckets, 'Buckets', '#/storage', 'box-archive')}
                ${stat(b.counts.functions, 'RPC functions', '#/api/docs', 'bolt')}
            </div>
            <div class="row g-3">
                <div class="col-xl-7">${panel(`${icon('list-check')} Getting started`, `<ol class="gr-steps-list">${checklist(b).join('')}</ol>`)}</div>
                <div class="col-xl-5">${panel(`${icon('circle-info')} General`, `<table class="gr-table gr-kv w-100 mb-0">
                    <tr><th>Application</th><td>${esc(b.settings.app_name)}</td></tr>
                    <tr><th>Version</th><td>Grocery Restable ${esc(GR.CFG.version)}</td></tr>
                    <tr><th>Framework</th><td>${esc((b.frameworks[b.framework] || {}).label || b.framework)}</td></tr>
                    <tr><th>API base URL</th><td><code>${esc(absoluteApiUrl())}</code> <a href="#" data-copy="${esc(absoluteApiUrl())}" title="Copy">${icon('copy')}</a></td></tr>
                    <tr><th>OpenAPI</th><td>${b.settings.expose_openapi ? `<a href="${esc(absoluteApiUrl())}/openapi.json" target="_blank" rel="noopener">openapi.json</a>` : 'disabled'}</td></tr>
                    <tr><th>Logged in as</th><td>${icon('user')} ${esc(b.user.username)}</td></tr>
                    <tr><th>Credits</th><td class="small">Inspired by <a href="https://github.com/scoumbourdis/grocery-crud" target="_blank" rel="noopener noreferrer">Grocery CRUD</a>. Not affiliated with or endorsed by Grocery CRUD / John Skoumbourdis.</td></tr></table>`)}</div>
            </div>
            <h2 class="gr-section-title mt-2">${icon('rocket')}Use the API</h2>
            ${GR.snippets.explorer(GR.snippets.overviewTopics(sample))}`);
        GR.snippets.bindExplorer($content());
    }

    GR.pages = GR.pages || {};
    GR.pages.home = { overview };
})(window.GR = window.GR || {});
