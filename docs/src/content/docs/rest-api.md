---
title: "REST API reference"
description: "Endpoints, query syntax (filters, embedding, ordering, paging), responses and errors."
sidebar:
  label: "REST API reference"
---

Base URL: `{apiUrl}` - by default `https://your-app/api`. All bodies are JSON (`application/json`) unless noted;
multipart (`multipart/form-data`) and form (`application/x-www-form-urlencoded`) bodies are accepted for writes.

The query syntax is compatible with [PostgREST](https://postgrest.org/en/stable/references/api/tables_views.html)
for the common cases, so existing PostgREST / Supabase clients mostly work unchanged.

## Endpoints

| Method | Path | Description |
|---|---|---|
| `GET` | `/` | Index: resources visible to the caller, OpenAPI and storage URLs, current role |
| `GET` | `/openapi.json` | OpenAPI 3.1 document of what the caller can use ([OpenAPI](openapi.md)) |
| `GET` | `/sdk.js` | The [JavaScript SDK](javascript-sdk.md) as an ES module |
| `GET` | `/_schema` · `/_schema/{resource}` · `/{resource}/_schema` | Field metadata for UI builders |
| `GET` | `/{resource}` | List rows |
| `POST` | `/{resource}` | Create one row (object) or many (array); upsert with `Prefer: resolution=merge-duplicates` |
| `PATCH` | `/{resource}?{filters}` | Update every matching row (filters required) |
| `DELETE` | `/{resource}?{filters}` | Delete every matching row (filters required) |
| `GET` | `/{resource}/_options/{field}` | `value` / `label` pairs of a relation |
| `GET` | `/{resource}/{id}` | Read one row |
| `PUT` | `/{resource}/{id}` | Replace (required fields must be present) |
| `PATCH` | `/{resource}/{id}` | Partial update |
| `DELETE` | `/{resource}/{id}` | Delete |
| `POST` | `/{resource}/{id}/clone` | Clone (body overrides fields) |
| `POST` | `/{resource}/{id}/upload/{field}` | Upload a file into an upload field |
| `DELETE` | `/{resource}/{id}/upload/{field}` | Remove the file of an upload field |
| `GET` | `/{resource}/{id}/file/{field}` | Download the file of an upload field |
| `POST` | `/{resource}/{id}/actions/{action}` | Custom action |
| `GET`/`POST` | `/rpc/{function}` | Remote procedure |
| `GET` | `/storage` | Buckets visible to the caller |
| `GET`/`POST` | `/storage/{bucket}` | List objects (`prefix`, `delimiter`, `limit`, `cursor`, like S3 ListObjectsV2) / upload |
| `GET`/`HEAD`/`DELETE` | `/storage/{bucket}/{key}` | Download (with `ETag`, `304` on `If-None-Match`) / headers only / delete an object |
| `GET` | `/storage/{bucket}/{key}?info` | Object metadata (size, etag, mime, cache_control, metadata, owner, dates) |
| `OPTIONS` | any | CORS preflight (`204`) |

`{id}` is the primary key value (URL encoded). For multipart and form bodies, `PUT` / `PATCH` / `DELETE` can be
tunnelled through `POST` with `X-HTTP-Method-Override: PATCH` or a `_method=PATCH` form field (browsers and PHP only
parse multipart bodies on `POST`). JSON requests always use their real method.

Every response carries `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset` while
[rate limiting](rate-limiting.md) applies. Query strings are limited to 16 KB (`414`) and `select` / `or` / `and`
to 10 nested levels (`400`).

## Authentication headers

```http
apikey: gr_xxx                        # or X-API-Key: gr_xxx, or Authorization: Bearer gr_xxx
Authorization: Bearer eyJhbGciOi...    # JWT
```

See [Authentication](authentication.md).

## Reading

### Projection: `select`

```http
GET /employees?select=employeeNumber,lastName
GET /employees?select=id:employeeNumber,name:lastName          # aliases
GET /employees?select=*,offices(city,country)                  # embed a relation
GET /employees?select=lastName,office:offices(city)            # embed with alias
GET /offices?select=city,employees(lastName,customers(customerName))   # nested
```

Without `select`, the resource's list columns (`columns()`) or read fields (`setReadFields()`) are returned, plus
relations configured as default embeds. `*` means "the default columns". Unknown columns are rejected with `400`.
See [Relations](relations.md).

### Filters

Every query parameter that is not reserved is a filter: `column=operator.value`.

| Operator | SQL | Example |
|---|---|---|
| `eq` | `=` | `status=eq.Shipped` |
| `neq` | `<>` | `status=neq.Cancelled` |
| `gt`, `gte`, `lt`, `lte` | `>`, `>=`, `<`, `<=` | `price=gte.10` |
| `like` | `LIKE` (`*` = `%`) | `name=like.*phone*` |
| `ilike` | case insensitive `LIKE` | `email=ilike.*@EXAMPLE.com` |
| `in` | `IN (…)` | `id=in.(1,2,3)` · `city=in.("New York","Paris")` |
| `is` | `IS NULL / TRUE / FALSE` | `deleted_at=is.null` · `active=is.true` |
| `not.` | negation | `status=not.in.(Cancelled,Disputed)` · `email=not.is.null` |

Several filters are combined with `AND`. Values are type checked against the column (`id=eq.abc` on an integer
column is a `400`) and always bound as parameters.

Logical groups (nesting allowed):

```http
GET /employees?or=(salary.gt.8000,salary.is.null)
GET /employees?and=(active.is.true,or(jobTitle.eq.President,jobTitle.like.VP*))
GET /employees?not.or=(officeCode.eq.1,officeCode.eq.2)
```

Inside a group the syntax is `column.operator.value`; quote values containing commas or parentheses with `"…"`.

Filters on related tables: `relation.column=operator.value`, also inside groups (`offices.city.eq.Paris`).

Only columns readable through the resource (list ∪ read columns, never hidden ones) can be filtered, ordered or
searched.

### Ordering: `order`

```http
GET /employees?order=lastName.desc,firstName
GET /employees?order=reportsTo.asc.nullsfirst
```

The resource default order (`orderBy()`) applies when `order` is absent; the primary key is always appended so
pages are stable.

### Paging: `limit`, `offset`, `page`, `per_page`

```http
GET /orders?limit=25&offset=50
GET /orders?page=3&per_page=25
```

Defaults: the resource page size or the `default_per_page` setting (25); capped by `setMaxLimit()` / `max_limit` (1000).

### Counting: `count=exact`

```http
GET /orders?limit=1&count=exact          # or header  Prefer: count=exact
```

Every list response carries `Content-Range: {from}-{to}/{total|*}`; with `count=exact` the total is computed and
also sent as `X-Total-Count`.

### Search: `q`

```http
GET /customers?q=mini
```

Case-insensitive `LIKE '%…%'` over the searchable columns (`setSearchable()`, default: text-like list columns),
combined with the other filters.

### CSV export: `format=csv`

```http
GET /orders?format=csv&select=orderNumber,status&limit=1000     # or  Accept: text/csv
```

UTF-8 with BOM, header row, embedded values as JSON, spreadsheet formula injection neutralized. Disabled with
`unsetExport()`.

### Response shape

Lists are bare JSON arrays (PostgREST compatible):

```json
[{ "employeeNumber": 1002, "lastName": "Murphy", "active": true, "salary": 9000.5 }]
```

With the `response_envelope` setting:

```json
{ "data": [...], "meta": { "total": 23, "count": 10, "limit": 10, "offset": 0, "page": 1 } }
```

Types are native JSON: integers, numbers (high precision decimals stay strings), booleans, decoded JSON columns,
`set` columns as arrays, binary as base64, dates as stored.

### Single record

```http
GET /employees/1002?select=*,offices(city)
```

`404` when the row does not exist **or** is filtered out by `where()` / `scope()`.

## Writing

### Create

```http
POST /employees
Content-Type: application/json

{ "lastName": "Doe", "firstName": "Jane", "email": "jane@example.com", "officeCode": "1" }
```

`201 Created`, `Location: /api/employees/1704`, body = the created row (respecting `?select=`). Use
`Prefer: return=minimal` for an empty `201`.

Bulk insert - send an array (max `maxBulkRows`, one transaction, all-or-nothing):

```json
[{ "title": "A" }, { "title": "B" }]
```

Upsert - rows whose primary key exists are updated (partial), the others inserted:

```http
POST /film
Prefer: resolution=merge-duplicates

[{ "film_id": 1, "title": "Renamed" }, { "title": "New film" }]
```

Multipart with files (upload fields):

```bash
curl -X POST /api/employees -F lastName=Doe -F email=jane@example.com -F officeCode=1 -F "photo=@me.png"
```

In form and multipart bodies an empty string becomes `NULL` for non-text columns.

### Update

```http
PATCH /employees/1002                       partial
PUT /employees/1002                         replace: required / NOT NULL fields must be present
PATCH /employees?officeCode=eq.4            every matching row (filters required, max maxBulkRows)
```

Response: the updated row (or an array for bulk), `Prefer: return=minimal` → `204`. Sending the primary key with
the same value is allowed; changing it is not (unless it is listed in `fields()`).

### Delete

```http
DELETE /employees/1002            → 204
DELETE /employees?status=eq.draft → 200 {"deleted": [3, 7]}
```

### Clone

```http
POST /film/1/clone
{ "title": "Academy Dinosaur 2" }
```

Copies every column except the primary key and upload fields, plus many-to-many links; the body overrides fields
(needed for unique columns). Enable with `setClone()`.

### Validation errors

```json
HTTP/1.1 422 Unprocessable Entity
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "details": { "fields": { "email": "The Email field must contain a valid email address.", "foo": "Unknown field." } }
  }
}
```

Unknown and non-writable fields are reported instead of being silently ignored. See [Validation](validation.md).

## Errors

All errors share one format:

```json
{ "error": { "code": "not_found", "message": "Resource not found." } }
```

| Status | `code` | When |
|---|---|---|
| 400 | `bad_request` | invalid query syntax, unknown column / relation, bad value type, bulk operation without filters |
| 400 | `cancelled` | a `before_*` callback returned `false` |
| 401 | `unauthorized` | missing / invalid credentials (`WWW-Authenticate: Bearer` header) |
| 403 | `forbidden` | authenticated role lacks the permission |
| 404 | `not_found` | unknown resource / record (or hidden by row-level security) |
| 405 | `method_not_allowed` | operation disabled, wrong method, `no_primary_key` |
| 409 | `conflict` | storage object exists |
| 409 | `constraint_violation` | database unique / foreign key / not null violation |
| 413 | `payload_too_large` | file too large |
| 422 | `validation_failed` | invalid input (`details.fields`) |
| 500 | `database_error`, `configuration_error`, `internal_error` | server side problems (details only with `debug`) |

Database messages are never exposed unless `debug` is enabled.

## Headers

Response headers on every API response: `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`,
`X-Frame-Options: DENY`, CORS headers (when enabled) exposing `Content-Range, X-Total-Count, Location,
Content-Disposition`. Accepted request headers for CORS: `apikey, authorization, content-type, prefer, range,
x-api-key, x-http-method-override, x-requested-with`.

`Prefer` values: `return=minimal`, `return=representation` (default), `count=exact`, `resolution=merge-duplicates`.

## Schema metadata

```http
GET /_schema/employees
```

```json
{
  "name": "employees", "subject": "Employee", "primaryKey": "employeeNumber",
  "operations": { "list": true, "read": true, "create": true, "update": true, "delete": true, "clone": false, "export": true },
  "permissions": { "select": true, "insert": false, "update": false, "delete": false },
  "fields": {
    "lastName": { "label": "Last Name", "type": "string", "maxLength": 50, "required": true, "readable": true, "creatable": true, "updatable": true },
    "officeCode": { "label": "Office City", "type": "string", "relation": { "name": "offices", "type": "belongs_to", "table": "offices", "options": "_options/officeCode" } }
  },
  "relations": [...], "listColumns": [...], "readColumns": [...], "searchable": [...], "actions": []
}
```

`permissions` are those of the caller - handy to build forms and hide buttons.

## Client examples

The easiest way is the [JavaScript SDK](javascript-sdk.md):

```js
import GroceryRestable from 'https://example.com/api/sdk.js';

const gr = new GroceryRestable('https://example.com/api', { apiKey: ANON_KEY });
const { items } = await gr.collection('orders').getList(1, 20, { filter: { status: 'Shipped' }, select: 'orderNumber,status,customer(customerName)' });
```

Plain `fetch`:

```js
const api = (path, options = {}) => fetch(`https://example.com/api/${path}`, {
  ...options,
  headers: { apikey: ANON_KEY, Authorization: `Bearer ${userJwt}`, 'Content-Type': 'application/json', ...options.headers },
}).then(async (r) => (r.status === 204 ? null : r.json()));

const rows = await api('orders?select=orderNumber,status,customer(customerName)&status=eq.Shipped&limit=20');
await api('orders/10100', { method: 'PATCH', body: JSON.stringify({ status: 'Resolved' }) });
```

PHP (any HTTP client):

```php
$rows = json_decode(file_get_contents('https://example.com/api/offices?select=city', false, stream_context_create([
    'http' => ['header' => "apikey: {$key}\r\n"],
])), true);
```

PostgREST client libraries (e.g. `postgrest-js`) work for the common reads and writes when pointed at `{apiUrl}`.
Not implemented: the single-object `Accept: application/vnd.pgrst.object+json` header, PostgREST's RPC argument
conventions (Grocery Restable RPC functions are PHP callables), aggregate functions and `Range` request headers
(use `limit` / `offset`).
