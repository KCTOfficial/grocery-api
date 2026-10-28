---
title: "Getting started"
description: "Install Grocery Restable, run the setup wizard and shape your first resources in about ten minutes."
---

This tutorial uses the MySQL **classicmodels** sample database (offices, employees, customers, orders,
orderdetails, products, payments …) - download it from
[mysqltutorial.org](https://www.mysqltutorial.org/getting-started-with-mysql/mysql-sample-database/) and import it
into MySQL / MariaDB - and CodeIgniter 4. The same steps work without a framework, see
[No framework](no-framework.md).

## 1. Install

```bash
composer create-project codeigniter4/appstarter shop
cd shop
composer require kctofficial/grocery-restable
```

Configure the database in `.env` as usual for CodeIgniter:

```ini
database.default.hostname = 127.0.0.1
database.default.database = classicmodels
database.default.username = root
database.default.password =
database.default.DBDriver = MySQLi
```

Recommended: give Grocery Restable its own encryption key for the secrets it stores in SQLite
(`php spark grocery-restable:publish` prints a fresh one):

```ini
groceryrestable.encryptionKey = base64:5KvNDtZtOJcKhY+1d+VQQHC8CE/MH/FcrqSdGrB0ZoI=
```

Nothing else is required: CodeIgniter discovers the Grocery Restable routes (`/api/*`, `/grocery-restable/*`),
the `groceryRestable` service and the `grocery-restable:*` spark commands automatically.

## 2. Run the setup wizard

```bash
php spark serve
```

Open <http://localhost:8080/grocery-restable>. The wizard asks for:

1. **Framework** - *CodeIgniter 4* (detected) or *No framework*.
2. **Database** - use the CodeIgniter database group, or enter a connection (engine + PHP driver).
3. **Admin account** - the login of this GUI.
4. **API options** - authentication mode and whether to *auto expose* every table.

At the end two API keys are displayed **once**: an `anon` key (public) and a `service_role` key
(full access, server side only). Store them.

![Setup wizard](images/setup-framework.png)

> The wizard is only reachable from `127.0.0.1` / `::1` unless you set `setupToken`.
> On a remote server use `php spark grocery-restable:setup` instead. See [Security](security.md).

## 3. Call the API

With *auto expose* enabled every table is already an endpoint:

```bash
KEY=gr_xxxxxxxx   # the service_role key

curl "http://localhost:8080/api/offices" -H "apikey: $KEY"
curl "http://localhost:8080/api/products?select=productName,buyPrice&buyPrice=gt.100&order=buyPrice.desc&limit=5" -H "apikey: $KEY"
curl "http://localhost:8080/api/orders?status=in.(Shipped,Resolved)&count=exact&limit=1" -H "apikey: $KEY" -i
```

## 4. Shape a resource in code

Auto expose already detected the relations from the foreign keys of classicmodels. Defining a resource in code lets
you name relations, choose titles, add validation, uploads and permissions - like in Grocery CRUD. For the upload
example add a column first:

```sql
ALTER TABLE employees ADD photo VARCHAR(255) NULL;
```

Publish the config and edit `define()`:

```bash
php spark grocery-restable:publish
```

```php
// app/Config/GroceryRestable.php
public function define(\GroceryRestable\GroceryRestable $api): void
{
    $api->resource('employees')
        ->setSubject('Employee')
        ->setRelation('officeCode', 'offices', '{city} ({country})')
        ->setRelationHasMany('customers', 'customers', 'salesRepEmployeeNumber', 'customerName')
        ->displayAs('officeCode', 'Office')
        ->requiredFields('lastName', 'email')
        ->setRules('email', 'required|valid_email')
        ->setFieldUpload('photo', 'employees', 'jpg|png|webp')
        ->setPermissions('anon', ['select']);

    // orderdetails is the junction table between orders and products
    $api->resource('orders')
        ->setRelation('customerNumber', 'customers', '{customerName} ({country})', null, null, 'customer')
        ->setRelationNtoN('products', 'orderdetails', 'products', 'orderNumber', 'productCode', 'productName', 'orderLineNumber')
        ->unsetDelete();
}
```

```bash
# Embed the office (belongs_to) and the customers (has_many)
curl "http://localhost:8080/api/employees?select=lastName,offices(city),customers(customerName)&limit=3"

# An order with its customer (belongs_to) and its products (many_to_many through orderdetails, in line order)
curl "http://localhost:8080/api/orders/10100?select=orderNumber,status,customer(customerName),products(productName)" -H "apikey: $KEY"

# Orders of French customers
curl "http://localhost:8080/api/orders?select=orderNumber,customer(customerName)&customer.country=eq.France&limit=5" -H "apikey: $KEY"

# Create with validation (employeeNumber is not auto-increment in classicmodels)
curl -X POST "http://localhost:8080/api/employees" -H "apikey: $KEY" -H "Content-Type: application/json" \
     -d '{"employeeNumber":1800,"lastName":"Doe","firstName":"Jane","email":"jane@example.com","extension":"x1","officeCode":"1","jobTitle":"Sales Rep"}'

# Upload a file into a field
curl -X POST "http://localhost:8080/api/employees/1800/upload/photo" -H "apikey: $KEY" -F "file=@me.png"
```

> Writing many-to-many links (`{"products": ["S10_1678", "S10_1949"]}`) inserts junction rows with only the two key
> columns, so it works for junction tables whose other columns are nullable or have defaults. `orderdetails` requires
> quantity and price (such a write returns `409 constraint_violation`), so manage its rows through an `orderdetails`
> resource instead.

## 5. Or shape it in the GUI

Open the **API** area, pick the endpoint in the sidebar (or **New** › *Configure first* for a table that is not exposed yet)
and work through its tabs: **Fields** (columns that are listed / readable / writable, labels, rules, field types),
**Relations**, **Permissions**, **Uploads** and **Filters & order**. **Save** (or Ctrl+S) stores the definition in SQLite
and it is live immediately. **PHP code** shows the equivalent fluent code if you later want to move it into your application.

![Resource editor](images/resource-editor.png)

## 6. Next steps

- Lock down access: [Authentication](authentication.md)
- Learn the full query language: [REST API reference](rest-api.md)
- Browse every resource option: [Resources](resources.md)
- Production checklist: [Security](security.md)
