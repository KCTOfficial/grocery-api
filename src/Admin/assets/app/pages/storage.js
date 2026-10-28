/*!
 * Grocery Restable admin - Storage area: buckets and their files.
 */
(function (GR, $) {
    'use strict';

    const { esc, icon, badge, sourceBadge, yesNo, panel, link, withQuery } = GR.html;
    const { absoluteApiUrl, formatBytes, formValues } = GR.util;
    const { api, upload } = GR.http;
    const { toast, fail, drawer, confirmDialog } = GR.ui;
    const C = GR.components;
    const shell = GR.shell;
    const store = GR.store;
    const $content = shell.$content;

    shell.defineNav('storage', {
        head: () => shell.navHead('Storage', 'box-archive', `<a href="#" class="gr-nav-action" data-action="new-bucket" title="New bucket">${icon('plus')} New</a>`, 'Filter buckets'),
        body: (filter) => {
            const all = shell.navItem({ href: '#/storage', iconName: 'list', label: 'All buckets' });
            if (!store.buckets) {
                shell.loadBuckets().then(shell.renderNav, () => {});
                return `<ul class="gr-menu">${all}</ul><div class="text-muted small ps-3">${icon('spinner', 'fa-spin')} loading…</div>`;
            }
            const list = store.buckets.filter((b) => !filter || b.name.toLowerCase().includes(filter));
            return `<ul class="gr-menu">${all}</ul><div class="gr-nav-caption">Buckets <span class="badge text-bg-light">${esc(store.buckets.length)}</span></div>
                <ul class="gr-menu">${list.map((b) => shell.navItem({ href: link('storage', b.name), iconName: 'folder', label: b.name, nested: true, extra: b.public ? ` <span class="text-muted small" title="public">${icon('globe')}</span>` : '' })).join('')
                    || `<li class="text-muted small ps-3">${filter ? 'No match' : 'No buckets yet'}</li>`}</ul>`;
        },
        foot: () => `<ul class="gr-menu">${shell.navItem({ href: '#/settings/cleanup', iconName: 'broom', label: 'Storage cleanup' })}</ul>`,
    });

    // ---------------------------------------------------------------- bucket drawer

    function bucketEditor(bucket) {
        const d = bucket || { public: false, permissions: null };
        const ops = store.boot.permissions;
        const $d = drawer({
            title: bucket ? `${icon('pen')} Edit bucket <b>${esc(bucket.name)}</b>` : `${icon('plus')} New bucket`,
            size: 'lg',
            body: `<div class="row g-2">
                <div class="col-md-6"><label class="form-label small mb-0" for="b-name">Name</label><input class="form-control form-control-sm" id="b-name" name="name" value="${esc(d.name || '')}" ${bucket ? 'readonly' : ''}></div>
                <div class="col-md-6 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="public" id="b-public" ${d.public ? 'checked' : ''}>
                    <label class="form-check-label" for="b-public">Public (anyone can read / download)</label></div></div>
                <div class="col-md-6"><label class="form-label small mb-0" for="b-types">Allowed types</label><input class="form-control form-control-sm" id="b-types" name="allowedTypes" value="${esc(d.allowedTypes || '')}" placeholder="jpg|png|pdf (empty = global setting)"></div>
                <div class="col-md-6"><label class="form-label small mb-0" for="b-size">Max size</label><input class="form-control form-control-sm" id="b-size" name="maxSize" value="${esc(d.maxSize || '')}" placeholder="5MB (empty = global setting)"></div>
                <div class="col-12"><label class="form-label small mb-0" for="b-desc">Description</label><input class="form-control form-control-sm" id="b-desc" name="description" value="${esc(d.description || '')}"></div>
                <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="b-defperm" ${d.permissions ? '' : 'checked'}>
                    <label class="form-check-label" for="b-defperm">Use the default permissions</label></div><div id="b-perms" class="${d.permissions ? '' : 'd-none'}">${C.permissionGrid(d.permissions || {}, ops, 'bucket')}</div>
                    <p class="gr-help mb-0">select = list / download, insert = upload, update = overwrite (upsert), delete = delete objects.</p></div></div>`,
            buttons: [{ label: `${icon('floppy-disk')} Save`, save: true, action: async ($el) => {
                const v = formValues($el.find('.gr-overlay-body'));
                const definition = {
                    public: v.public, allowedTypes: v.allowedTypes, maxSize: v.maxSize, description: v.description,
                    permissions: $el.find('#b-defperm').is(':checked') ? null : C.readPermissionGrid($el.find('.gr-perm-grid')),
                };
                await api('bucket.save', { name: v.name, definition });
                toast('Bucket saved');
                await shell.loadBuckets(true);
                if (bucket) GR.reload(); else window.location.hash = link('storage', v.name);
            } }],
        });
        C.bindPermissionGrid($d.find('.gr-overlay-body'), ops);
        $d.find('#b-defperm').on('change', function () { $d.find('#b-perms').toggleClass('d-none', this.checked); });
    }

    async function deleteBucket(name) {
        const $m = GR.ui.modal({
            title: `${icon('triangle-exclamation', 'text-warning me-1')} Delete bucket <b>${esc(name)}</b>`,
            body: `<p>The bucket definition is removed. Its files:</p>
                <div class="form-check"><input class="form-check-input" type="radio" name="purge" id="purge-yes" value="1" checked>
                    <label class="form-check-label" for="purge-yes">Move them to the trash (kept ${esc(store.boot.settings.storage_trash_days)} days, then deleted)</label></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="purge" id="purge-no" value="0">
                    <label class="form-check-label" for="purge-no">Keep them on disk (reported as an orphan folder by the storage cleanup)</label></div>`,
            buttons: [{ label: `${icon('trash')} Delete bucket`, cls: 'btn-danger', action: async ($el) => {
                const here = window.location.hash;
                const purge = $el.find('[name=purge]:checked').val() === '1';
                const r = await api('bucket.delete', { name, purge });
                toast(r.trashed ? 'Bucket deleted, files moved to the trash' : 'Bucket deleted');
                await shell.loadBuckets(true);
                if (window.location.hash !== here) return; // the user already went elsewhere
                if (here === '#/storage') GR.reload(); else window.location.hash = '#/storage';
            } }],
        });
        return $m;
    }

    // ---------------------------------------------------------------- GET /storage

    async function index() {
        shell.setBreadcrumb([{ label: 'Storage', icon: 'box-archive' }]);
        const buckets = await shell.loadBuckets(true);
        const row = (b) => `<tr><td><a href="${link('storage', b.name)}"><b>${icon('folder', 'text-warning')} ${esc(b.name)}</b></a></td><td>${sourceBadge(b.source || 'gui')}</td><td class="text-center">${yesNo(b.public)}</td>
            <td>${esc(b.allowedTypes || 'default')}</td><td>${esc(b.maxSize || 'default')}</td><td>${b.permissions ? Object.keys(b.permissions).map((r) => badge(r, 'info')).join(' ') : '<span class="text-muted small">default</span>'}</td>
            <td>${esc(b.description || '')}</td><td class="gr-actions-cell"><a href="${link('storage', b.name)}">${icon('folder-open')} Browse</a>
            ${b.source !== 'code' ? `<a href="#" data-edit="${esc(b.name)}">${icon('pen')} Edit</a><a href="#" class="text-danger" data-del="${esc(b.name)}">${icon('trash')} Delete</a>` : ''}</td></tr>`;

        $content().html(panel(`${icon('box-archive')} Storage buckets`, `<table class="gr-table w-100"><thead><tr><th>Name</th><th>Source</th><th>Public</th><th>Allowed types</th><th>Max size</th><th>Permissions</th><th>Description</th><th></th></tr></thead><tbody>
            ${buckets.map(row).join('') || '<tr><td colspan="8" class="text-center text-muted py-3">No buckets yet - click <b>New bucket</b> to create one.</td></tr>'}
            </tbody></table><p class="gr-help mb-0">API: <code>GET/POST ${esc(GR.CFG.apiUrl)}/storage/{bucket}</code>, <code>GET/HEAD/DELETE ${esc(GR.CFG.apiUrl)}/storage/{bucket}/{key}</code>. Files are stored like S3 / R2: one key per file, with ETag, Content-Type and metadata. <a href="#/settings/cleanup">Storage cleanup</a> finds orphan files.</p>`,
            `<button class="btn btn-sm btn-primary" data-action="new-bucket">${icon('plus')} New bucket</button>`));

        $content().find('[data-edit]').on('click', function (e) { e.preventDefault(); bucketEditor(buckets.find((b) => b.name === this.dataset.edit)); });
        $content().find('[data-del]').on('click', function (e) { e.preventDefault(); deleteBucket(this.dataset.del); });
    }

    // ---------------------------------------------------------------- GET /storage/:bucket?prefix=users/42/

    const objectUrl = (bucket, key) => absoluteApiUrl() + '/storage/' + encodeURIComponent(bucket) + '/' + key.split('/').map(encodeURIComponent).join('/');
    const downloadUrl = (bucket, key) => `${GR.CFG.adminUrl}/download?bucket=${encodeURIComponent(bucket)}&path=${encodeURIComponent(key)}`;

    function objectRow(bucket, f) {
        return `<tr class="gr-clickable" data-key="${esc(f.path)}"><td>${icon('file', 'text-secondary')} ${esc(f.name)}</td><td class="text-end">${esc(formatBytes(f.size))}</td>
            <td>${esc(f.mime)}</td><td><code class="small" title="${esc(f.etag)}">${esc(String(f.etag).slice(0, 8))}</code></td><td class="text-nowrap">${esc(String(f.updated_at).replace('T', ' ').replace('Z', ''))}</td>
            <td class="gr-actions-cell"><a href="${esc(downloadUrl(bucket, f.path))}" title="Download">${icon('download')}</a><a href="#" data-copy="${esc(objectUrl(bucket, f.path))}" title="Copy the API URL">${icon('link')}</a>
            <a href="#" class="text-danger" data-del="${esc(f.path)}" title="Delete">${icon('trash')}</a></td></tr>`;
    }

    function objectDrawer(bucket, info) {
        const rows = [
            ['Key', `<code>${esc(info.path)}</code>`], ['Size', `${esc(formatBytes(info.size))} <span class="text-muted">(${esc(info.size)} bytes)</span>`],
            ['Content-Type', esc(info.mime)], ['ETag', `<code>${esc(info.etag)}</code> <span class="text-muted small">MD5 of the content</span>`],
            ['Cache-Control', info.cache_control ? `<code>${esc(info.cache_control)}</code>` : '<span class="text-muted">bucket default</span>'],
            ['Original name', esc(info.original_name || '')], ['Owner', esc(info.owner || '')],
            ['Created', esc(info.created_at)], ['Last modified', esc(info.updated_at)],
            ['API URL', `<code class="small text-break">${esc(info.url || objectUrl(bucket, info.path))}</code> <a href="#" data-copy="${esc(info.url || objectUrl(bucket, info.path))}">${icon('copy')}</a>`],
        ];
        const metadata = Object.entries(info.metadata || {});
        drawer({
            title: `${icon('file')} ${esc(info.name)}`,
            size: 'lg',
            body: `<table class="gr-table gr-kv w-100">${rows.map(([k, v]) => `<tr><th>${esc(k)}</th><td>${v}</td></tr>`).join('')}</table>
                <p class="small fw-semibold mb-1">Metadata</p>${metadata.length
                    ? `<table class="gr-table w-100">${metadata.map(([k, v]) => `<tr><th class="small">${esc(k)}</th><td class="small">${esc(v)}</td></tr>`).join('')}</table>`
                    : '<p class="small text-muted">None</p>'}
                ${String(info.mime).startsWith('image/') ? `<img src="${esc(downloadUrl(bucket, info.path))}" alt="" class="img-fluid border rounded mt-2" style="max-height:240px">` : ''}`,
            buttons: [{ label: `${icon('download')} Download`, cls: 'btn-outline-primary', action: () => { window.location.href = downloadUrl(bucket, info.path); return false; } }],
        });
    }

    async function bucket(req) {
        const name = req.params.bucket;
        const prefix = req.query.prefix || '';
        const base = link('storage', name);
        const at = (p) => (p ? withQuery(base, { prefix: p }) : base);
        shell.setBreadcrumb([{ label: 'Storage', icon: 'box-archive', href: '#/storage' }, { label: name, icon: 'folder', href: base }, ...(prefix ? [{ label: prefix }] : [])]);

        const [first, buckets] = await Promise.all([api('bucket.objects', { bucket: name, prefix, limit: 100 }), shell.loadBuckets()]);
        const info = buckets.find((b) => b.name === name) || {};
        const parts = prefix.split('/').filter(Boolean);
        const crumbs = [`<a href="${base}">${esc(name)}</a>`, ...parts.map((p, i) => `<a href="${at(parts.slice(0, i + 1).join('/') + '/')}">${esc(p)}</a>`)];
        const parent = parts.length > 1 ? parts.slice(0, -1).join('/') + '/' : '';
        const folderRow = (p) => `<tr><td colspan="6"><a href="${at(p)}">${icon('folder', 'text-warning')} ${esc(p.slice(prefix.length).replace(/\/$/, ''))}</a></td></tr>`;

        $content().html(panel(`${icon('folder-open')} ${crumbs.join(' / ')} ${info.public ? badge('public', 'success') : ''}`, `<div class="table-responsive"><table class="gr-table w-100 mb-0">
            <thead><tr><th>Name</th><th class="text-end">Size</th><th>Type</th><th title="MD5 of the content">ETag</th><th>Last modified (UTC)</th><th></th></tr></thead><tbody id="object-rows">
            ${prefix ? `<tr><td colspan="6"><a href="${at(parent)}">${icon('turn-up')} ..</a></td></tr>` : ''}
            ${first.prefixes.map(folderRow).join('')}${first.files.map((f) => objectRow(name, f)).join('')}
            ${!first.count ? '<tr><td colspan="6" class="text-center text-muted py-3">Empty: upload a file below.</td></tr>' : ''}</tbody></table></div>
            <div class="d-flex gap-2 align-items-center mt-2"><button class="btn btn-sm btn-outline-secondary ${first.truncated ? '' : 'd-none'}" id="load-more">${icon('angles-down')} Load more</button>
            <span class="small text-muted ms-auto">Keys follow S3: <code>${esc(prefix || '')}name.ext</code>; click a file for its metadata.</span></div>`,
            info.source !== 'code' && info.name ? `<a href="#" data-edit-bucket>${icon('pen')} Bucket settings</a>` : '',
            `<form id="up-form"><div class="d-flex flex-wrap gap-2 align-items-center"><input type="file" class="form-control form-control-sm w-auto" name="file" required aria-label="File">
             <input class="form-control form-control-sm w-auto" name="path" placeholder="folder (optional)" value="${esc(prefix.replace(/\/$/, ''))}" aria-label="Folder">
             <div class="form-check"><input class="form-check-input" type="checkbox" name="upsert" id="up-upsert"><label class="form-check-label small" for="up-upsert">overwrite</label></div>
             <button class="btn btn-sm btn-primary">${icon('upload')} Upload</button></div>
             <details class="small mt-2"><summary>Advanced: Cache-Control and metadata</summary><div class="row g-2 mt-1">
                <div class="col-md-4"><input class="form-control form-control-sm" name="cache_control" placeholder="Cache-Control, e.g. public, max-age=86400" aria-label="Cache-Control"></div>
                <div class="col-md-8"><input class="form-control form-control-sm font-monospace" name="metadata" placeholder='metadata JSON, e.g. {"author": "jane"}' aria-label="Metadata"></div></div></details></form>`));

        let cursor = first.next_cursor;
        $('#load-more').on('click', async function () {
            try {
                const next = await api('bucket.objects', { bucket: name, prefix, limit: 100, cursor });
                $('#object-rows').append(next.prefixes.map(folderRow).join('') + next.files.map((f) => objectRow(name, f)).join(''));
                cursor = next.next_cursor;
                $(this).toggleClass('d-none', !next.truncated);
            } catch (err) { fail(err); }
        });
        $('#up-form').on('submit', async function (e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('bucket', name);
            fd.set('upsert', $('#up-upsert').is(':checked') ? '1' : '0');
            try { await upload('bucket.upload', fd); toast('File uploaded'); GR.reload(); } catch (err) { fail(err); }
        });
        $content().on('click', 'tr[data-key]', async function (e) {
            if ($(e.target).closest('a, button').length) return;
            try { objectDrawer(name, await api('bucket.object.info', { bucket: name, path: this.dataset.key })); } catch (err) { fail(err); }
        });
        $content().find('[data-edit-bucket]').on('click', (e) => { e.preventDefault(); bucketEditor(info); });
        $content().on('click', '[data-del]', async function (e) {
            e.preventDefault();
            if (!await confirmDialog(`Delete <b>${esc(this.dataset.del)}</b>?`, 'Delete')) return;
            try { await api('bucket.object.delete', { bucket: name, path: this.dataset.del }); toast('File deleted'); GR.reload(); } catch (err) { fail(err); }
        });
    }

    function init() {
        $(document).on('click', '[data-action=new-bucket]', (e) => {
            e.preventDefault();
            bucketEditor(null);
        });
    }

    GR.pages = GR.pages || {};
    GR.pages.storage = { init, index, bucket };
})(window.GR = window.GR || {}, jQuery);
