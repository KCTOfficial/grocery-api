---
title: "Logging"
description: "JSON logs of API requests and admin actions, one file per day, with monitoring and clean-up in the GUI."
---

Grocery Restable writes a JSON log of API requests and admin actions: **one file per day**, one JSON object per
line ([JSON Lines](https://jsonlines.org)), in `{storagePath}/logs` (option `logPath`):

```text
writable/grocery-restable/logs/2026-10-27.jsonl
writable/grocery-restable/logs/2026-10-28.jsonl
```

## Entries

An API request:

```json
{"time":"2026-10-28T14:03:12.418+07:00","type":"request","level":"info","method":"GET","path":"/employees","query":"select=*&limit=20","status":200,"duration_ms":12.4,"ip":"203.0.113.7","role":"authenticated","auth":"api_key","key_id":3,"resource":"employees","via":"api","user_agent":"Mozilla/5.0 ..."}
```

| Field | |
|---|---|
| `time` | ISO 8601 with milliseconds and the server's time zone |
| `type` | `request` (REST API) or `admin` (GUI) |
| `level` | `info` (status < 400), `warning` (4xx, failed logins), `error` (5xx) |
| `method`, `path`, `query`, `status`, `duration_ms` | the request; secrets in the query string (`apikey`, `token`, `password` ...) are masked |
| `ip`, `role`, `auth`, `key_id`, `user` | who called (`user` is the JWT `sub`) |
| `resource` | the endpoint, when there is one |
| `via` | `api`, or `console` for requests sent from the GUI console |
| `error` | `code` and `message`; for 5xx also the exception class and message |

An admin action (logins, failed logins, every configuration change made in the GUI):

```json
{"time":"2026-10-28T14:05:40.002+07:00","type":"admin","level":"info","action":"resource.save","user":"admin","ip":"127.0.0.1","target":"employees"}
```

Request bodies, passwords and API keys are never written. Files are created with `0600` permissions; keep
`storagePath` outside the web root.

## Settings

**Settings > Logging** in the GUI:

| Setting | Default | |
|---|---|---|
| `log_requests` | `all` | `all`, `errors` (status >= 400) or `none` |
| `log_admin` | `true` | admin logins and configuration changes |
| `log_retention_days` | `14` | files older than this are deleted when the first entry of a new day is written; `0` keeps them forever |

## Monitoring in the GUI

The **Logs** area lists one entry per day. A day shows:

- filters in one row: type, level, status class (2xx, 4xx, 5xx), method and a text search;
- counters: API requests, client errors, server errors, average and 95th percentile duration, admin actions;
- entries per hour (hover a bar; *Table view* lists the numbers), top endpoints and client IPs;
- the entries, newest first; click one for every field and the raw JSON;
- *Live*: today's log refreshes every 5 seconds;
- *Download* (the `.jsonl` file), *Delete day*, and *Clean up* in the sidebar (keep the last *N* days, or delete all).

## Using the files elsewhere

JSON Lines work with the usual tools:

```bash
# server errors of the day
jq -c 'select(.status >= 500)' writable/grocery-restable/logs/2026-10-28.jsonl

# requests per endpoint
jq -r 'select(.type == "request") | .resource // "-"' 2026-10-28.jsonl | sort | uniq -c | sort -rn
```

Log shippers (Vector, Fluent Bit, Filebeat, Promtail) can tail the directory as JSON input.

## From PHP

```php
$logs = $api->logs();
$logs->days();                                      // [['day' => '2026-10-28', 'size' => 1234, ...], ...]
$logs->read('2026-10-28', ['status' => '5xx'], 1, 50); // entries, total, stats
$logs->deleteOlderThan(30);                         // keep the last 30 days
```

Errors are still sent to the configured `logger` (CodeIgniter `log_message()` or PSR-3) as before.
