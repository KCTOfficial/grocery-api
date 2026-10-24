---
title: "Resources"
description: "Define resources in code, in the GUI or automatically — full method reference."
---

A **resource** is a table or view exposed under `/api/{name}`. Resources come from three sources:

| Source | How | Notes |
|---|---|---|
| **code** | `$api->resource('employees')->…` (CodeIgniter: `define()` of `app/Config/GroceryRestable.php`) | Supports everything, including callbacks, scopes and actions. Read-only in the GUI. |
| **GUI** | *API resource* tab, stored in SQLite | Everything that is serializable (no PHP callbacks). Can be exported as code. |
| **auto** | `auto_expose` setting | One resource per table of the default connection; relations from foreign keys; default permissions. |

Precedence for the same name: **code > GUI > auto**. A table that already has a code or GUI resource is never
auto exposed a second time (so a restricted resource cannot be bypassed through auto expose), and tables listed in
`auto_exclude_tables` are skipped. Resource names must match `[A-Za-z_][A-Za-z0-9_-]{0,63}` and cannot be `rpc`,
`storage`, `_schema` or `openapi`.

```php
$api->resource('employees');                 // table "employees"
$api->resource('staff', 'employees');        // endpoint /api/staff on table "employees"
$api->register((new Resource('staff', 'employees'))->setSubject('Staff'));
```

Calling `$api->resource('employees')` twice returns the same definition, so it can be extended in several places.

## Grocery CRUD compatibility

Every method has a camelCase name (CodeIgniter 4 style) and the Grocery CRUD snake_case alias:
`set_relation()` → `setRelation()`, `unset_delete()` → `unsetDelete()`, `callback_before_insert()` →
`callbackBeforeInsert()`, `set_relation_n_n()` → `setRelationNtoN()`, `field_type()` / `change_field_type()` →
`fieldType()`. See [Migrating from Grocery CRUD](migrating-from-grocery-crud.md).

## Method reference

All methods return the resource (fluent) unless noted. Lists of columns can be passed as variadic strings or arrays.

### Basics

| Method | Description |
|---|---|
| `setTable(string $table)` | Table or view. Also the default name. |
| `setName(string $name)` | Endpoint name. |
| `setConnection(?string $name)` | Use a non-default connection. |
| `setSubject(string $subject, ?string $plural = null)` | Human name (OpenAPI tags, GUI). |
| `setDescription(string $text)` | OpenAPI description. |
| `setPrimaryKey(string $field, ?string $table = null)` | Primary key of this table (views, tables without PK) or, with `$table`, of a related table. Without a single-column primary key only the list endpoint is available. |

### Columns and fields

| Method | Description |
|---|---|
| `columns(...$columns)` | Columns returned by the **list** endpoint (and by `select=*`). Relation names listed here are **embedded by default**. Default: all visible columns. |
| `unsetColumns(...$columns)` | Remove columns from the list output. |
| `setReadFields(...$fields)` | Columns returned by `GET /{name}/{id}`. Relation names are embedded by default. Default: all visible columns. |
| `unsetReadFields(...$fields)` | Remove columns from the single record output. |
| `fields(...$fields)` | Writable fields for create **and** update (columns and many-to-many relation names). |
| `addFields(...$fields)` / `editFields(...$fields)` | Writable fields for create / update only. |
| `unsetFields(...)`, `unsetAddFields(...)`, `unsetEditFields(...)` | Remove writable fields. |
| `requiredFields(...$fields)` | Required on create and replace (`PUT`); may not be emptied on update. |
| `uniqueFields(...$fields)` | Value must not exist in another row (checked before writing). |
| `setSearchable(...$columns)` | Columns used by `?q=`. Default: text-like list columns. |
| `displayAs(string\|array $field, ?string $label = null)` | Labels used in errors, `_schema` and OpenAPI. |
| `fieldType(string $field, string $type, mixed $extras = null)` | Override a field type, see below. |
| `setRules(string\|array $field, ?string $labelOrRules = null, ?string $rules = null)` | CodeIgniter validation rules. See [Validation](validation.md). |

