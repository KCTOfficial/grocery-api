---
title: "Security"
description: "Production checklist, threat model and the protections built into Grocery Restable."
---

Grocery Restable exposes your database on the network, so its defaults are restrictive and every layer validates
its input. This page explains the protections and what you must configure.

## Production checklist

- [ ] `auth_mode` is **not** `none`.
- [ ] `default_permissions` give `anon` nothing (the default) and resources open only what is public.
- [ ] The `service_role` key is used **only** on servers; public clients use the `anon` key or user JWTs.
- [ ] `debug` is off (CodeIgniter: `CI_ENVIRONMENT = production`).
- [ ] `encryptionKey` is set (or the generated key file is backed up and readable only by PHP).
- [ ] `storagePath` (SQLite store, key file, uploads, buckets) is outside the web root and not world-readable.
- [ ] The admin GUI is disabled on public API servers, or restricted (`adminFilters`, VPN, reverse proxy allow-list).
- [ ] Setup was completed (the wizard is closed afterwards); `setupToken` removed if you used one.
- [ ] CORS origins are restricted to your web apps.
- [ ] HTTPS everywhere (API keys and JWTs are bearer credentials).
- [ ] The database user has only the privileges the API needs.
- [ ] Rate limiting in front of the API (`throttle` filter or reverse proxy).
- [ ] After migrations, review which new tables became endpoints (auto expose exposes them immediately).

## SQL injection

- All values are bound as parameters (`?`), with PDO native prepares (`ATTR_EMULATE_PREPARES = false` for MySQL),
  MySQLi prepared statements or CodeIgniter bindings.
- Every identifier coming from a request (columns, relations, aliases, order) is validated against an allow-list
  built from the introspected schema and the resource definition, then quoted by the engine grammar.
- `where()` / GUI conditions accept a column name and a fixed operator set, never raw SQL. Raw SQL is only possible
  in code (relation `$where` strings, your own actions / RPC functions) where you control it.
- Filter values are type checked against the column type before reaching the database.

## Authorization

- Default deny for `anon`; per-role permissions per resource and bucket; `service_role` bypass only with its key.
- Row-level `scope()` conditions apply to reads, updates, deletes, actions, uploads, downloads and to rows embedded
  from that resource; rows outside the scope are indistinguishable from missing rows (`404`).
- Embedding a table requires `select` on its resource; tables that are not exposed only reveal their key and title
  columns.
- Columns typed `hidden`, `invisible` or `password` are never returned and can never be filtered, ordered or
  searched (no boolean oracle on password hashes).
- A table that has a code or GUI resource is never auto exposed again under its own name.
- Bulk update / delete require at least one filter and are capped at `maxBulkRows`.

## Authentication

- API keys: 192-bit random, stored as SHA-256 hashes, revocable, shown once.
- JWT: HMAC only (`alg: none` / RS*/ES* rejected), constant time signature comparison, `exp` / `nbf` / `iss` / `aud`.
- Invalid credentials return `401` instead of falling back to `anon`.

## Uploads

Extension allow-lists, mandatory rejection of server-executable extensions and double extensions, active content
(HTML/SVG/JS) only when explicitly allowed, MIME sniffing that must match the extension, random sanitized file names,
storage outside the web root, `realpath` containment checks, safe download headers (`nosniff`, sandbox CSP,
attachment for non-inline types). Upload columns cannot be set to arbitrary paths through JSON. See
[Uploads & storage](uploads-and-storage.md).

## Output

- JSON encoded with invalid UTF-8 substitution; `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: no-referrer` on every API response.
- CSV export neutralizes spreadsheet formulas (`=`, `+`, `-`, `@` prefixes).
- Error messages never contain SQL, stack traces or database messages unless `debug` is on; 5xx errors are logged.

## Admin GUI

- Password hashing with `password_hash()` (rehash on login), timing-equalized login, 5 failures / 15 minutes / IP
  throttling, session id regeneration, 2-hour idle timeout.
- CSRF token required for the login form and every XHR call; XHR calls also require the session.
- Strict CSP (no inline scripts), CDN assets pinned with Subresource Integrity, every rendered value HTML-escaped,
  `X-Frame-Options: DENY`, `Cache-Control: no-store`.
- Secrets are write-only (database passwords and the JWT secret are never sent to the browser); exports exclude
  users, keys and secrets.
- The console runs requests server-side as a chosen role; it is available only to logged-in admins.

### Setup wizard exposure

Before the first setup the wizard is reachable only from `trustedSetupIps` (`127.0.0.1`, `::1`) or with
`?token=<setupToken>`. **Behind a reverse proxy that connects from localhost, every visitor looks local** — in that
case set `setupToken` (or run `php spark grocery-restable:setup`) before deploying, and make sure CodeIgniter's
`proxyIPs` is configured so the real client IP is used.

## Secrets at rest

Database passwords and the JWT secret are encrypted in SQLite with libsodium `secretbox` using `encryptionKey`
(derived to 256 bits with BLAKE2b). Without a configured key, a random key file is created next to the store with
`0600` permissions — anyone who can read both files can decrypt the secrets, so prefer an environment variable.

## Reporting vulnerabilities

Please report security issues privately to the maintainers instead of opening a public issue.
