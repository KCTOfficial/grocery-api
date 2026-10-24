---
title: "CodeIgniter 4 integration"
description: "Installation, configuration, routes, filters, database groups and spark commands for CodeIgniter 4."
sidebar:
  label: "CodeIgniter 4"
---

Grocery Restable follows CodeIgniter conventions: a `Config` class, a discovered service, discovered routes,
spark commands, the framework's database groups, validation service and session.

Requirements: CodeIgniter **4.5+** (tested with 4.7), PHP 8.2+.

## Installation

```bash
composer require kctofficial/grocery-restable
```

Composer registers the namespace `GroceryRestable\CodeIgniter4`; with CodeIgniter's module discovery
(`Config\Modules::$discoverInComposer = true`, the default) the following are picked up automatically:

| Discovered file | Provides |
|---|---|
| `src/CodeIgniter4/Config/Routes.php` | `/api/*` and `/grocery-restable/*` routes (can be disabled) |
| `src/CodeIgniter4/Config/Services.php` | `service('groceryRestable')` |
| `src/CodeIgniter4/Commands/*` | `php spark grocery-restable:*` |
| `src/CodeIgniter4/Config/GroceryRestable.php` | `config('GroceryRestable')` defaults |

Check with:

```bash
php spark routes | grep -i grocery
php spark list | grep grocery-restable
```

## Configuration

Publish the config to customize it:

```bash
php spark grocery-restable:publish      # creates app/Config/GroceryRestable.php (use --force to overwrite)
```

```php
namespace Config;

use GroceryRestable\CodeIgniter4\Config\GroceryRestable as BaseGroceryRestable;

class GroceryRestable extends BaseGroceryRestable
{
    public string $apiRoute = 'api';            // https://example.com/api/...
    public string $adminRoute = 'grocery-restable';  // https://example.com/grocery-restable
    public bool $autoRoutes = true;
    public bool $adminEnabled = true;
    public array $apiFilters = [];              // e.g. ['throttle']
    public array $adminFilters = [];            // e.g. ['ipAllowList']
    public array $databaseGroups = ['default' => 'default'];

    public function define(\GroceryRestable\GroceryRestable $api): void
    {
        // resources, buckets and RPC functions defined in code
    }
}
```

Every property can be overridden in `.env` with the `groceryrestable.` prefix, e.g.
`groceryrestable.encryptionKey`, `groceryrestable.jwtSecret`, `groceryrestable.setupToken`, `groceryrestable.adminEnabled = false`.

| Property | Default | Description |
|---|---|---|
| `apiRoute` | `api` | URI of the REST API, relative to `baseURL`. |
| `adminRoute` | `grocery-restable` | URI of the admin GUI. |
| `autoRoutes` | `true` | Register routes through module discovery. Set `false` and call `Routes::register()` yourself. |
| `adminEnabled` | `true` | Disable the GUI entirely (e.g. on API-only production servers). |
| `apiFilters` / `adminFilters` | `[]` | CodeIgniter filters applied to the routes. |
| `storagePath` | `WRITEPATH/grocery-restable` | SQLite store, key file, uploads, buckets. Must not be public. |
| `uploadPath` / `bucketPath` | inside `storagePath` | Base directories of field uploads / buckets. |
| `databaseGroups` | `['default' => 'default']` | CodeIgniter DB groups exposed as connections (`name => group`). |
| `useCodeIgniterValidation` | `true` | Run `setRules()` through CodeIgniter's validation service. |
| `encryptionKey` | `''` | Key for secrets stored in SQLite (`base64:` / `hex:` / 32+ chars). Empty = key file. |
| `setupToken` | `''` | Allows the web setup wizard from any IP with `?token=`. |
| `trustedSetupIps` | `['127.0.0.1', '::1']` | IPs allowed to open the wizard without a token. |
| `jwtSecret` | `''` | Overrides the JWT secret stored in SQLite (keep it in `.env`). |
| `debug` | `null` | Return exception details in API errors. `null` = only when `CI_ENVIRONMENT = development`. |
| `maxEmbedDepth` | `3` | Maximum nesting of `select=a(b(c(*)))`. |
| `maxBulkRows` | `1000` | Maximum rows per bulk insert / update / delete. |

## Routes

With `autoRoutes = true` nothing is needed. To control the position or options, disable it and register
them in `app/Config/Routes.php`:

```php
\GroceryRestable\CodeIgniter4\Routes::register($routes);
```

Grocery Restable routes accept `GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS`.

### CSRF filter

APIs authenticate with tokens, not cookies, and the admin GUI has its own CSRF protection. If you enabled
CodeIgniter's global `csrf` filter, exclude both prefixes in `app/Config/Filters.php`:

```php
public array $globals = [
    'before' => [
        'csrf' => ['except' => ['api/*', 'grocery-restable/*']],
    ],
];
```

## Two ways to define resources in code

### 1. Central definition (recommended)

