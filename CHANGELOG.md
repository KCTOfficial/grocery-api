# Changelog

## 1.0.0 — unreleased

Complete rewrite of the project as **Grocery Restable**: an instant REST API generator with a Grocery CRUD style API.
Inspired by Grocery CRUD. Not affiliated with or endorsed by Grocery CRUD / John Skoumbourdis.

- The forked Grocery CRUD (CodeIgniter 3) sources, its sample database and its license files have been removed; they
  remain available in the git history (last commit before the rewrite: `fcdde04`). The new code base is MIT licensed.

- Framework-agnostic core distributed as a Composer library (`kctofficial/grocery-restable`), PHP 8.2+.
- Integrations: **CodeIgniter 4** (auto-discovered routes, service, spark commands, database groups, validation,
  sessions) and **no framework** (`NativeServer`).
- Engines: MySQL / MariaDB (PDO or MySQLi), PostgreSQL (PDO), SQLite (PDO); CodeIgniter MySQLi / Postgre / SQLite3.
- PostgREST-compatible querying: projection, embedding, filters, logical groups, relation filters, ordering, paging,
  counts, search, CSV.
- Writes: create, bulk create, upsert, update, replace, bulk update, delete, bulk delete, clone, many-to-many sync,
  validation (CodeIgniter rules), unique fields, field types, callbacks, actions, RPC.
- Field uploads and storage buckets with strict file validation.
- API keys, JWT, per-role permissions, row-level security scopes, CORS.
- OpenAPI 3.1 document and `_schema` metadata.
- Schema changes are detected immediately with a cheap fingerprint query per request (no waiting for the cache TTL).
- Admin GUI flow: *Getting started* checklist, Resources page listing every table with one-click **Expose** and the
  auto expose switch, per-table status bar, notifications for new tables, highlight.js code blocks, favicon, and
  offline fallback copies of all CDN libraries.
- phpMyAdmin-style admin GUI (jQuery 4, Bootstrap 5.3, Font Awesome 7 via CDN) with setup wizard; configuration
  stored in SQLite.
