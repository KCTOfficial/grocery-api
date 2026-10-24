---
title: "Overview"
description: "What Grocery Restable is, how it works, and the vocabulary used throughout the documentation."
---

> Inspired by Grocery CRUD. Not affiliated with or endorsed by Grocery CRUD / John Skoumbourdis.

Grocery Restable exposes the tables of a relational database as a REST API and gives you three ways to shape it:

1. **Code** — a fluent, Grocery CRUD compatible PHP API (`setRelation()`, `requiredFields()`, callbacks …).
2. **GUI** — a phpMyAdmin-style admin interface; definitions are stored in SQLite.
3. **Auto** — every table is exposed automatically, relations are detected from foreign keys.

When the same resource exists in more than one place, **code wins over GUI, and GUI wins over auto**.

## How it fits together

```text
HTTP ─► CodeIgniter 4 or a plain PHP front controller
          │
          ▼
     Grocery Restable engine ── authentication, permissions, row-level security
          │                     query parsing (select, filters, order, paging)
          │                     validation, callbacks, uploads
          ▼
     Your database (MySQL / MariaDB, PostgreSQL, SQLite)

     SQLite store ── settings, GUI resources, connections, API keys, admin users
```

Requests are translated into parameterized SQL against the tables you exposed. Everything you configure in the GUI
is stored in a small SQLite database next to your application; resources defined in code are versioned with your
application. See [Architecture](architecture.md) for the details.

## Where to start

| If you want to… | Read |
|---|---|
| Try it in ten minutes | [Getting started](getting-started.md) |
| Install it in CodeIgniter 4 | [CodeIgniter 4](codeigniter4.md) |
| Run it without a framework | [No framework](no-framework.md) |
| Shape endpoints in code | [Resources](resources.md) and [Relations](relations.md) |
| Call the API from a client | [REST API reference](rest-api.md) |
| Lock it down for production | [Authentication](authentication.md) and [Security](security.md) |
| Move an existing Grocery CRUD project | [Migrating from Grocery CRUD](migrating-from-grocery-crud.md) |

## Vocabulary

| Term | Meaning |
|---|---|
| **Resource** | A table or view exposed as an endpoint (`/api/{resource}`). Its name defaults to the table name. |
| **Relation** | A link from a resource to another table: `belongs_to`, `has_many` or `many_to_many`. |
| **Embedding** | Returning related rows inside the parent row: `select=*,offices(city)`. |
| **Role** | Who calls the API: `anon` (no credentials), `authenticated`, `service_role` (full access) or any custom role. |
| **Permission** | `select`, `insert`, `update`, `delete` granted to a role on a resource or bucket. |
| **Scope** | A row-level filter computed per request (row-level security). |
| **Bucket** | A named folder of files exposed under `/api/storage/{bucket}`. |
| **Connection** | A database the API reads from: a CodeIgniter database group or one configured in the GUI. |
| **Engine / driver** | The database product (MySQL, PostgreSQL, SQLite) and the PHP extension used to reach it (PDO, MySQLi). |