Columns that can be filtered, ordered and searched are the union of list and read columns (hidden columns never).
Clients may also `select` any of these columns explicitly.

Default writable fields: every non-generated column except the primary key on update and an auto-increment primary
key on create, plus every many-to-many relation. Upload fields only accept files (multipart or the upload endpoint)
or `null` to clear them.

#### Field types

| Type | Output | Input |
|---|---|---|
| `hidden` | never returned | not writable; with `$extras` the value is **forced** on every insert/update (e.g. `fieldType('tenant_id', 'hidden', 42)`) |
| `invisible` | never returned | never writable (callbacks only) |
| `readonly` | returned | not writable |
| `password` | never returned | hashed with `password_hash()`; an empty value on update keeps the current hash |
| `enum`, `dropdown` | as is | must be one of `$extras` (list, or `value => label` map); default: the column's ENUM values |
| `set`, `multiselect` | JSON array | array or comma separated string, each value in `$extras`; stored comma separated |
| `boolean`, `true_false` | `true`/`false` | `true/false/1/0/yes/no/on/off` |
| `integer`, `number` | JSON numbers | numeric validation |
| `date`, `datetime`, `time` | as stored | parsed and normalized (`Y-m-d`, `Y-m-d H:i:s`; ISO 8601 accepted) |
| `json` | decoded JSON | object/array or a JSON string |
| `string`, `text` | as is | max length from the schema |

Without an override the type comes from the schema (`integer`, `number`, `boolean`, `string`, `text`, `date`,
`datetime`, `time`, `json`, `uuid`, `enum`, `binary`). Binary values are returned base64 encoded.

### Relations

| Method | Description |
|---|---|
| `setRelation($field, $relatedTable, $titleField, $where = null, $orderBy = null, $alias = null, $ownerKey = null)` | belongs_to (Grocery CRUD `set_relation`) |
| `setRelationNtoN($name, $junctionTable, $selectionTable, $keyToThisTable, $keyToSelectionTable, $titleField, $priorityField = null, $where = null)` | many_to_many (`set_relation_n_n`) |
| `setRelationHasMany($name, $childTable, $foreignKey, $titleField = null, $where = null, $orderBy = null, $localKey = null)` | has_many |
| `addRelation(Relation $relation)` / `removeRelation(string $name)` | Low level |

See [Relations](relations.md).

### Uploads

| Method | Description |
|---|---|
| `setFieldUpload($field, $uploadDir = '', $allowedFileTypes = '', $maxSize = null)` | The column stores the file name of an uploaded file. `$uploadDir` is relative to the upload path unless absolute; `$allowedFileTypes` like `'jpg\|png\|pdf'`; `$maxSize` like `'2MB'`. See [Uploads & storage](uploads-and-storage.md). |

### Operations

| Method | Disables / enables |
|---|---|
| `unsetList()` | `GET /{name}` |
| `unsetRead()` (`unset_view`) | `GET /{name}/{id}` |
| `unsetAdd()` | `POST /{name}` |
| `unsetEdit()` | `PUT` / `PATCH` (single and bulk), uploads |
| `unsetDelete()` | `DELETE` (single and bulk) |
| `unsetExport()` | `?format=csv` |
| `setClone()` / `unsetClone()` | `POST /{name}/{id}/clone` (disabled by default) |
| `unsetOperations()` | create, update, delete, clone, export (read only resource) |
| `unsetPrint()` | no-op, kept for compatibility |
| `setOperation(string $operation, bool $enabled)` | generic |

Disabled operations answer `405 Method Not Allowed`. Views are exposed read-only by auto expose.

### Query defaults

| Method | Description |
|---|---|
| `where($column, $value)` / `where('price >=', 10)` / `where($column, $operator, $value)` / `where([...])` | Condition applied to **every** read, update and delete (CodeIgniter Query Builder semantics: `null` → `IS NULL`, arrays → `IN`). |
| `orWhere(...)` | Same, combined with `OR` (`a AND b OR c`, like CodeIgniter). |
| `like($field, $match, $side = 'both')` / `orLike(...)` | `LIKE` conditions (`before`, `after`, `both`, `none`). |
| `orderBy($field, $direction = 'asc')` | Default order when the client sends none (the primary key is always appended for stable paging). |
| `limit(int $perPage)` | Default page size. |
| `setMaxLimit(int $max)` | Maximum page size for this resource. |

