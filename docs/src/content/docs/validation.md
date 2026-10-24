---
title: "Validation"
description: "Required and unique fields, typed input checks and CodeIgniter validation rules."
---

Input is validated in this order; all problems are collected and returned together as `422 validation_failed`
with one message per field.

1. **Unknown / non-writable fields** — `Unknown field.` / `This field is not writable.`
2. **Required fields** — `requiredFields()` on create and replace (`PUT`); on `PATCH` a required field may be
   omitted but not emptied. NOT NULL columns without default are required on create automatically.
3. **Types** — from the schema or `fieldType()`: integers, numbers, booleans, dates, times, JSON, UUIDs, ENUM values,
   `enum`/`set` extras, maximum string length, `NULL` on NOT NULL columns.
4. **Rules** — `setRules()` (CodeIgniter syntax).
5. **Unique fields** — `uniqueFields()` (only when everything else is valid).

Database constraints remain the last line of defence: violations become `409 constraint_violation`.

## Rules

```php
$api->resource('users')
    ->setRules('email', 'required|valid_email|max_length[100]')
    ->setRules('username', 'Username', 'required|alpha_dash|min_length[3]')   // CodeIgniter order: field, label, rules
    ->setRules([
        'age' => 'permit_empty|integer|greater_than_equal_to[18]',
        'website' => 'permit_empty|valid_url_strict[https]',
        'role' => 'in_list[admin,editor,viewer]',
        'code' => 'regex_match[/^[A-Z]{3}-\d{4}$/]',
    ]);
```

Labels default to `displayAs()` (or a humanized column name) and appear in messages:
`The Username field must be at least 3 characters in length.`

Rules run on the raw input (before password hashing and type normalization). On `PATCH` only the fields that are
sent are validated; on create and `PUT` every rule runs (so `required` fires for missing fields). Fields that are
not sent are only checked by `required`-like rules. With the built-in validator, non-required fields that are sent
empty skip the remaining rules (like `permit_empty`); with CodeIgniter's validator add `permit_empty` as usual.

### CodeIgniter 4

With `useCodeIgniterValidation = true` (default) the CodeIgniter validation service evaluates the rules, so all of
its rules and your custom rule sets work, including database rules:

```php
->setRules('email', 'required|valid_email|is_unique[users.email,id,{id}]')
```

### Built-in validator (no framework)

Implemented rules: `required`, `required_with[f]`, `required_without[f]`, `permit_empty`, `if_exist`,
`min_length[n]`, `max_length[n]`, `exact_length[n,…]`, `valid_email`, `valid_emails`, `valid_url`,
`valid_url_strict[schemes]`, `valid_ip[ipv4|ipv6]`, `valid_json`, `valid_date[format]`, `numeric`, `integer`,
`decimal`, `is_natural`, `is_natural_no_zero`, `alpha`, `alpha_space`, `alpha_numeric`, `alpha_numeric_space`,
`alpha_dash`, `in_list[a,b]`, `not_in_list[a,b]`, `regex_match[/…/]`, `greater_than[n]`,
`greater_than_equal_to[n]`, `less_than[n]`, `less_than_equal_to[n]`, `matches[field]`, `differs[field]`, `string`.
`is_unique` / `is_not_unique` are ignored here — use `uniqueFields()`.

Custom rules:

```php
use GroceryRestable\Validation\Validator;

$validator = new Validator();
$validator->addRule('even', fn ($value) => (int) $value % 2 === 0, 'The {field} field must be even.');
$api->setValidator($validator);
```

Or implement `GroceryRestable\Validation\ValidatorInterface` to plug any validation library.

## Unique fields

```php
->uniqueFields('email', 'username')
```

Checked with a `SELECT` excluding the current record on update. Also add a unique index in the database to be safe
against concurrent requests.

## Cancelling from callbacks

`before_*` callbacks can reject a write by returning `false` (`400 cancelled`) or, for field-level messages, by
throwing:

```php
use GroceryRestable\Exception\ValidationException;

->callbackBeforeInsert(function (array $data) {
    if (($data['endDate'] ?? '') < ($data['startDate'] ?? '')) {
        throw ValidationException::forField('endDate', 'The end date must be after the start date.');
    }
    return $data;
})
```