All resources live in `define()` of `app/Config/GroceryRestable.php` and are served by the built-in controller
under `/api`. They appear in the GUI (read-only), in `/api/openapi.json` and can be embedded by other resources.
`define()` runs lazily on the first request that needs resources.

### 2. Grocery CRUD style: one controller method per table

```php
// app/Config/Routes.php
\GroceryRestable\CodeIgniter4\Routes::resource($routes, 'shop/employees', 'Employees::index');
```

```php
// app/Controllers/Employees.php
namespace App\Controllers;

use GroceryRestable\CodeIgniter4\Libraries\GroceryRestable;

class Employees extends BaseController
{
    public function index(string ...$segments)
    {
        $crud = new GroceryRestable();
        $crud->setTable('employees')
             ->set_subject('Employee')                       // snake_case works too
             ->set_relation('officeCode', 'offices', 'city')
             ->required_fields('lastName')
             ->callback_before_insert(fn (array $data) => $data + ['jobTitle' => 'Sales Rep']);

        return $crud->render($segments);
    }
}
```

`Routes::resource()` maps both `shop/employees` and `shop/employees/(:any)` (all verbs); the remaining
segments arrive as `$segments` and `render()` serves the whole sub-API (`/{id}`, `/{id}/upload/{field}`,
`/_options/{field}` …) for that single resource. Typical use: the route is behind your own filter (session
login, Shield, …), so grant access with `->public()` or `->setPermissions(...)`, see
[Authentication](authentication.md).

## Database connections

Each entry of `$databaseGroups` becomes a read-only **framework connection** in the GUI and the API uses the
exact CodeIgniter connection (same credentials, same driver). Supported CodeIgniter drivers: `MySQLi`,
`Postgre`, `SQLite3`. You can still add other connections from the GUI (PDO or MySQLi). Details:
[Database connections](database-connections.md).

The default connection (used by auto expose and by resources without `setConnection()`) is chosen in the
setup wizard and can be changed on the **Connections** page.

> Table names are used as-is: CodeIgniter's `DBPrefix` is not applied automatically. Use the full table name
> (e.g. `setTable('app_users')`).

## Validation

With `useCodeIgniterValidation = true`, `setRules()` strings are validated by `service('validation')`, so every
CodeIgniter rule works — including `is_unique[users.email,id,{id}]` and your own rule classes registered in
`Config\Validation::$ruleSets`. See [Validation](validation.md).

## Session

The admin GUI stores its login in the CodeIgniter session (key `grocery_restable`). Configure the session handler
as usual (`app/Config/Session.php`).

## Spark commands

| Command | Description |
|---|---|
| `php spark grocery-restable:publish [--force]` | Create `app/Config/GroceryRestable.php` and print a fresh encryption key. |
| `php spark grocery-restable:setup [--connection default] [--username admin] [--password …] [--auth api_key\|any\|jwt\|none] [--auto-expose] [--name "My API"]` | Non-interactive setup using a CodeIgniter database group; prints the `anon` and `service_role` keys. |
| `php spark grocery-restable:admin <username> [--password …] [--reset]` | Create an admin user or reset its password. |
| `php spark grocery-restable:key <name> [role]` | Create an API key (printed once). |
| `php spark grocery-restable:cache` | Clear the schema cache (normally not needed: structure changes are detected automatically). |

## Using the engine in your own code

```php
/** @var \GroceryRestable\GroceryRestable $api */
$api = service('groceryRestable');

$api->store()->createApiKey('Mobile app', 'authenticated');       // keys
$rows = $api->connections()->get()->select('SELECT 1 AS ok');       // raw connection
$spec = $api->openApi(site_url('api'));                              // OpenAPI array
```

## Development notes

- In the `development` environment CodeIgniter injects its Debug Toolbar into HTML pages. The admin GUI sends a
  strict Content-Security-Policy, so the browser console shows "Refused to execute inline script" messages for the
  toolbar. They are harmless and do not appear in production.
- PHP's built-in server (`php spark serve`) handles one request at a time; the GUI is noticeably faster on
  php-fpm / Apache.

## Apache with PHP FastCGI

If URLs other than the home page answer "No input file specified." (common on Laragon / XAMPP with `php-cgi`),
replace CodeIgniter's PATH_INFO rewrite rule in `public/.htaccess`:

```apache
RewriteRule ^ index.php [L,QSA]
```

## Deployment checklist

1. Set `groceryrestable.encryptionKey` and back it up (it decrypts the stored DB passwords and JWT secret).
2. Keep `storagePath` outside `public/` (the default `writable/grocery-restable` is).
3. `CI_ENVIRONMENT = production` (no debug details in API errors).
4. Disable the GUI on public API servers (`groceryrestable.adminEnabled = false`) or protect it with an
   `adminFilters` IP allow-list.
5. Restrict CORS origins in **Settings**.
6. Structure changes after migrations are detected automatically; `php spark grocery-restable:cache` forces a refresh.
