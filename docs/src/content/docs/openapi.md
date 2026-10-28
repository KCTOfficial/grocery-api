---
title: "OpenAPI and schema metadata"
description: "The generated OpenAPI 3.1 document and the _schema metadata endpoint."
sidebar:
  label: "OpenAPI"
---

## openapi.json

`GET {apiUrl}/openapi.json` returns an **OpenAPI 3.1** document generated from the live configuration:

- one tag per resource with its subject and description;
- paths for every enabled operation (list, create, bulk update/delete, read, replace, update, delete, clone,
  upload, download, relation options, actions), storage buckets and RPC functions;
- `components.schemas.{Resource}` (output: readable columns + relations) and `{Resource}Input` (writable fields with
  `required`), typed from the database schema and `fieldType()` overrides (enum values, max lengths, formats,
  nullable types, read-only keys, labels as `title`, column comments as `description`);
- reusable query parameters (`select`, `order`, `limit`, `offset`, `page`, `per_page`, `q`, `count`, `format`,
  `or`, `Prefer`) and a filter parameter per column;
- security schemes `apiKey` (header `apikey`) and `bearer`.

The document only describes what the caller can use: resources on which its role has at least one permission,
the buckets it can read and the RPC functions it may call (an anonymous caller does not learn the names of private
tables or columns). `service_role` gets everything. Disable it with the `expose_openapi` setting; it stays available
to `service_role`.

### Swagger UI / Redoc / Postman

Point any OpenAPI tool at the URL, e.g. Swagger UI:

```html
<div id="swagger"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
<script>SwaggerUIBundle({ url: '/api/openapi.json', dom_id: '#swagger' });</script>
```

Postman / Insomnia: *Import → Link* with the same URL. Code generators (`openapi-generator`) work as well.

The admin GUI renders the same document on the **API docs** tab and can download it.

## _schema

`GET {apiUrl}/_schema/{resource}` (or `/{resource}/_schema`) describes a resource for UI builders, including the
caller's effective permissions. `GET {apiUrl}/_schema` returns all resources the caller may read. See
[REST API reference](rest-api.md#schema-metadata).

Use it to generate forms dynamically: labels, types, required flags, ENUM options, upload constraints, rules and the
relation options endpoint for each field.

## Programmatic access

```php
$spec = $api->openApi('https://example.com/api');
file_put_contents('openapi.json', json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
```
