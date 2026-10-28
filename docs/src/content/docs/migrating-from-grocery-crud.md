---
title: "Migrating from Grocery CRUD"
description: "Map Grocery CRUD methods and behaviour to Grocery Restable."
sidebar:
  label: "From Grocery CRUD"
---

Grocery CRUD renders HTML CRUD pages; Grocery Restable exposes the same definitions as a REST API (your front end can be
anything). The definition API is kept, so most controller code can be moved almost verbatim.

## Before / after

Grocery CRUD (CodeIgniter 3):

```php
public function employees_management()
{
    $crud = new grocery_CRUD();
    $crud->set_theme('datatables');
    $crud->set_table('employees');
    $crud->set_relation('officeCode', 'offices', 'city');
    $crud->display_as('officeCode', 'Office City');
    $crud->set_subject('Employee');
    $crud->required_fields('lastName');
    $crud->set_field_upload('file_url', 'assets/uploads/files');
    $output = $crud->render();
    $this->_example_output($output);
}
```

Grocery Restable (CodeIgniter 4, Grocery CRUD style controller):

```php
use GroceryRestable\CodeIgniter4\Libraries\GroceryRestable;

public function employees(string ...$segments)
{
    $crud = new GroceryRestable();
    $crud->set_table('employees');
    $crud->set_relation('officeCode', 'offices', 'city');
    $crud->display_as('officeCode', 'Office City');
    $crud->set_subject('Employee');
    $crud->required_fields('lastName');
    $crud->set_field_upload('file_url', 'files');      // relative to writable/grocery-restable/uploads
    return $crud->render($segments);                     // JSON API instead of HTML
}
```

```php
// app/Config/Routes.php
\GroceryRestable\CodeIgniter4\Routes::resource($routes, 'crud/employees', 'Examples::employees');   // keep it outside the built-in /api prefix
```

Or, preferably, define it once in `app/Config/GroceryRestable.php` (`$api->resource('employees')->…`) and use the built-in
`/api` routes. Remember to grant permissions (`->setPermissions()` / `->public()`), see
[Authentication](authentication.md).

## Method mapping

| Grocery CRUD | Grocery Restable | Notes |
|---|---|---|
| `set_table` | `setTable` | |
| `set_subject` | `setSubject` | |
| `set_primary_key($field, $table)` | `setPrimaryKey` | |
| `columns`, `unset_columns` | `columns`, `unsetColumns` | list output; relation names embed by default |
| `fields`, `add_fields`, `edit_fields` | same (camelCase) | writable fields |
| `unset_fields`, `unset_add_fields`, `unset_edit_fields` | same | |
| `set_read_fields`, `unset_read_fields` | `setReadFields`, `unsetReadFields` | single record output |
| `display_as` | `displayAs` | labels in errors, `_schema`, OpenAPI |
| `required_fields`, `unique_fields` | same | |
| `set_rules($field, $label, $rules)` | `setRules` | same argument order supported |
| `field_type` / `change_field_type` | `fieldType` | `hidden`, `invisible`, `readonly`, `password`, `enum`, `set`, `true_false`, `integer`, `date`, … |
| `set_relation` | `setRelation` | `$where` as array preferred; raw SQL only in code |
| `set_relation_n_n` | `setRelationNtoN` | writes via the relation name in the body |
| - | `setRelationHasMany` | new |
| `set_field_upload` | `setFieldUpload` | stored outside the web root by default; optional max size |
| `unset_add`, `unset_edit`, `unset_delete`, `unset_read`/`unset_view`, `unset_list`, `unset_export` | same | `405` when disabled |
| `unset_clone`, `set_clone` | same | clone is disabled by default |
| `unset_operations` | same | read-only resource |
| `unset_print` | no-op | |
| `where`, `or_where`, `like`, `or_like` | same | applied to reads, updates and deletes; no raw SQL / `$escape` |
| `order_by` | `orderBy` | default order |
| `limit` | `limit` | default page size |
| `callback_before_insert` … `callback_delete` | same (camelCase) | extra trailing `AuthContext` argument |
| `callback_before_clone`, `callback_after_clone` | same | |
| `callback_column` | `callbackColumn` | output transformation |
| `callback_before_upload`, `callback_after_upload`, `callback_upload` | same | see uploads docs for arguments |
| `callback_field`, `callback_add_field`, `callback_edit_field`, `callback_read_field` | - | form rendering is the client's job; use `_schema` metadata |
| `add_action` | `addAction` | a real endpoint `POST /{resource}/{id}/actions/{name}` |
| `set_theme`, `set_language`, `set_lang_string`, `unset_jquery*`, `unset_texteditor`, `set_crud_url_path` | - | HTML specific |
| `render()` | `render($segments)` | returns a JSON response |
| `getState()` | - | the HTTP method and path define the operation |

## Behaviour differences

- **Validation** returns `422` with per-field messages instead of re-rendering a form.
- **Unknown fields are errors** (Grocery CRUD silently ignored them).
- **Uploads** are validated for extension *and* real content; executables are always refused; files live in
  `writable/grocery-restable/uploads` and are downloaded through `GET /{resource}/{id}/file/{field}`.
- **Relations** are embedded on request (`select=*,offices(city)`), not flattened into a display string; the
  title template is used by `/_options/{field}` for dropdowns.
- **Security**: nothing is public until you grant permissions or configure an authentication mode - there is no page
  protected by "the controller's login check" unless you add a CodeIgniter filter.
