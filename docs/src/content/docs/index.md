---
title: Grocery Restable
description: Turn any MySQL / MariaDB, PostgreSQL or SQLite database into a secure REST API - configured like Grocery CRUD, managed from a phpMyAdmin-style GUI.
template: splash
hero:
  tagline: Turn any MySQL / MariaDB, PostgreSQL or SQLite database into a secure REST API in minutes - the PostgREST / Supabase way, configured the Grocery CRUD way, managed from a phpMyAdmin-style GUI.
  image:
    file: ./images/dashboard.png
    alt: The Grocery Restable admin dashboard
  actions:
    - text: Get started
      link: getting-started/
      icon: right-arrow
    - text: Read the overview
      link: overview/
      variant: secondary
    - text: View on GitHub
      link: https://github.com/KCTOfficial/grocery-restable
      icon: external
      variant: minimal
---

## Your database, as an API

```php
// app/Config/GroceryRestable.php (CodeIgniter 4)
public function define(\GroceryRestable\GroceryRestable $api): void
{
    $api->resource('employees')
        ->setRelation('officeCode', 'offices', '{city} ({country})')
        ->requiredFields('lastName', 'email')
        ->setRules('email', 'required|valid_email')
        ->setFieldUpload('photo', 'employees', 'jpg|png');
}
```

```bash
curl "https://example.com/api/employees?select=lastName,offices(city)&officeCode=eq.4&order=lastName.desc" \
  -H "apikey: <YOUR_KEY>"
```

## What you get

| | |
|---|---|
| **Instant REST endpoints** | List, read, create (single, bulk, upsert), update, delete, clone and CSV export for every table and view. |
| **PostgREST-style queries** | `select` with embedded relations, filters (`eq`, `gt`, `in`, `ilike`, `is`, `not.` …), `or` / `and` groups, ordering, paging and exact counts. |
| **Relations like Grocery CRUD** | `belongs_to`, `many_to_many` (with writes) and `has_many` - in code, in the GUI, or detected from foreign keys. |
| **Three ways to define the API** | Fluent PHP code (Grocery CRUD compatible), the admin GUI (stored in SQLite), or automatic exposure of every table. |
| **Uploads & storage** | Field uploads and Supabase-style storage buckets with extension allow-lists, MIME sniffing and traversal protection. |
| **Security built in** | API keys (`anon` / `service_role`), JWT, per-role permissions, row-level security, CORS, encrypted secrets. |
| **Admin GUI** | phpMyAdmin-style interface: setup wizard, table browser, resource and relation editors, API keys, storage, docs, console. |
| **OpenAPI 3.1** | A live `openapi.json` for Swagger UI, Postman and code generators, plus `_schema` metadata for form builders. |

## Choose your setup

- **[CodeIgniter 4](codeigniter4.md)** - `composer require`, and routes, services and spark commands are discovered automatically.
- **[No framework](no-framework.md)** - a single front controller on plain PHP with PDO or MySQLi.
- Then follow the **[Getting started](getting-started.md)** tutorial, or jump to the **[REST API reference](rest-api.md)**.

---

<small>Grocery Restable is MIT licensed. Inspired by [Grocery CRUD](https://github.com/scoumbourdis/grocery-crud). Not affiliated with or endorsed by Grocery CRUD / John Skoumbourdis.</small>
