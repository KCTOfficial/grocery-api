---
title: "Uploads and storage buckets"
description: "Field uploads, S3 / R2 style storage buckets with object metadata, strict file validation and orphan file cleanup."
sidebar:
  label: "Uploads & storage"
---

Grocery Restable handles files in two ways:

- **Field uploads** - a column stores the name of a file (Grocery CRUD `set_field_upload`).
- **Storage buckets** - objects addressed by keys, with metadata and their own permissions, organized like S3 / R2.

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
set from JSON (only `null` to clear them) - this prevents pointing a record at arbitrary files.

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

Buckets are organized like **Amazon S3 / Cloudflare R2**: every file is an *object* addressed by a *key*
(`users/42/avatar.png`), with metadata, and a bucket listing works like S3's `ListObjectsV2`.

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

### How files are organized

```text
{storagePath}/
├── buckets/
│   ├── .tmp/                      uploads in progress (renamed into place when complete)
│   ├── avatars/                   one folder per bucket
│   │   └── users/42/me.png        the key is the path: avatars + users/42/me.png
│   └── invoices/2026/10/inv-1001.pdf
├── uploads/employees/3f9a1c2e-cv.pdf   field uploads (flat folder per field)
├── trash/2026-10-28/...            cleaned files, deleted after storage_trash_days
└── grocery-restable.sqlite        settings and the object index (metadata)
```

- **Keys** are tidy and predictable: the folder you send plus the file name, lower case, spaces and special
  characters turned into `-`, and the real extension (checked against the content), e.g. `Q3 Report.PNG` in
  `reports/2026` becomes `reports/2026/q3-report.png`. Send `name` to choose the file name. Keys have at most
  10 segments of `[A-Za-z0-9._ -]` and 1024 bytes (the S3 limit); `..` and leading dots are rejected.
- **The key is the path on disk**, so a bucket folder can be copied 1:1 to S3 or R2 (see below).
- **Writes are atomic**: the upload is written to `buckets/.tmp` and renamed into place, so readers never see a
  missing or half written file, also when an object is replaced.
- **Folders do not exist on their own** (like S3): they appear when a key contains `/`, and empty folders are
  removed when their last object is deleted.

### Object metadata

Each object has metadata stored in the SQLite index (S3 `HeadObject`):

