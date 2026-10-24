---
title: "Uploads and storage buckets"
description: "Field uploads, downloads and Supabase-style storage buckets with strict file validation."
sidebar:
  label: "Uploads & storage"
---

Grocery Restable handles files in two ways:

- **Field uploads** — a column stores the name of a file (Grocery CRUD `set_field_upload`).
- **Storage buckets** — named folders of files with their own permissions (Supabase Storage style).

Both share the same validation pipeline.

## Field uploads

```php
$api->resource('employees')
    ->setFieldUpload('photo', 'employees/photos', 'jpg|png|webp', '2MB')
    ->setFieldUpload('cv', 'employees/cv', 'pdf');
```

| Argument | Description |
|---|---|
| `$field` | Column storing the file name (`VARCHAR`) |
| `$uploadDir` | Directory **relative to `uploadPath`** (default `{storagePath}/uploads`, outside the web root) or an absolute path |
| `$allowedFileTypes` | Extensions separated by `\|` or `,`; empty = the `upload_allowed_types` setting |
| `$maxSize` | e.g. `512KB`, `2MB`; empty = the `upload_max_size` setting |

The column stores a generated name: 8 random hex characters + the sanitized original name, e.g.
`3f9a1c2e-my-photo.png` (Grocery CRUD style).

### Endpoints

```bash
# Upload / replace the file of a record (multipart field "file")
curl -X POST "$API/employees/1002/upload/photo" -H "apikey: $KEY" -F "file=@me.png"

# Create or update with files in the same request (multipart, field name = column)
curl -X POST "$API/employees" -H "apikey: $KEY" -F lastName=Doe -F email=j@example.com -F officeCode=1 -F "photo=@me.png"
curl -X POST "$API/employees/1002" -H "apikey: $KEY" -H "X-HTTP-Method-Override: PATCH" -F "photo=@new.png"

# Download (inline for images, PDF, audio, video, text; add ?download to force an attachment)
curl "$API/employees/1002/file/photo" -H "apikey: $KEY" -o photo.png

# Remove the file and set the column to NULL
curl -X DELETE "$API/employees/1002/upload/photo" -H "apikey: $KEY"
```

| Operation | Permission |
|---|---|
| upload / remove | `update` (and the `update` operation enabled) |
| multipart create | `insert` |
| download | `select` |

The response of an upload is the updated record. With `delete_replaced_files` (default on) the previous file is
deleted when it is replaced or cleared, and files are deleted together with their record. Upload columns cannot be
set from JSON (only `null` to clear them) — this prevents pointing a record at arbitrary files.

### Upload callbacks

```php
->callbackBeforeUpload(function (UploadedFileInterface $file, UploadField $field, AuthContext $auth) {
    return $file->getSize() < 100_000 ?: 'Images must be smaller than 100 KB.';   // false / string = reject
})
->callbackAfterUpload(function (array $info, UploadField $field, AuthContext $auth): void {
    // $info = ['file_name' => ..., 'path' => ..., 'size' => ..., 'mime' => ..., 'original_name' => ...]
    make_thumbnail($info['path']);
})
->callbackUpload(function (UploadedFileInterface $file, UploadField $field, AuthContext $auth): string {
    return $s3->put($file->getTempPath());      // replace the storage: return the value stored in the column
});
```

## Validation pipeline

Every uploaded file (fields and buckets) goes through these checks:

1. PHP upload error codes (`413` for size limits, `422` otherwise).
2. Maximum size (field / bucket setting, then the global setting).
3. Extension allow-list (field / bucket, then `upload_allowed_types`).
4. **Always rejected**: server-side executable extensions (`php`, `phtml`, `phar`, `cgi`, `pl`, `py`, `sh`, `asp(x)`,
   `jsp`, `exe`, `bat`, `dll`, `htaccess`, `ini`, …) and double extensions such as `shell.php.png`.
5. **Active content** (`html`, `htm`, `svg`, `js`, `xml`, `swf`, …) only when listed explicitly for that field / bucket.
6. The real MIME type is detected with `finfo` and must match the extension (`fake.png` containing text is rejected);
   dangerous MIME types (HTML, PHP, scripts, executables, SVG) are rejected unless explicitly allowed.
7. The stored name is random + sanitized (`[a-z0-9_-]`), so client file names never control paths.

Downloads use `Content-Disposition` (attachment for anything that is not a safe inline type),
`X-Content-Type-Options: nosniff` and `Content-Security-Policy: default-src 'none'; sandbox`, and file paths are
resolved with `realpath()` and checked to stay inside the upload directory.

## Storage buckets

Create buckets in the GUI (**Storage**) or in code:

```php
$api->bucket('avatars', ['public' => true, 'allowedTypes' => 'jpg|png|webp', 'maxSize' => '1MB']);
$api->bucket('invoices', [
    'allowedTypes' => 'pdf',
    'permissions' => ['authenticated' => ['select', 'insert'], 'accounting' => ['select', 'insert', 'update', 'delete']],
    'description' => 'Generated invoices',
]);
```

| Option | Description |
|---|---|
| `public` | Anybody (also `anon`) may list and download |
| `allowedTypes`, `maxSize` | Same as field uploads |
| `permissions` | Per role: `select` (list/download), `insert` (upload), `update` (overwrite), `delete`; `null` = default permissions |
| `description` | Shown in the GUI and OpenAPI |

Files live in `{bucketPath}/{bucket}/` (default `{storagePath}/buckets`).

### Storage API

```bash
# List buckets visible to the caller
curl "$API/storage" -H "apikey: $KEY"

# Upload (multipart): optional folder "path", optional "name", "upsert=true" to overwrite
curl -X POST "$API/storage/avatars" -H "apikey: $KEY" -F "file=@me.png" -F path=users/42
# → 201 {"bucket":"avatars","path":"users/42/me.png","name":"me.png","size":68,"mime":"image/png","url":"…/api/storage/avatars/users/42/me.png"}

# List a folder
curl "$API/storage/avatars?prefix=users/42" -H "apikey: $KEY"
# → {"prefix":"users/42","folders":[],"files":[{"name":"me.png","path":"users/42/me.png","size":68,"mime":"image/png","updated_at":"…"}]}

# Download (public buckets: no key needed, cached 1 hour)
curl "$API/storage/avatars/users/42/me.png" -o me.png

# Delete
curl -X DELETE "$API/storage/avatars/users/42/me.png" -H "apikey: $KEY"
```

Uploading to an existing path returns `409` unless `upsert=true` (or `PUT`), which requires `update`. Object paths
are limited to 10 segments of `[A-Za-z0-9._ -]` (no `..`, no leading dot); traversal attempts are rejected with `422`.

### Linking files to records

Store the bucket path in a regular column and let clients build the URL:

```json
{ "id": 42, "avatar": "users/42/me.png" }
```
```
https://example.com/api/storage/avatars/users/42/me.png
```

## Serving files directly from the web server

By default files are stored outside the web root and served through the API with permission checks. If you set an
absolute upload directory inside `public/`, the web server serves files without Grocery Restable — keep active content
disabled and make sure the server never executes scripts in that directory.
