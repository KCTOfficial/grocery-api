---
title: "Rate limiting"
description: "Configurable request limits per API key, user and IP, globally and per endpoint."
---

Every REST API request is counted and limited, so one client cannot flood the API or guess keys.

## How clients are counted

| Caller | Counted by |
|---|---|
| Anonymous (role `anon`, also with the public anon key) | client IP |
| API key | the key |
| JWT user | the `sub` claim (the IP when the token has none) |
| `service_role` | not limited while *Exempt the service_role* is on |
| Failed authentication (bad key or token) | client IP, so guessing keys is slowed down |

The limit is a fixed window: at most *N* requests per *window* seconds. When it is exceeded the API answers
`429 Too Many Requests`:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 37
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 37

{"error": {"code": "rate_limited", "message": "Too many requests. Try again in 37 seconds."}}
```

Every limited response carries `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset` (seconds until
the window resets); CORS exposes them to browsers.

## Configuration

**Settings > Rate limiting** in the GUI, or the settings in SQLite:

| Setting | Default | |
|---|---|---|
| `rate_limit_enabled` | `true` | Turn limiting on or off. |
| `rate_limit_requests` | `600` | Requests per window for API keys and JWT users. |
| `rate_limit_window` | `60` | Window length in seconds. |
| `rate_limit_anon_requests` | `120` | Requests per window for anonymous callers, per IP. `0` = same as `rate_limit_requests`. |
| `rate_limit_exempt_service` | `true` | `service_role` is not limited (server side jobs). |

### Per endpoint

An endpoint can have its own limit and counter, which replaces the global limit for it:

```php
$api->resource('login_attempts')->setRateLimit(10, 60);   // 10 requests per minute and client
$api->resource('reports')->setRateLimit(0);               // unlimited
```

In the GUI: **API** > endpoint > **General** > *Rate limit of this endpoint* (empty = global, `0` = unlimited).

## Where the counters live

In APCu when the extension is enabled (fast, per server), otherwise in the SQLite configuration store. With several
API servers behind a load balancer each server counts on its own: divide the limits by the number of servers, or
add a shared limit in the load balancer.

## Behind a reverse proxy

The limiter needs the real client IP.

- **CodeIgniter 4** uses `getIPAddress()`: list your proxies in `app/Config/App.php` `$proxyIPs`.
- **No framework** (`NativeServer`) uses `REMOTE_ADDR`, which cannot be spoofed. Behind a proxy every request then
  comes from the proxy's IP: limit anonymous traffic in the proxy (nginx `limit_req`), or pass a `Request` with the
  forwarded IP to `$api->handle()` yourself after validating the proxy.

The admin GUI login has its own throttling (5 failures per IP in 15 minutes).