Raw SQL is never accepted in these conditions — use a column name and an operator (`=`, `!=`, `<`, `<=`, `>`, `>=`,
`in`, `not in`, `is null`, `is not null`, `like`, `not like`).

### Security

| Method | Description |
|---|---|
| `setPermissions(string\|array $role, ?array $operations = null)` | Grant `select`, `insert`, `update`, `delete` per role; `'*'` = everybody. Without it the default permissions apply. |
| `public()` | Everything for everybody (use when the route is protected by your framework). |
| `scope(callable $fn)` | Row-level security: `fn (Conditions $q, AuthContext $auth) => $q->where('owner_id', $auth->userId())`. |

See [Authentication](authentication.md).

### Callbacks and actions

`callbackBeforeInsert`, `callbackAfterInsert`, `callbackInsert`, `callbackBeforeUpdate`, `callbackAfterUpdate`,
`callbackUpdate`, `callbackBeforeDelete`, `callbackAfterDelete`, `callbackDelete`, `callbackBeforeClone`,
`callbackAfterClone`, `callbackColumn`, `callbackBeforeUpload`, `callbackAfterUpload`, `callbackUpload`,
`addAction`. See [Callbacks, actions & RPC](callbacks-actions-rpc.md).

### Introspection

`getName()`, `getTable()`, `getConnection()`, `getSubject()`, `getRelations()`, `getRelation($name)`,
`getUploads()`, `getPermissions()`, `isEnabled($operation)`, `toArray()`, `Resource::fromArray($array)`,
`problems(Table $table)` (configuration problems against the real table).

## A complete example

```php
use GroceryRestable\Auth\AuthContext;
use GroceryRestable\Resource\Conditions;

$api->resource('orders')
    ->setSubject('Order', 'Orders')
    ->setDescription('Customer orders')
    ->columns('orderNumber', 'orderDate', 'status', 'customer')         // "customer" relation embedded in lists
    ->setRelation('customerNumber', 'customers', '{customerName} ({country})', null, 'customerName', 'customer')
    ->setRelationHasMany('lines', 'orderdetails', 'orderNumber', null, null, 'orderLineNumber')
    ->fieldType('status', 'enum', ['In Process', 'Shipped', 'Cancelled'])
    ->fieldType('created_by', 'hidden')                                 // never returned
    ->requiredFields('orderDate', 'customerNumber')
    ->setRules('comments', 'permit_empty|max_length[500]')
    ->where('deleted', 0)
    ->orderBy('orderDate', 'desc')
    ->limit(50)
    ->unsetDelete()
    ->setPermissions(['authenticated' => ['select', 'insert', 'update'], 'admin' => ['select', 'insert', 'update', 'delete']])
    ->scope(fn (Conditions $q, AuthContext $auth) => $auth->role === 'admin' ? null : $q->where('salesRep', (string) $auth->userId()))
    ->callbackBeforeInsert(fn (array $data, AuthContext $auth) => $data + ['created_by' => $auth->userId()]);
```

## Auto generated resources

Auto expose (and *Create API resource* in the GUI) builds resources from the schema:

- one `belongs_to` per single-column foreign key, named after the referenced table (or the column without
  `_id`/`Id` when that collides), title guessed from `name`, `title`, `label`, `fullname`, `username`, `email`,
  `code`, `subject`, `description` or the first string column;
- one `has_many` per foreign key pointing to the table (name = child table, `{child}_by_{column}` on collisions);
- one `many_to_many` through every junction table (two single-column foreign keys forming the primary key, or a
  small table of at most five columns);
- views are read-only; tables without a single-column primary key expose only the list endpoint.

Customize an auto resource in the GUI (it becomes a GUI resource) or define it in code.
