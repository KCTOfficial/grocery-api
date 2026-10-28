---
title: "Callbacks, actions and RPC"
description: "Hook into writes, transform output, and add custom endpoints."
sidebar:
  label: "Callbacks, actions & RPC"
---

Callbacks are code-only (PHP callables). Every callback receives the Grocery CRUD arguments first and the
`GroceryRestable\Auth\AuthContext` of the caller last, so Grocery CRUD style callbacks work unchanged.

## Write callbacks

| Method | Signature | Return |
|---|---|---|
| `callbackBeforeInsert` | `fn (array $data, AuthContext $auth)` | modified `$data`, `false` to cancel, or nothing |
| `callbackAfterInsert` | `fn (array $data, int\|string $id, AuthContext $auth)` | ignored |
| `callbackInsert` | `fn (array $data, AuthContext $auth)` | **replaces** the INSERT; return the new primary key |
| `callbackBeforeUpdate` | `fn (array $data, int\|string $id, AuthContext $auth)` | modified `$data`, `false` to cancel |
| `callbackAfterUpdate` | `fn (array $data, int\|string $id, AuthContext $auth)` | ignored |
| `callbackUpdate` | `fn (array $data, int\|string $id, AuthContext $auth)` | **replaces** the UPDATE |
| `callbackBeforeDelete` | `fn (int\|string $id, AuthContext $auth)` | `false` to cancel |
| `callbackAfterDelete` | `fn (int\|string $id, AuthContext $auth)` | ignored |
| `callbackDelete` | `fn (int\|string $id, AuthContext $auth)` | **replaces** the DELETE (soft delete) |
| `callbackBeforeClone` | `fn (array $data, int\|string $sourceId, AuthContext $auth)` | modified `$data`, `false` to cancel |
| `callbackAfterClone` | `fn (array $data, int\|string $newId, AuthContext $auth)` | ignored |

- `$data` contains the validated, normalized **column** values (passwords already hashed, uploads already stored).
  Many-to-many ids are written after the insert/update and are not part of `$data`.
- Each write (including its many-to-many sync and callbacks) runs in a **database transaction**; an exception in any
  callback rolls everything back and removes files stored during the request.
- Several `before_*` / `after_*` callbacks can be registered; replacement callbacks (`callbackInsert`, …) keep only
  the last one.

Examples:

```php
$api->resource('posts')
    ->callbackBeforeInsert(fn (array $d, AuthContext $auth) => $d + ['author_id' => $auth->userId(), 'created_at' => date('Y-m-d H:i:s')])
    ->callbackBeforeUpdate(fn (array $d) => $d + ['updated_at' => date('Y-m-d H:i:s')])
    ->callbackAfterInsert(function (array $d, $id) {
        service('email')->setTo('editor@example.com')->setMessage("Post #$id created")->send();
    })
    ->callbackDelete(function ($id) {             // soft delete
        db_connect()->table('posts')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
    })
    ->where('deleted_at', null);                   // hide soft deleted rows everywhere
```

## Output callbacks

```php
->callbackColumn('price', fn ($value, array $row) => round((float) $value * 1.11, 2))
->callbackColumn('avatar', fn ($value) => $value ? base_url('uploads/' . $value) : null)
```

`$row` is the raw database row (all fetched columns). The returned value replaces the column in list, read and
embedded output.

## Upload callbacks

`callbackBeforeUpload`, `callbackAfterUpload`, `callbackUpload` - see
[Uploads & storage](uploads-and-storage.md#upload-callbacks).

## Actions

Custom endpoints attached to a record: `POST /{resource}/{id}/actions/{name}`.

```php
use GroceryRestable\Service\ActionContext;

$api->resource('orders')->addAction('ship', function ($id, array $payload, ActionContext $ctx) {
    $ctx->db->execute('UPDATE orders SET status = ?, shippedDate = ? WHERE orderNumber = ?', ['Shipped', date('Y-m-d'), $id]);

    return ['shipped' => $id, 'tracking' => $payload['tracking'] ?? null];
}, permission: 'update');
```

- The record must exist and be visible to the caller (`where()` / `scope()`), otherwise `404`.
- `$permission` (default `update`) is checked for the caller's role.
- `ActionContext` gives `auth`, `request` (headers, body, files), `db` (the resource connection) and `resource`.
- Return an array/scalar (JSON `200`), `null` (`204`) or a `GroceryRestable\Http\Response`.

## RPC functions

Free endpoints: `/rpc/{name}`.

```php
$api->rpc('sales_by_country', function (array $params, ActionContext $ctx) {
    return $ctx->db->select(
        'SELECT c.country, SUM(p.amount) AS total FROM payments p JOIN customers c ON c.customerNumber = p.customerNumber
         WHERE p.paymentDate >= ? GROUP BY c.country ORDER BY total DESC',
        [$params['since'] ?? '2000-01-01'],
    );
}, ['methods' => ['GET'], 'roles' => ['authenticated'], 'description' => 'Payments per country']);
```

```http
GET /api/rpc/sales_by_country?since=2004-01-01
```

| Option | Default | Description |
|---|---|---|
| `methods` | `['POST']` | Allowed HTTP methods. `GET` parameters come from the query string, others from the body. |
| `roles` | `['authenticated']` | Allowed roles; `'*'` = everybody. `service_role` is always allowed. |
| `description` | `''` | OpenAPI summary |
| `connection` | default | Connection passed as `$ctx->db` |

Always bind parameters (`?` placeholders) when you build SQL in actions and RPC functions.
