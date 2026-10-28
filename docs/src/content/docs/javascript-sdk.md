---
title: "JavaScript SDK"
description: "One small ES module to use a Grocery Restable API from browsers and Node.js: collections, files, storage, RPC and errors."
---

The SDK is a single ES module without dependencies (`sdk/js` in the package). It works in browsers and in
Node.js 18+, and the API serves it itself, so a page needs no build step:

```js
import GroceryRestable from 'https://example.com/api/sdk.js';

const gr = new GroceryRestable('https://example.com/api', { apiKey: 'gr_your_anon_key' });

const { items, totalItems } = await gr.collection('employees').getList(1, 20, {
    filter: { officeCode: '1', salary: { gte: 5000 } },
    sort: '-lastName,firstName',
    select: '*,offices(city)',
});
```

The admin GUI shows ready-to-copy code for every endpoint in **API** > endpoint > **API preview**, and for the main
features on the **Overview** page.

## Install

| Where | How |
|---|---|
| Browser, no build step | `import GroceryRestable from '{apiUrl}/sdk.js'` |
| Bundler or Node.js | `npm install ./vendor/kctofficial/grocery-restable/sdk/js` (or copy `sdk/js` into your project), then `import GroceryRestable from '@kctofficial/grocery-restable'` |

TypeScript definitions are included (`sdk/js/index.d.ts`).

## Client

```js
const gr = new GroceryRestable(baseUrl, {
    apiKey: 'gr_...',      // "apikey" header. In browsers: the anon key, never service_role
    token: 'eyJ...',       // optional user JWT ("Authorization: Bearer")
    headers: {},           // extra headers for every request
});

gr.setToken(jwt);           // after your own login (any provider issuing JWTs with your secret)
gr.authStore.isValid;       // a token is set and not expired
gr.authStore.payload;       // decoded claims (not verified, the server verifies them)
gr.authStore.onChange((token) => localStorage.setItem('token', token));
gr.clearToken();

gr.beforeSend = (url, init) => { /* change the request, or return { url, init } */ };
gr.afterSend = (response, data) => data;
```

## Collections

`gr.collection(name)` (alias `gr.from(name)`) addresses a resource.

| Method | Request |
|---|---|
| `getList(page, perPage, options)` | `GET /{name}`, resolves `{ page, perPage, totalItems, totalPages, items }` |
| `getFullList(options)` | every matching record, fetched in batches of `options.batch` (500) |
| `getFirstListItem(filter, options)` | the first match, or a `404` error |
| `getOne(id, options)` | `GET /{name}/{id}` |
| `create(data, options)` | `POST /{name}`; objects containing `File` / `Blob` values are sent as multipart |
| `createMany(rows)`, `upsert(data)` | bulk insert; insert or update by primary key |
| `update(id, data, options)` | `PATCH /{name}/{id}` (multipart when it contains files) |
| `updateMany(filter, data)`, `deleteMany(filter)` | bulk operations, a filter is required |
| `delete(id)` | `DELETE /{name}/{id}` |
| `clone(id, overrides)` | `POST /{name}/{id}/clone` |
| `uploadFile(id, field, file)`, `deleteFile(id, field)` | upload fields |
| `getFile(id, field)`, `getFileUrl(id, field)` | download as a `Blob`, or the URL |
| `options(field, { search, limit })` | value / label pairs of a relation (dropdowns) |
| `action(id, name, body)` | custom actions defined in PHP |
| `schema()` | field metadata to build forms |

Every method accepts `headers` and `signal` (an `AbortController` signal) in its options.

### Filters, sort and select

| Option | Example | Sent as |
|---|---|---|
| `filter` | `{ city: 'Paris' }` | `city=eq.Paris` |
| | `{ salary: { gte: 5000, lt: 9000 } }` | `salary=gte.5000&salary=lt.9000` |
| | `{ officeCode: ['1', '4'] }` | `officeCode=in.(1,4)` |
| | `{ reportsTo: null, active: true }` | `reportsTo=is.null&active=is.true` |
| | `{ email: { ilike: '*@example.com' } }` | `email=ilike.*@example.com` |
| | `{ status: { not: { in: ['a', 'b'] } } }` | `status=not.in.(a,b)` |
| | `{ or: [{ age: { lt: 18 } }, { age: { gt: 65 } }] }` | `or=(age.lt.18,age.gt.65)` |
| | `{ 'offices.city': 'Paris' }` | filter on a relation |
| | `'city=eq.Paris'` | a raw query string, used as is |
| `sort` | `'-lastName,firstName'` | `order=lastName.desc,firstName.asc` |
| `select` | `'*,offices(city),projects(name)'` | columns and embedded relations |
| `search` | `'smith'` | `q=smith` |
| `query` | `{ format: 'csv' }` | any extra parameter |

Values are encoded by the SDK, including commas and parentheses inside `in` lists and `or` groups. The syntax behind
it is described in the [REST API reference](rest-api.md).

## Storage

```js
const avatars = gr.storage('avatars');
await avatars.upload('users/42', file, {
    name: 'me.png', upsert: true,
    cacheControl: 'public, max-age=86400', metadata: { author: 'jane' },
});

const page = await avatars.list('users/42/');                    // { prefixes, folders, files, truncated, next_cursor }
const more = await avatars.list('users/42/', { limit: 100, cursor: page.next_cursor });
const everything = await avatars.listAll('users/');               // recursive, every page
const info = await avatars.info('users/42/me.png');               // size, etag, mime, metadata ...
const url = avatars.getUrl('users/42/me.png');                    // public buckets
const blob = await avatars.download('users/42/me.png');
await avatars.remove('users/42/me.png');
const buckets = await gr.buckets();
```

Listing works like S3 `ListObjectsV2`: `prefix` is a key prefix (a folder ends with `/`), `delimiter: ''` lists
recursively. See [Storage buckets](uploads-and-storage.md#storage-buckets).

## RPC and other requests

```js
const stats = await gr.rpc('stats', { year: 2026 });               // POST /rpc/stats
const report = await gr.rpc('report', { id: 1 }, { method: 'GET' });
const spec = await gr.openApi();                                    // what the caller can use
const raw = await gr.send('/employees', { query: { limit: 1 } });   // any request
```

## Errors

Every failure rejects with a `GroceryRestableError`:

```js
import { GroceryRestableError } from '@kctofficial/grocery-restable';

try {
    await gr.collection('employees').create({});
} catch (error) {
    error.status;    // 422
    error.code;      // "validation_failed", "rate_limited", "not_found", "network_error" ...
    error.details;   // { fields: { lastName: "The Last name field is required." } }
}
```

`429` means the [rate limit](rate-limiting.md) was reached; wait for the `Retry-After` seconds.

## Server side (Node.js)

Use a key with the role your job needs, never in code that reaches browsers:

```js
import GroceryRestable from '@kctofficial/grocery-restable';

const gr = new GroceryRestable(process.env.API_URL, { apiKey: process.env.SERVICE_KEY });
await gr.collection('orders').updateMany({ status: 'pending', created_at: { lt: '2026-01-01' } }, { status: 'expired' });
```
