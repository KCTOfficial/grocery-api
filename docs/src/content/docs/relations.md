---
title: "Relations and embedding"
description: "belongs_to, has_many and many_to_many relations, embedding, relation filters and options."
sidebar:
  label: "Relations"
---

Relations let clients **join** related tables in one request and let resources **write** many-to-many links.
They do not require foreign keys in the database, but foreign keys are used to detect relations automatically.

| Type | Grocery CRUD | Meaning | Embedded as |
|---|---|---|---|
| `belongs_to` | `set_relation` | `this.field → related.key` | object or `null` |
| `has_many` | — | `child.foreignKey → this.key` | array |
| `many_to_many` | `set_relation_n_n` | `this.pk ← junction → selection.pk` | array |

## belongs_to

```php
->setRelation(
    'officeCode',            // local column (foreign key)
    'offices',               // related table
    '{city} ({country})',    // title: a column or a template with {column} placeholders
    ['active' => 1],         // optional conditions on the related rows (array, Conditions or, in code only, raw SQL)
    'city desc',             // optional order of related rows (options endpoint)
    'office',                // optional relation name (default: the related table name)
    'officeCode',            // optional referenced column (default: primary key of the related table)
)
```

```http
GET /api/employees?select=lastName,office(city,country)
```
```json
[{ "lastName": "Murphy", "office": { "city": "San Francisco", "country": "USA" } }]
```

The local column keeps its raw value (`officeCode`); the embedded object appears under the relation name.

## has_many

```php
->setRelationHasMany(
    'customers',                 // relation name
    'customers',                 // child table
    'salesRepEmployeeNumber',    // column of the child table referencing this table
    'customerName',              // optional title
    null,                        // optional conditions
    'customerName',              // optional order
    null,                        // optional local key (default: primary key)
)
```

```http
GET /api/employees/1370?select=lastName,customers(customerName,creditLimit)
```

## many_to_many

```php
->setRelationNtoN(
    'actors',        // relation name, also the body key used to write links
    'film_actor',    // junction table
    'actor',         // selection table
    'film_id',       // junction column referencing this table
    'actor_id',      // junction column referencing the selection table
    'fullname',      // title
    'priority',      // optional priority column of the junction (keeps the order)
    null,            // optional conditions on the selection table
)
```

Embedding:

```http
GET /api/film?select=title,actors(actor_id,fullname)
```

Writing (create, update, upsert): send the list of related ids under the relation name. The junction table is
synchronized: missing links are inserted, removed ones deleted, the priority column rewritten in the given order.
Extra junction columns of kept links are preserved.

```http
PATCH /api/film/1
Content-Type: application/json

{ "title": "Academy Dinosaur", "actors": [5, 2, 9] }
```

With form posts send `actors=5,2,9` or `actors[]=5&actors[]=2`. Deleting a record first removes its junction rows
(like Grocery CRUD). Cloning copies the links.

## Embedding syntax

```
select=*,office(*)                       all list columns + the relation
select=id,o:office(city)                 alias "o"
select=*,customers(customerName,orders(orderNumber,status))   nested (up to maxEmbedDepth, default 3)
select=title,actors                      a relation name without parentheses embeds all its columns
```

- Embedding is done with **one extra query per relation**, never one per row.
- Relation names listed in `columns()` / `setReadFields()` (or ticked in the GUI) are embedded **by default** when
  the client sends no `select`.
- Nested embedding through a table requires that table to be exposed as a resource (its relations are used).

### What can be embedded

The target table is looked up in the registry:

- **The table is exposed as a resource** → its visible columns, its `where()` conditions and its row-level `scope()`
  apply, and the caller needs `select` permission on it. Explicitly requested embeds fail with `401/403` when the
  permission is missing; default embeds are silently skipped.
- **The table is not exposed** → only its key and the columns of the title template are visible. This is how
  `setRelation('officeCode', 'offices', 'city')` behaves in Grocery CRUD: you see the city, not the whole table.

## Filtering on related tables

Use `relation.column` as filter key; the filter becomes a sub-query (`IN (SELECT …)`):

```http
GET /api/employees?offices.country=eq.France
GET /api/film?actors.fullname=ilike.*guiness*
GET /api/offices?employees.jobTitle=eq.President
GET /api/employees?or=(offices.city.eq.Paris,offices.city.eq.London)
```

The same visibility rules as for embedding apply to the filtered columns.

## Options endpoint (dropdowns)

```http
GET /api/employees/_options/officeCode?q=par&limit=20      belongs_to: by local column
GET /api/film/_options/actors                              many_to_many: by relation name
```
```json
[{ "value": "4", "label": "Paris (France)" }]
```

Labels come from the title template, the order from the relation order (or the first title column), the search
`q` runs over the title columns, and the target's conditions / scopes apply. Requires `select` on the resource.

## Raw SQL conditions

In code only, the `$where` argument may be a raw SQL string for backward compatibility with Grocery CRUD:

```php
->setRelation('officeCode', 'offices', 'city', "r.country <> 'USA'")
```

Use the alias `r` for the related table (and `j` for the junction table of many-to-many). Raw strings are never
accepted from GUI definitions or clients. Prefer arrays: `['country !=' => 'USA']`.
