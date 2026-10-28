<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Feature;

use GroceryRestable\Tests\Support\ApiTestCase;
use GroceryRestable\Tests\Support\Fixture;

final class UploadStorageTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->api->resource('employees')->setFieldUpload('file_url', 'employees', 'png|pdf');
        $this->api->bucket('avatars', ['public' => true, 'allowedTypes' => 'png|jpg']);
        $this->api->bucket('private', ['permissions' => ['authenticated' => ['select', 'insert']]]);
    }

    private function png(string $name = 'Me Photo.png'): \GroceryRestable\Http\NativeUploadedFile
    {
        return Fixture::uploadedFile($this->dir, $name, Fixture::png());
    }

    public function testFieldUploadLifecycle(): void
    {
        [$status, $row] = $this->call('POST', 'employees/1002/upload/file_url', null, [], ['file' => $this->png()]);
        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-me-photo\.png$/', $row['file_url']);
        $stored = $this->dir . '/storage/uploads/employees/' . $row['file_url'];
        self::assertFileExists($stored);

        [$status, , $download] = $this->call('GET', 'employees/1002/file/file_url');
        self::assertSame(200, $status);
        self::assertSame('image/png', $download->getHeader('Content-Type'));
        self::assertStringStartsWith('inline', (string) $download->getHeader('Content-Disposition'));

        // Replacing the file removes the previous one.
        [, $row2] = $this->call('POST', 'employees/1002/upload/file_url', null, [], ['file' => $this->png('second.png')]);
        self::assertFileDoesNotExist($stored);

        [$status, $row3] = $this->call('DELETE', 'employees/1002/upload/file_url');
        self::assertSame(200, $status);
        self::assertNull($row3['file_url']);
        self::assertFileDoesNotExist($this->dir . '/storage/uploads/employees/' . $row2['file_url']);
    }

    public function testMultipartCreateWithFile(): void
    {
        [$status, $row] = $this->call('POST', 'employees', null, [], ['file_url' => $this->png()], [
            'lastName' => 'Doe', 'firstName' => 'Jane', 'email' => 'jane@example.com', 'officeCode' => '1', 'salary' => '',
        ]);

        self::assertSame(201, $status);
        self::assertNull($row['salary'], 'Empty form values become NULL for non text columns');
        self::assertStringEndsWith('-me-photo.png', $row['file_url']);
    }

    public function testDangerousUploadsAreRejected(): void
    {
        $cases = [
            ['shell.php', '<?php echo 1;'],
            ['shell.php.png', Fixture::png()],
            ['fake.png', 'plain text'],
            ['page.html', '<html></html>'],
            ['doc.exe', 'MZ'],
        ];
        foreach ($cases as [$name, $content]) {
            [$status] = $this->call('POST', 'employees/1002/upload/file_url', null, [], ['file' => Fixture::uploadedFile($this->dir, $name, $content)]);
            self::assertSame(422, $status, $name . ' must be rejected');
        }
    }

    public function testStoredFileNamesCannotEscapeTheDirectory(): void
    {
        $this->db()->exec("UPDATE employees SET file_url = '../../grocery-restable.sqlite' WHERE employeeNumber = 1002");
        self::assertSame(404, $this->call('GET', 'employees/1002/file/file_url')[0]);
        self::assertSame(422, $this->call('PATCH', 'employees/1002', ['file_url' => 'other.png'])[0], 'Upload columns cannot be set from JSON');
    }

    public function testBucketsUploadListDownloadDelete(): void
    {
        [$status, $object] = $this->call('POST', 'storage/avatars', null, [], ['file' => $this->png()], ['path' => 'users/1']);
        self::assertSame(201, $status);
        self::assertSame('users/1/me-photo.png', $object['path']);

        self::assertSame(409, $this->call('POST', 'storage/avatars', null, [], ['file' => $this->png()], ['path' => 'users/1'])[0]);
        self::assertSame(200, $this->call('POST', 'storage/avatars', null, [], ['file' => $this->png()], ['path' => 'users/1', 'upsert' => '1'])[0], 'replacing answers 200');

        [, $list] = $this->call('GET', 'storage/avatars?prefix=users/1/');
        self::assertSame(['me-photo.png'], array_column($list['files'], 'name'));
        [, $root] = $this->call('GET', 'storage/avatars');
        self::assertSame(['users/'], $root['prefixes']);

        self::assertSame(200, $this->call('GET', 'storage/avatars/users/1/me-photo.png')[0]);
        self::assertSame(422, $this->call('GET', 'storage/avatars/../../grocery-restable.sqlite')[0]);
        self::assertSame(422, $this->call('POST', 'storage/avatars', null, [], ['file' => Fixture::uploadedFile($this->dir, 'a.pdf', '%PDF-1.4')])[0]);
        self::assertSame(204, $this->call('DELETE', 'storage/avatars/users/1/me-photo.png')[0]);
        self::assertSame(404, $this->call('GET', 'storage/avatars/users/1/me-photo.png')[0]);
    }

    public function testBucketPermissions(): void
    {
        $this->api->store()->saveSettings(['auth_mode' => 'api_key']);
        $this->call('POST', 'storage/avatars', null, ['apikey' => $this->api->store()->createApiKey('svc', 'service_role')], ['file' => $this->png()]);
        $key = $this->api->store()->createApiKey('user', 'authenticated');

        self::assertSame(200, $this->call('GET', 'storage/avatars/me-photo.png')[0], 'Public buckets are readable anonymously');
        self::assertSame(401, $this->call('POST', 'storage/avatars', null, [], ['file' => $this->png()])[0]);
        self::assertSame(401, $this->call('GET', 'storage/private')[0]);
        self::assertSame(201, $this->call('POST', 'storage/private', null, ['apikey' => $key], ['file' => $this->png()])[0]);
        self::assertSame(403, $this->call('DELETE', 'storage/private/me-photo.png', null, ['apikey' => $key])[0]);
        self::assertSame(['avatars'], array_column($this->call('GET', 'storage')[1], 'name'));
    }
}
