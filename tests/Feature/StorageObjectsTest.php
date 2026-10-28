<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Feature;

use GroceryRestable\Tests\Support\ApiTestCase;
use GroceryRestable\Tests\Support\Fixture;

/**
 * S3 / R2 style buckets (keys, metadata, ListObjectsV2, ETag) and the orphan cleanup.
 */
final class StorageObjectsTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->api->resource('employees')->setFieldUpload('file_url', 'employees', 'png|pdf');
        $this->api->bucket('docs', ['allowedTypes' => 'png|pdf|txt']);
        $this->api->bucket('pub', ['public' => true, 'allowedTypes' => 'png']);
    }

    private function upload(string $bucket, string $folder, string $name = 'a.png', array $form = []): array
    {
        $content = str_ends_with($name, '.txt') ? 'hello ' . $name : Fixture::png();

        return $this->call('POST', 'storage/' . $bucket, null, [], ['file' => Fixture::uploadedFile($this->dir, $name, $content)], ['path' => $folder] + $form);
    }

    private function bucketDir(string $bucket): string
    {
        return $this->dir . '/storage/buckets/' . $bucket;
    }

    // ------------------------------------------------------------------ objects

    public function testUploadStoresS3StyleMetadata(): void
    {
        [$status, $object] = $this->upload('docs', 'reports/2026', 'Q3 Report.png', ['cache_control' => 'max-age=600', 'metadata' => '{"Author":"jane","year":2026}']);
        self::assertSame(201, $status);
        self::assertSame('reports/2026/q3-report.png', $object['path'], 'keys are tidy: lower-case, dashes, real extension');
        self::assertSame(md5(Fixture::png()), $object['etag']);
        self::assertSame('image/png', $object['mime']);
        self::assertSame('Q3 Report.png', $object['original_name']);
        self::assertSame(['author' => 'jane', 'year' => '2026'], $object['metadata']);
        self::assertSame('max-age=600', $object['cache_control']);
        self::assertSame('role:service_role', $object['owner']);
        self::assertFileExists($this->bucketDir('docs') . '/reports/2026/q3-report.png', 'the key is the path on disk');
        self::assertSame([], glob($this->dir . '/storage/buckets/.tmp/*'), 'no temporary file left');

        self::assertSame(422, $this->upload('docs', 'x', 'b.png', ['metadata' => '{"bad key":"x"}'])[0]);
        self::assertSame(422, $this->upload('docs', 'x', 'b.png', ['cache_control' => "max-age=1\r\nX: y"])[0]);
    }

    public function testListObjectsV2(): void
    {
        foreach (['a.txt', 'b.txt', 'c.txt'] as $name) {
            $this->upload('docs', 'users/1', $name);
        }
        $this->upload('docs', 'users/2', 'z.txt');
        $this->upload('docs', '', 'root.txt');

        [, $root] = $this->call('GET', 'storage/docs');
        self::assertSame(['users/'], $root['prefixes']);
        self::assertSame(['users'], $root['folders']);
        self::assertSame(['root.txt'], array_column($root['files'], 'path'));

        [, $users] = $this->call('GET', 'storage/docs?prefix=users/');
        self::assertSame(['users/1/', 'users/2/'], $users['prefixes']);

        [, $page1] = $this->call('GET', 'storage/docs?prefix=users/1/&limit=2');
        self::assertSame(['users/1/a.txt', 'users/1/b.txt'], array_column($page1['files'], 'path'));
        self::assertTrue($page1['truncated']);
        [, $page2] = $this->call('GET', 'storage/docs?prefix=users/1/&limit=2&cursor=' . rawurlencode($page1['next_cursor']));
        self::assertSame(['users/1/c.txt'], array_column($page2['files'], 'path'));
        self::assertFalse($page2['truncated']);

        [, $folders] = $this->call('GET', 'storage/docs?prefix=users/&limit=1');
        [, $next] = $this->call('GET', 'storage/docs?prefix=users/&limit=1&cursor=' . rawurlencode($folders['next_cursor']));
        self::assertSame(['users/2/'], $next['prefixes'], 'a folder cursor skips everything inside the folder');

        [, $flat] = $this->call('GET', 'storage/docs?delimiter=');
        self::assertSame(['root.txt', 'users/1/a.txt', 'users/1/b.txt', 'users/1/c.txt', 'users/2/z.txt'], array_column($flat['files'], 'path'), 'recursive listing in key order');
    }

    public function testDownloadHeadersConditionalRequestsAndInfo(): void
    {
        [, $object] = $this->upload('pub', 'img', 'logo.png', ['cache_control' => 'public, max-age=86400']);
        [$status, , $response] = $this->call('GET', 'storage/pub/img/logo.png');
        self::assertSame(200, $status);
        self::assertSame('"' . $object['etag'] . '"', $response->getHeader('ETag'));
        self::assertSame('public, max-age=86400', $response->getHeader('Cache-Control'));
        self::assertStringEndsWith('GMT', (string) $response->getHeader('Last-Modified'));

        self::assertSame(304, $this->call('GET', 'storage/pub/img/logo.png', null, ['If-None-Match' => '"' . $object['etag'] . '"'])[0]);
        [$status, , $head] = $this->call('HEAD', 'storage/pub/img/logo.png');
        self::assertSame([200, (string) strlen(Fixture::png()), ''], [$status, $head->getHeader('Content-Length'), $head->getBody()]);

        [$status, $info] = $this->call('GET', 'storage/pub/img/logo.png?info');
        self::assertSame([200, 'logo.png', $object['etag']], [$status, $info['original_name'], $info['etag']]);

        // A private bucket never sends a shared cache header, whatever the object metadata says.
        $this->upload('docs', '', 'p.png', ['cache_control' => 'public, max-age=86400']);
        self::assertSame('private, no-store', $this->call('GET', 'storage/docs/p.png')[2]->getHeader('Cache-Control'));
    }

    public function testFilesCopiedByHandAreIndexedAndDeletesLeaveNoEmptyFolders(): void
    {
        mkdir($this->bucketDir('docs') . '/legacy/deep', 0777, true);
        file_put_contents($this->bucketDir('docs') . '/legacy/deep/old.txt', 'old file');

        [, $list] = $this->call('GET', 'storage/docs?delimiter=');
        self::assertSame(['legacy/deep/old.txt'], array_column($list['files'], 'path'));
        self::assertSame(md5('old file'), $list['files'][0]['etag']);

        self::assertSame(204, $this->call('DELETE', 'storage/docs/legacy/deep/old.txt')[0]);
        self::assertDirectoryDoesNotExist($this->bucketDir('docs') . '/legacy', 'S3 has no folders: empty ones are removed');
        self::assertNull($this->api->store()->object('docs', 'legacy/deep/old.txt'));
    }

    // ------------------------------------------------------------------ cleanup

    public function testOrphanFieldFilesAreFoundAndMovedToTheTrash(): void
    {
        [, $row] = $this->call('POST', 'employees/1002/upload/file_url', null, [], ['file' => Fixture::uploadedFile($this->dir, 'used.png', Fixture::png())]);
        $uploads = $this->dir . '/storage/uploads/employees';
        file_put_contents($uploads . '/deadbeef-old.png', 'orphan');
        touch($uploads . '/deadbeef-old.png', time() - 3 * 86400);
        file_put_contents($uploads . '/cafebabe-new.png', 'fresh');   // younger than the minimum age
        touch($uploads . '/' . $row['file_url'], time() - 3 * 86400);  // old but referenced

        $janitor = $this->api->janitor();
        $report = $janitor->scan();
        self::assertSame(1, $report['tasks']['orphan_files']['count']);
        self::assertSame('deadbeef-old.png', $report['tasks']['orphan_files']['items'][0]['file']);
        self::assertSame(2, $janitor->scan(0)['tasks']['orphan_files']['count'], 'min age 0 also reports fresh files');

        self::assertSame(['orphan_files' => 1], $janitor->clean(['orphan_files']));
        self::assertFileDoesNotExist($uploads . '/deadbeef-old.png');
        self::assertFileExists($uploads . '/' . $row['file_url']);
        self::assertFileExists($this->dir . '/storage/trash/' . date('Y-m-d') . '/uploads/employees/deadbeef-old.png');

        // Expired trash days are deleted; "trash_all" empties it.
        self::assertSame(0, $janitor->clean(['trash'])['trash']);
        self::assertSame(1, $janitor->clean(['trash_all'])['trash_all']);
        self::assertDirectoryDoesNotExist($this->dir . '/storage/trash/' . date('Y-m-d'));
    }

    public function testUploadFoldersThatCannotBeCheckedAreSkipped(): void
    {
        $this->api->resource('broken', 'no_such_table')->setFieldUpload('file', 'employees');
        $uploads = $this->dir . '/storage/uploads/employees';
        mkdir($uploads, 0777, true);
        file_put_contents($uploads . '/deadbeef-old.png', 'x');
        touch($uploads . '/deadbeef-old.png', time() - 3 * 86400);

        $report = $this->api->janitor()->scan();
        self::assertSame(0, $report['tasks']['orphan_files']['count']);
        self::assertStringContainsString('not found', $report['skipped'][0]['reason']);
    }

    public function testBucketHousekeeping(): void
    {
        $this->upload('docs', 'a', 'keep.txt');
        mkdir($this->bucketDir('docs') . '/empty/inner', 0777, true);
        mkdir($this->dir . '/storage/buckets/deleted_bucket/x', 0777, true);
        file_put_contents($this->dir . '/storage/buckets/deleted_bucket/x/file.txt', 'left behind');
        $this->api->store()->putObject('docs', 'gone.txt', ['size' => 1, 'etag' => 'x', 'content_type' => 'text/plain']);
        file_put_contents($this->bucketDir('docs') . '/manual.txt', 'copied by hand');
        @mkdir($this->dir . '/storage/buckets/.tmp', 0700, true);
        file_put_contents($this->dir . '/storage/buckets/.tmp/abandoned', 'x');
        touch($this->dir . '/storage/buckets/.tmp/abandoned', time() - 7200);

        $tasks = $this->api->janitor()->scan()['tasks'];
        self::assertSame(1, $tasks['orphan_buckets']['count']);
        self::assertSame(1, $tasks['stale_index']['count']);
        self::assertSame(1, $tasks['untracked_objects']['count']);
        self::assertSame(1, $tasks['tmp_files']['count']);
        self::assertSame(['empty/inner/', 'empty/'], array_column($tasks['empty_folders']['items'], 'path'));

        $done = $this->api->janitor()->clean(['orphan_buckets', 'stale_index', 'untracked_objects', 'tmp_files', 'empty_folders']);
        self::assertSame(['orphan_buckets' => 1, 'stale_index' => 1, 'untracked_objects' => 1, 'tmp_files' => 1, 'empty_folders' => 2], $done);
        self::assertDirectoryDoesNotExist($this->dir . '/storage/buckets/deleted_bucket');
        self::assertDirectoryDoesNotExist($this->bucketDir('docs') . '/empty');
        self::assertNotNull($this->api->store()->object('docs', 'manual.txt'));
        self::assertFileExists($this->bucketDir('docs') . '/a/keep.txt');
    }

    public function testRoutineRunsOncePerDay(): void
    {
        self::assertNotNull($this->api->janitor()->routine());
        self::assertNull($this->api->janitor()->routine());
        $this->api->store()->clearCache();
        $this->api->store()->saveSettings(['storage_auto_cleanup' => false]);
        self::assertNull($this->api->janitor()->routine());
    }

    public function testRowsChangedOutsideTheApiReleaseTheirFiles(): void
    {
        [, $row] = $this->call('POST', 'employees/1056/upload/file_url', null, [], ['file' => Fixture::uploadedFile($this->dir, 'cv.pdf', '%PDF-1.4 test')]);
        $file = $this->dir . '/storage/uploads/employees/' . $row['file_url'];
        $this->db()->exec("UPDATE employees SET file_url = '" . $row['file_url'] . "' WHERE employeeNumber = 1102"); // shared by two rows

        $janitor = $this->api->janitor();
        self::assertSame(0, $janitor->releaseRowFiles('default', 'employees', ['file_url' => $row['file_url']]), 'still used by 1056 and 1102');
        $this->db()->exec('UPDATE employees SET file_url = NULL');
        self::assertSame(1, $janitor->releaseRowFiles('default', 'employees', ['file_url' => $row['file_url']], ['file_url' => null]));
        self::assertFileDoesNotExist($file);
    }
}
