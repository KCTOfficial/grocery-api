---
title: "Troubleshooting / FAQ"
description: "Answers to common problems."
sidebar:
  label: "Troubleshooting"
---

### `404 Unknown resource "x"`
The table is not exposed: enable *auto expose*, create the resource in the GUI, or define it in code. Check
`auto_exclude_tables` and that the table exists on the **default** connection (or use `setConnection()`).

### `401 Authentication required` although I sent a key
- Header names: `apikey`, `X-API-Key` or `Authorization: Bearer`. Apache CGI/FastCGI may drop `Authorization`;
  add `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` or use the `apikey` header.
- The mode may not accept that credential type (`jwt` mode refuses API keys and vice versa).
- The key may be revoked (API keys page).
- A valid key whose role lacks the permission gets `403`, not `401`. Use the **Console** "as role" field to
  reproduce what a role can see.

### `403 Role "authenticated" is not allowed to delete "orders"`
Grant the permission on the resource (Permissions panel or `setPermissions()`) or in the default permissions.

### Embedded relation returns `401` / `403`
The embedded table is a resource the caller cannot `select`. Grant `select` on it, or don't expose it as a resource
(then only its key and title columns are embedded).

### My new table does not appear in the API
Structure changes are detected on the next request, so check how the table is exposed: on the **Resources** page it is
either an endpoint (code / GUI / auto) or *not exposed*. When *auto expose* is off, click **Expose**. Auto expose only
covers the **default** connection and skips `auto_exclude_tables` and names that are not valid endpoint names. If the
GUI was already open, the navigation re-reads the tables when you move to another page (or click the refresh icon).

### `409 constraint_violation`
The database refused the write (duplicate unique value, missing referenced row, row still referenced). Enable `debug`
in development to see the database message.

### `Invalid CSRF token` in the GUI
The session expired or a second tab logged out. Reload the page. If CodeIgniter's own `csrf` filter is enabled
globally, exclude `grocery-restable/*` and `api/*` ([CodeIgniter 4](codeigniter4.md#csrf-filter)).

### The setup wizard says it is only reachable from a trusted IP
Set `setupToken` and open `/grocery-restable/setup?token=…`, or run `php spark grocery-restable:setup`.

### "No input file specified." (CodeIgniter on Apache with PHP FastCGI, e.g. Laragon)
CodeIgniter's default `public/.htaccess` rewrites to `index.php/$1` (PATH_INFO). With PHP running as FastCGI /
`php-cgi` this fails for every URL that has a path, so the home page works but `/api/...` and `/grocery-restable`
answer "No input file specified.". Rewrite to `index.php` without PATH_INFO - CodeIgniter 4 reads the path from
`REQUEST_URI` anyway:

```apache
# public/.htaccess - replace:  RewriteRule ^([\s\S]*)$ index.php/$1 [L,NC,QSA]
RewriteRule ^ index.php [L,QSA]
```

### "Refused to execute inline script" in the browser console
CodeIgniter's Debug Toolbar (development only) conflicts with the admin's Content-Security-Policy. Harmless.

### The admin GUI is slow with `php spark serve`
PHP's built-in server handles one request at a time and CodeIgniter's development mode adds the debug toolbar to
every request. Use php-fpm / Apache for realistic performance.

### "The PHP extension pdo_pgsql is not installed"
Install / enable the extension for your engine (`pdo_mysql` or `mysqli`, `pdo_pgsql`, `pdo_sqlite`). The setup
wizard and the Connections page only enable installed drivers.

### "Unable to decrypt a stored secret"
The encryption key changed (or the key file was lost). Restore the key, or re-enter the connection passwords and the
JWT secret in the GUI.

### Uploads fail with "The file content (…) does not match its extension"
The file's real type differs from its extension (e.g. a renamed file). Convert the file or allow the right extension.

### How do I reset an admin password?
`php spark grocery-restable:admin admin --reset` (CodeIgniter) or `$api->store()->changePassword($id, $password)`.

### How do I start over?
Delete the SQLite store (`writable/grocery-restable/grocery-restable.sqlite`) - this removes settings, GUI resources, keys and
users; uploaded files and the key file stay.

### Can I use PostgreSQL schemas other than `public`?
Yes: set *Schema* on the connection (`schema` key); the search path is set on connect.

### Does it support SQL Server / Oracle?
Not yet; see [Database connections](database-connections.md).
