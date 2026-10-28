/*!
 * Grocery Restable admin - code samples (JavaScript SDK, cURL, PHP) and the "explorer" component:
 * a list of topics on the left and their code, in several languages, on the right (like pocketbase.io).
 */
(function (GR, $) {
    'use strict';

    const { esc, icon, codeBlock } = GR.html;
    const { absoluteApiUrl } = GR.util;

    const LANGS = { javascript: 'JavaScript', curl: 'cURL', php: 'PHP' };
    const HIGHLIGHT = { javascript: 'javascript', curl: 'bash', php: 'php' };

    const quote = (value) => "'" + String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
    const json = (value, indent = 4) => JSON.stringify(value, null, indent);
    /** A JS object literal (keys unquoted when possible), indented for a code sample. */
    const literal = (value, depth = 1) => {
        const pad = '    '.repeat(depth);
        const entries = Object.entries(value);
        if (!entries.length) return '{}';
        return '{\n' + entries.map(([k, v]) => `${pad}${/^[A-Za-z_$][\w$]*$/.test(k) ? k : quote(k)}: ${typeof v === 'object' && v !== null && !Array.isArray(v) ? literal(v, depth + 1) : typeof v === 'string' ? quote(v) : JSON.stringify(v)},`).join('\n') + '\n' + '    '.repeat(depth - 1) + '}';
    };

    /** The two lines every JavaScript sample starts with. */
    function sdkSetup() {
        const base = absoluteApiUrl();
        return `// JavaScript SDK (npm package, or straight from the API: ${base}/sdk.js)
import GroceryRestable from ${quote(base + '/sdk.js')};

const gr = new GroceryRestable(${quote(base)}, { apiKey: 'YOUR_ANON_KEY' });`;
    }

    const curl = (method, path, { body, form, auth = 'apikey' } = {}) => {
        const lines = [`curl${method === 'GET' ? '' : ' -X ' + method} "${absoluteApiUrl()}/${path}"`];
        lines.push(auth === 'bearer' ? '  -H "Authorization: Bearer YOUR_USER_JWT"' : '  -H "apikey: YOUR_KEY"');
        if (body !== undefined) lines.push('  -H "Content-Type: application/json"', `  -d '${JSON.stringify(body)}'`);
        (form || []).forEach((f) => lines.push(`  -F "${f}"`));
        return lines.join(' \\\n');
    };

    /** Sample value of a column, by API type. */
    function sampleValue(column) {
        if (!column) return 'value';
        if (column.enumValues && column.enumValues.length) return column.enumValues[0];
        return ({
            integer: 1, number: 9.99, boolean: true, date: '2026-01-31', datetime: '2026-01-31 10:00:00', time: '10:00:00',
            json: { key: 'value' }, uuid: '0193b3a4-6f1e-7c39-9b51-2d1f5e0c8a77',
        })[column.type] ?? (column.name.toLowerCase().includes('email') ? 'jane@example.com' : 'text');
    }

    // ---------------------------------------------------------------- explorer component

    /**
     * items: [{ id, icon, title, text, code: { javascript, curl, php }, extra }]
     * Returns HTML; call bindExplorer($scope) once it is in the page.
     */
    function explorer(items, active = items[0] && items[0].id) {
        const current = items.find((i) => i.id === active) || items[0];
        return `<div class="gr-explorer" data-explorer>
            <ul class="gr-explorer-list" role="tablist">${items.map((item) => `<li><a href="#" role="tab" class="gr-explorer-item ${item.id === current.id ? 'active' : ''}" data-topic="${esc(item.id)}">
                <span class="gr-explorer-title">${icon(item.icon)} ${esc(item.title)}</span>${item.text ? `<span class="gr-explorer-text">${item.text}</span>` : ''}</a></li>`).join('')}</ul>
            <div class="gr-explorer-body">${items.map((item) => topic(item, item.id === current.id)).join('')}</div></div>`;
    }

    function topic(item, visible) {
        const langs = Object.keys(LANGS).filter((l) => item.code[l]);
        return `<div class="gr-explorer-topic ${visible ? '' : 'd-none'}" data-topic-body="${esc(item.id)}">
            <div class="gr-explorer-head">${item.head || ''}<div class="gr-lang-tabs" role="tablist">${langs.map((l, i) => `<button type="button" class="${i === 0 ? 'active' : ''}" data-lang="${l}">${LANGS[l]}</button>`).join('')}</div></div>
            ${langs.map((l, i) => `<div class="gr-explorer-code ${i === 0 ? '' : 'd-none'}" data-lang-body="${l}">${codeBlock(item.code[l], HIGHLIGHT[l], 'mb-0')}
                <button type="button" class="btn btn-sm gr-copy-code" data-copy-snippet title="Copy">${icon('copy')}</button></div>`).join('')}
            ${item.extra || ''}</div>`;
    }

    /** Clicks on topics and language tabs; the chosen language is kept across topics. */
    function bindExplorer($scope) {
        $scope.on('click', '[data-topic]', function (e) {
            e.preventDefault();
            const $explorer = $(this).closest('[data-explorer]');
            $explorer.find('[data-topic]').removeClass('active');
            $(this).addClass('active');
            $explorer.find('[data-topic-body]').addClass('d-none').filter(`[data-topic-body="${CSS.escape(this.dataset.topic)}"]`).removeClass('d-none');
            const lang = $explorer.data('lang');
            if (lang) $explorer.find(`[data-topic-body="${CSS.escape(this.dataset.topic)}"] [data-lang="${lang}"]`).trigger('click');
        });
        $scope.on('click', '[data-lang]', function () {
            const $topic = $(this).closest('[data-topic-body]');
            $topic.find('[data-lang]').removeClass('active');
            $(this).addClass('active');
            $topic.find('[data-lang-body]').addClass('d-none').filter(`[data-lang-body="${this.dataset.lang}"]`).removeClass('d-none');
            $(this).closest('[data-explorer]').data('lang', this.dataset.lang);
        });
        $scope.on('click', '[data-copy-snippet]', function () {
            GR.ui.copy($(this).closest('.gr-explorer-code').find('code').text());
        });
    }

    // ---------------------------------------------------------------- overview topics

    function overviewTopics(name = 'example') {
        const setup = sdkSetup();
        const c = quote(name);
        return [
            {
                id: 'database', icon: 'database', title: 'Database REST API',
                text: 'Every exposed table is an endpoint: list, search, embed relations, create, update and delete.',
                code: {
                    javascript: `${setup}

// list and search records (filter, sort, embed relations)
const list = await gr.collection(${c}).getList(1, 20, {
    filter: { status: 'active', price: { gte: 10 } },
    sort: '-created_at,title',
    select: '*,category(name)',
});

// fetch a single record
const record = await gr.collection(${c}).getOne('RECORD_ID');

// create, update and delete
const created = await gr.collection(${c}).create({ title: 'Lorem ipsum' });
await gr.collection(${c}).update('RECORD_ID', { title: 'Changed' });
await gr.collection(${c}).delete('RECORD_ID');`,
                    curl: `# list and search
${curl('GET', `${name}?status=eq.active&order=created_at.desc&limit=20&select=*,category(name)`)}

# create
${curl('POST', name, { body: { title: 'Lorem ipsum' } })}

# update / delete
${curl('PATCH', `${name}/RECORD_ID`, { body: { title: 'Changed' } })}
${curl('DELETE', `${name}/RECORD_ID`)}`,
                },
            },
            {
                id: 'auth', icon: 'user-shield', title: 'Authentication',
                text: 'API keys per role (like Supabase), JWTs from your own login, permissions and row level security.',
                code: {
                    javascript: `${setup}

// signed-in users: send the JWT issued by your login / identity provider
gr.setToken(userJwt);              // Authorization: Bearer ...
gr.authStore.isValid;               // false once the token expired
gr.authStore.onChange((token) => localStorage.setItem('token', token));

// only the rows the user may see (row level security, defined in PHP)
const mine = await gr.collection('orders').getFullList();

gr.clearToken();`,
                    curl: `# API key (role of the key: anon, authenticated, service_role ...)
${curl('GET', 'orders')}

# user JWT (role and "sub" come from the token claims)
${curl('GET', 'orders', { auth: 'bearer' })}`,
                    php: `// app/Config/GroceryRestable.php (CodeIgniter 4) or your bootstrap file
$api->resource('orders')
    ->setPermissions('anon', [])
    ->setPermissions('authenticated', ['select', 'insert'])
    // row level security: users only see and write their own orders
    ->scope(fn ($q, $auth) => $q->where('customer_id', $auth->userId()));`,
                },
            },
            {
                id: 'files', icon: 'hard-drive', title: 'File storage',
                text: 'Upload fields on records and storage buckets, with type and size checks.',
                code: {
                    javascript: `${setup}

// a record with a file (objects with File / Blob values are sent as multipart)
const employee = await gr.collection('employees').create({
    lastName: 'Doe',
    photo: fileInput.files[0],
});
const photoUrl = gr.collection('employees').getFileUrl(employee.id, 'photo');

// storage buckets
await gr.storage('avatars').upload('users/42', file, { name: 'me.png', upsert: true });
const { files } = await gr.storage('avatars').list('users/42/');
await gr.storage('avatars').remove('users/42/me.png');`,
                    curl: `# upload into a field of a record
${curl('POST', 'employees/42/upload/photo', { form: ['file=@me.png'] })}

# upload into a bucket
${curl('POST', 'storage/avatars', { form: ['file=@me.png', 'path=users/42', 'upsert=1'] })}`,
                    php: `$api->resource('employees')->setFieldUpload('photo', 'employees', 'jpg|png|webp');

$api->bucket('avatars', ['public' => true, 'allowedTypes' => 'jpg|png', 'maxSize' => '2MB']);`,
                },
            },
            {
                id: 'extend', icon: 'terminal', title: 'Extendable',
                text: 'Hooks before and after every write, custom actions and remote procedures, in plain PHP.',
                code: {
                    php: `// intercept writes
$api->resource('orders')
    ->callbackBeforeInsert(function (array $data, $auth) {
        $data['created_by'] = $auth->userId();
        return $data;
    })
    ->callbackAfterUpdate(fn (array $data, $id) => log_message('info', "order {$id} updated"));

// custom action: POST /api/orders/{id}/actions/ship
$api->resource('orders')->addAction('ship', function ($id, array $body, $ctx) {
    $ctx->db->execute('UPDATE orders SET status = ? WHERE id = ?', ['shipped', $id]);
    return ['shipped' => true];
});

// remote procedure: POST /api/rpc/stats
$api->rpc('stats', fn (array $params, $ctx) => [
    'orders' => $ctx->db->select('SELECT COUNT(*) AS n FROM orders')[0]['n'],
], ['roles' => ['authenticated']]);`,
                    javascript: `${setup}

await gr.collection('orders').action('ORDER_ID', 'ship');
const stats = await gr.rpc('stats');`,
                    curl: `${curl('POST', 'orders/ORDER_ID/actions/ship', { body: {} })}
${curl('POST', 'rpc/stats', { body: {} })}`,
                },
            },
        ];
    }

    GR.snippets = { explorer, bindExplorer, overviewTopics, sdkSetup, curl, quote, json, literal, sampleValue };
})(window.GR = window.GR || {}, jQuery);