| Field | |
|---|---|
| `path` | the key |
| `size` | bytes |
| `etag` | MD5 of the content (like an S3 single part upload); sent as the `ETag` header |
| `mime` | Content-Type detected from the content with `finfo` (never the client's claim) |
| `original_name` | the file name sent by the client (used as the download name) |
| `cache_control` | optional `Cache-Control` sent with downloads |
| `metadata` | your own key / value pairs (like `x-amz-meta-*`): keys `[A-Za-z0-9_-]`, text values, 2 KB max |
| `owner` | who uploaded it: `user:{sub}`, `key:{id}` or `role:{role}` |
| `created_at`, `updated_at` | UTC timestamps (`created_at` is kept when the object is replaced) |

Files copied into a bucket folder by hand (or restored from a backup) are indexed automatically the first time
the bucket is listed, by the daily housekeeping, or by the cleanup task *untracked objects*.

### Storage API

```bash
# Buckets visible to the caller
curl "$API/storage" -H "apikey: $KEY"

# Upload (multipart): folder "path", optional "name", "upsert=true" to overwrite,
# optional "cache_control" and "metadata" (JSON object)
curl -X POST "$API/storage/avatars" -H "apikey: $KEY" -F "file=@Me Photo.png" -F path=users/42 \
     -F "cache_control=public, max-age=86400" -F 'metadata={"author":"jane"}'
# 201 Created (200 when an existing object was replaced)
# {"bucket":"avatars","replaced":false,"name":"me-photo.png","path":"users/42/me-photo.png","size":68,
#  "mime":"image/png","etag":"8d777f385d3dfec8815d20f7496026dc","original_name":"Me Photo.png",
#  "cache_control":"public, max-age=86400","metadata":{"author":"jane"},"owner":"key:3",
#  "created_at":"2026-10-28T07:12:03Z","updated_at":"2026-10-28T07:12:03Z","url":".../api/storage/avatars/users/42/me-photo.png"}

# Metadata only
curl "$API/storage/avatars/users/42/me-photo.png?info" -H "apikey: $KEY"
curl -I "$API/storage/avatars/users/42/me-photo.png"        # HEAD: headers only

# Download (public buckets: no key needed); add ?download for an attachment
curl "$API/storage/avatars/users/42/me-photo.png" -o me.png

# Delete
curl -X DELETE "$API/storage/avatars/users/42/me-photo.png" -H "apikey: $KEY"
```

Uploading to an existing key returns `409` unless `upsert=true` (or `PUT`), which requires `update`.

### Listing (ListObjectsV2)

```bash
curl "$API/storage/avatars?prefix=users/&delimiter=/&limit=100" -H "apikey: $KEY"
```

| Parameter | Default | |
|---|---|---|
| `prefix` | `''` | Key prefix. A folder is listed with a trailing slash: `users/42/` |
| `delimiter` | `/` | `/` groups deeper keys into folders (common prefixes); empty (`delimiter=`) lists every key below the prefix |
| `limit` | `1000` | Maximum keys + folders per page (1 to 1000) |
| `cursor` | | `next_cursor` of the previous page |

```json
{
  "bucket": "avatars", "prefix": "users/", "delimiter": "/",
  "prefixes": ["users/41/", "users/42/"], "folders": ["41", "42"],
  "files": [],
  "count": 2, "truncated": false, "next_cursor": null
}
```

Keys are returned in binary order like S3. `prefixes` are the full common prefixes, `folders` the same names
relative to the prefix.

### Caching

Downloads carry `ETag` (the MD5), `Last-Modified` and `Cache-Control`. A request with `If-None-Match: "<etag>"`
gets `304 Not Modified` without the body, so browsers and CDNs revalidate cheaply.

| Bucket | `Cache-Control` |
|---|---|
| public | the object's `cache_control`, otherwise `public, max-age=3600` |
| private | `private, no-store`, or the object's `cache_control` only when it starts with `private` (private files never go to shared caches) |

### Moving to S3 or Cloudflare R2

Because keys and paths match, a bucket folder can be synced with standard tools, for a backup, a CDN, or a
migration:

```bash
rclone sync writable/grocery-restable/buckets/avatars r2:avatars        # Cloudflare R2
aws s3 sync writable/grocery-restable/buckets/avatars s3://my-avatars   # Amazon S3
```

The object metadata stays in the SQLite index (`storage_objects` table); copy `cache_control` or `metadata` with
your sync tool's options if you need them on the remote side.

### Linking files to records

Store the key in a regular column and let clients build the URL:

```json
{ "id": 42, "avatar": "users/42/me-photo.png" }
```
```
https://example.com/api/storage/avatars/users/42/me-photo.png
```

## Orphan files and cleanup

Files are deleted together with the data that uses them where possible:

- A record deleted through the API, or a field upload that is replaced or cleared, deletes its file
  (`delete_replaced_files`, default on).
- Rows deleted or changed in the admin **Data** browser release their files the same way, unless another row still
  uses the same file.
- Deleting a bucket in the GUI offers to move its files to the trash.

Files can still become orphans: rows deleted with SQL, imports, a crash between saving a file and the database
commit, upload fields removed from a resource. The **storage cleanup** finds and cleans them.

| Task | What | Result |
|---|---|---|
| `orphan_files` | Files of upload fields that no row references (checked against every resource using that folder) | moved to the trash |
| `orphan_buckets` | Folders of buckets that no longer exist | moved to the trash |
| `stale_index` | Metadata rows whose file is gone, or of deleted buckets | removed |
| `untracked_objects` | Bucket files without metadata (copied by hand) | indexed |
| `tmp_files` | Interrupted uploads older than one hour | deleted |
| `empty_folders` | Empty folders inside buckets | removed |
| `trash` | Trash days older than `storage_trash_days` | deleted |
| `trash_all` | Everything in the trash | deleted |

Safety rules:

- A scan is a dry run; nothing changes until you clean.
- Files younger than `orphan_min_age_hours` (24) are never orphans, so uploads in progress are safe.
- Orphans are **moved** to `{storagePath}/trash/{day}/` and deleted after `storage_trash_days` (7); restore a file by
  moving it back. With `storage_trash_days = 0` they are deleted immediately.
- An upload folder is **skipped** (and reported) when one of the resources using it cannot be checked: its table or
  column does not exist, or it stores files with a custom `callbackUpload()`.
- Only folders configured as upload fields or buckets are touched; other files under `storagePath` are left alone.

### Running it

- **GUI:** *Settings > Storage cleanup* (also linked from the Storage sidebar): *Scan*, review the report and the
  listed files, select the tasks, *Clean selected*.
- **Daily housekeeping** (`storage_auto_cleanup`, on by default): once a day, on the first storage upload or delete,
  the safe tasks run automatically: `stale_index`, `untracked_objects`, `tmp_files`, `empty_folders`, `trash`.
  Orphans are never removed automatically.
- **CodeIgniter 4:**

  ```bash
  php spark grocery-restable:cleanup                     # report (dry run)
  php spark grocery-restable:cleanup --run               # orphans + housekeeping (not trash_all)
  php spark grocery-restable:cleanup --run --tasks=orphan_files --min-age=72
  ```

  Schedule it with cron: `0 3 * * * cd /var/www/app && php spark grocery-restable:cleanup --run`.
- **Without a framework** (a cron script):

  ```php
  $api = GroceryRestable::create(require __DIR__ . '/../config/grocery-restable.php');
  $report = $api->janitor()->scan();                              // dry run
  $done = $api->janitor()->clean(['orphan_files', 'orphan_buckets', 'stale_index', 'untracked_objects', 'tmp_files', 'empty_folders', 'trash']);
  ```

| Setting | Default | |
|---|---|---|
| `storage_auto_cleanup` | `true` | Daily housekeeping (safe tasks only) |
| `orphan_min_age_hours` | `24` | Minimum age of an orphan |
| `storage_trash_days` | `7` | Days cleaned files stay in the trash (`0` = delete immediately) |
| `delete_replaced_files` | `true` | Delete a field file when it is replaced, cleared or its record is deleted |

## Serving files directly from the web server

By default files are stored outside the web root and served through the API with permission checks. If you set an
absolute upload directory inside `public/`, the web server serves files without Grocery Restable - keep active content
disabled and make sure the server never executes scripts in that directory.
