---
title: "Authentication and permissions"
description: "API keys, JWT, roles, per-resource permissions and row-level security."
sidebar:
  label: "Authentication"
---

## Roles

Every request runs as a **role**:

| Role | Who | Permissions |
|---|---|---|
| `anon` | requests without credentials | from the resource / default permissions (none by default) |
| `authenticated` | the default role of API keys and JWTs | from the resource / default permissions (all four by default) |
| `service_role` | trusted servers | **bypasses every permission check and every `scope()`** |
| any name | custom roles (`editor`, `accounting` …) from API keys or JWT claims | from the resource / default permissions |

## Authentication modes

Setting `auth_mode` (Settings → Authentication):

| Mode | Accepts |
|---|---|
| `api_key` (default) | API keys |
| `jwt` | JWT bearer tokens |
| `any` | both |
| `none` | nothing is checked: **every request is `service_role`**. Development only. |

Credentials are read from, in order:

1. `apikey: <key>` or `X-API-Key: <key>`
2. `Authorization: Bearer <api key or JWT>` — values starting with `gr_` are API keys, anything else a JWT
3. `?apikey=<key>` — only when `allow_query_apikey` is enabled (keys leak into access logs)

Like Supabase, a client may send the public `anon` key in `apikey` **and** a user JWT in `Authorization`; the JWT
then defines the role. Invalid credentials are always a `401` (they never fall back to `anon`).

## API keys

- Created by the setup wizard (`anon` + `service_role`), on the **API keys** page, with
  `php spark grocery-restable:key <name> [role]` or `$api->store()->createApiKey($name, $role)`.
- Format: `gr_` + 48 hex characters (192 bits of randomness). Only the SHA-256 hash is stored; the key is shown once.
- Revoke or delete keys at any time; *last used* is tracked (updated at most once a minute).

Typical setup: ship the `anon` key in public clients (with `anon` permissions limited to public data), keep the
`service_role` key on servers, give integrations their own key with a dedicated role.

## JWT

Grocery Restable verifies HMAC JWTs (`HS256`, `HS384`, `HS512`) signed with the configured secret
(Settings → JWT, or `jwtSecret` in the bootstrap config / `groceryrestable.jwtSecret` in `.env`). It does not issue user
tokens — your application (login endpoint, CodeIgniter Shield, an identity provider using HMAC) does.

Checks: signature (`alg: none` and asymmetric algorithms are rejected), `exp`, `nbf` (60 s leeway), optional
`iss` and `aud` (settings `jwt_issuer`, `jwt_audience`).

The role comes from the claim named by `jwt_role_claim` (default `role`, dot notation allowed such as
`app_metadata.role`), falling back to `jwt_default_role` (`authenticated`). Role names must match
`[A-Za-z_][A-Za-z0-9_-]*`.

Issue a token in PHP (e.g. after your own login):

```php
use GroceryRestable\Support\Jwt;

$token = Jwt::encode([
    'sub' => (string) $user->id,
    'role' => 'authenticated',
    'iat' => time(),
    'exp' => time() + 3600,
], getenv('GROCERY_RESTABLE_JWT_SECRET'));
```

The GUI can generate test tokens (API keys page) for any role.

## Permissions

A resource grants operations per role:

```php
$api->resource('products')->setPermissions([
    'anon' => ['select'],
    'authenticated' => ['select'],
    'editor' => ['select', 'insert', 'update'],
    'admin' => ['select', 'insert', 'update', 'delete'],
]);

$api->resource('news')->setPermissions('*', ['select']);   // "*" = every role, including anon
$api->resource('internal')->public();                         // everything for everybody
```

| Permission | Allows |
|---|---|
| `select` | list, read, `_options`, `_schema`, file download, embedding as a target, CSV export |
| `insert` | create, bulk create, multipart create, clone (with `select`) |
| `update` | update, replace, bulk update, uploads, upsert of existing rows, actions (default) |
| `delete` | delete, bulk delete |

Resources without `setPermissions()` use the **default permissions** (Settings): by default `anon` gets nothing and
`authenticated` gets everything. Auto-exposed resources always use the default permissions.

Missing permission → `401` for `anon`, `403` for other roles. Disabled operations (`unsetDelete()` …) → `405` for
every role, including `service_role`.

## Row-level security

`scope()` adds conditions computed per request to every read, update and delete (and to embedded rows of that
resource):

```php
use GroceryRestable\Auth\AuthContext;
use GroceryRestable\Resource\Conditions;

$api->resource('notes')
    ->setPermissions('authenticated', ['select', 'insert', 'update', 'delete'])
    ->scope(fn (Conditions $q, AuthContext $auth) => $q->where('owner_id', (string) $auth->userId()))
    ->fieldType('owner_id', 'readonly')
    ->callbackBeforeInsert(fn (array $data, AuthContext $auth) => ['owner_id' => $auth->userId()] + $data);
```

- Rows outside the scope behave as if they did not exist (`404`), also for updates and deletes.
- `AuthContext` exposes `role`, `method` (`anonymous`, `api_key`, `jwt`, `internal`), `claims`, `claim($name)`,
  `userId()` (`sub` claim), `apiKeyId`, `isService()`, `isAnonymous()`.
- `service_role` bypasses scopes; static `where()` conditions of the resource always apply.

Multi-tenant example:

```php
->scope(function (Conditions $q, AuthContext $auth) {
    $q->where('tenant_id', (int) $auth->claim('tenant_id', 0));
})
->fieldType('tenant_id', 'invisible')
->callbackBeforeInsert(fn (array $d, AuthContext $a) => ['tenant_id' => (int) $a->claim('tenant_id')] + $d)
```

## Protecting routes with your framework

When a CodeIgniter filter already authenticates the user (session login, Shield), you can let the filter decide and
open the resource to every caller:

```php
// app/Config/GroceryRestable.php
public array $apiFilters = ['session'];            // e.g. CodeIgniter Shield's session filter

// and per resource
$api->resource('orders')->public();
```

Inside callbacks you can still read the framework user (`auth()->user()` with Shield).

## Rate limiting

Grocery Restable does not rate limit API calls itself. Use CodeIgniter's `throttle` filter via `$apiFilters`, or your
reverse proxy (nginx `limit_req`). Admin logins are throttled (5 failures / 15 min per IP).

## CORS

Settings → CORS: allowed origins (`*` by default), preflight max age. Credentials are tokens, not cookies, so
`Access-Control-Allow-Credentials` is not sent. Restrict origins in production when only known web apps call the API.
