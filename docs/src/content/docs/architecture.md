---
title: "Architecture and extension points"
description: "Internals, request lifecycle and how to extend Grocery Restable."
sidebar:
  label: "Architecture"
---

```
                     ┌──────────────── framework adapter ────────────────┐
HTTP ──► CodeIgniter 4 (Routes, ApiController, AdminController, Bridge)  │
   or ──► NativeServer (plain PHP)                                        │
                     └──────────────────────┬────────────────────────────┘
                                            ▼  GroceryRestable\Http\Request
                         ┌──────────── GroceryRestable (engine) ────────────┐
                         │ ApiKernel ─► Authenticator ─► ResourceReader │
                         │            │                  ResourceWriter │──► ConnectionInterface ─► database
                         │            │                  StorageService │      (PDO / MySQLi / CodeIgniter)
                         │ AdminKernel (GUI, setup, XHR)                 │
                         │ ResourceRegistry ◄─ code · ConfigStore · Auto │──► SchemaRepository (cached introspection)
                         │ ConfigStore (SQLite)                          │
                         └───────────────────────────────────────────────┘
                                            ▼  GroceryRestable\Http\Response
```

## Source layout

| Path | Responsibility |
|---|---|
| `src/GroceryRestable.php` | Engine facade: lazy services, definition API (`resource()`, `bucket()`, `rpc()`), `handle()` / `render()` / `handleAdmin()` |
| `src/Config.php` | Bootstrap configuration and framework choices |
| `src/Store/ConfigStore.php` | SQLite store: settings, connections, resources, keys, buckets, users, throttling, cache |
| `src/Database/` | `ConnectionInterface`, `PdoConnection`, `MysqliConnection`, `ConnectionFactory`, `ConnectionManager`, grammars, schema inspectors |
| `src/Resource/` | `Resource` (fluent definition), `Relation`, `UploadField`, `Conditions`, `ResourceRegistry`, `AutoResourceFactory`, `CodeExporter` |
| `src/Query/` | PostgREST style query parser (`SelectNode`, `Filter`, `FilterGroup`, `QueryOptions`) |
| `src/Service/` | `SqlCompiler`, `ResourceReader` (list, read, embedding, options), `ResourceWriter` (writes, validation, uploads, m2m), `UploadService`, `StorageService`, `RowTransformer` |
| `src/Auth/` | `Authenticator` (keys, JWT, permissions), `AuthContext` |
| `src/Http/` | `Request`, `Response`, `ApiKernel` (router), `NativeServer`, uploaded files |
| `src/Admin/` | `AdminKernel`, `Installer`, sessions, views, `assets/admin.js`, `assets/admin.css` |
| `src/OpenApi/` | OpenAPI generator |
| `src/Validation/` | `ValidatorInterface`, built-in `Validator` |
| `src/CodeIgniter4/` | Adapter: config, services, routes, controllers, bridge, CI connection / session / validator, spark commands, Grocery CRUD style library |

## Request lifecycle (API)

1. The adapter converts the framework request into `GroceryRestable\Http\Request` (path relative to the API base, raw
   query string, headers, body, files, client IP).
2. `ApiKernel` answers CORS preflights, authenticates (`AuthContext`), routes the path.
3. `ResourceRegistry` resolves the resource (code → SQLite → auto) and binds it to its table and connection
   (`ResolvedResource`).
4. `QueryParser` parses the query string; `SqlCompiler` turns resource conditions, scopes, client filters and search
   into parameterized SQL using allow-listed identifiers.
5. `ResourceReader` runs the main query and one query per embedded relation; `RowTransformer` casts values and runs
   `callbackColumn`.
6. Writes go through `ResourceWriter`: field checks, normalization, rules, uniqueness, uploads, callbacks, SQL,
   many-to-many sync — inside a transaction.
7. Exceptions become JSON errors; the adapter converts the `Response` back (files are streamed).

## Extension points

| Interface / hook | Use it to |
|---|---|
| `Database\ConnectionInterface` | add a database driver (`driver()` must return `mysql`, `pgsql` or `sqlite` to reuse the grammars and inspectors) |
| `$api->addConnection($name, $factory, $meta)` | register connections from your application |
| `Validation\ValidatorInterface` + `$api->setValidator()` | plug another validation library |
| `Admin\SessionInterface` | store the admin session elsewhere |
| `Http\UploadedFileInterface` | bridge another framework's uploaded files |
| `$api->booting(fn ($api) => …)` | define resources lazily |
| `Resource` callbacks, `scope()`, `addAction()`, `$api->rpc()` | custom behaviour per resource |

### Writing a framework adapter

An adapter needs to:

1. Build `GroceryRestable::create([...])` from the framework configuration (`framework`, `storagePath`, `apiUrl`,
   `adminUrl`, secrets) and register framework connections with `addConnection()`.
2. Route `{api}/*` to `$api->handle($request)` and `{admin}/*` to `$api->handleAdmin($request, $session)`.
3. Convert requests (`Request` constructor) and responses (`Response::getStatus()`, `getHeaders()`, `getBody()`,
   `getFilePath()` / `getFileName()` / `isInline()` for downloads).
4. Optionally provide a validator and a session implementation.

`src/CodeIgniter4/` is the reference implementation (about 600 lines). Only *No framework* and *CodeIgniter 4* are
offered by the setup wizard in this release.

## Adding an engine

1. A grammar in `src/Database/Grammar/` (identifier quoting, limit/offset, `ILIKE`, casting, `RETURNING`).
2. A schema inspector in `src/Database/Schema/` returning `Table` / `Column` / `ForeignKey` objects.
3. An entry in `ConnectionFactory::ENGINES` and the DSN in `makePdo()`.
4. Error mapping: PDO exceptions are mapped by SQLSTATE class (`23` → 409, `22` → 400).
