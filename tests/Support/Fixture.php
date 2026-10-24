<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Support;

use GroceryRestable\GroceryRestable;
use GroceryRestable\Http\NativeUploadedFile;
use GroceryRestable\Http\Request;
use GroceryRestable\Http\Response;
use PDO;

/**
 * Builds an isolated SQLite database (a small classicmodels-like schema)
 * and a Grocery Restable instance using it.
 */
final class Fixture
{
    public static function directory(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'grocery-restable-tests-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        return $dir;
    }

    public static function database(string $dir): string
    {
        $file = $dir . DIRECTORY_SEPARATOR . 'app.sqlite';
        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(<<<'SQL'
            PRAGMA foreign_keys = ON;
            CREATE TABLE offices (
                officeCode VARCHAR(10) PRIMARY KEY,
                city VARCHAR(50) NOT NULL,
                country VARCHAR(50) NOT NULL,
                phone VARCHAR(50)
            );
            CREATE TABLE employees (
                employeeNumber INTEGER PRIMARY KEY,
                lastName VARCHAR(50) NOT NULL,
                firstName VARCHAR(50) NOT NULL,
                email VARCHAR(100) NOT NULL,
                officeCode VARCHAR(10) NOT NULL REFERENCES offices(officeCode),
                reportsTo INTEGER REFERENCES employees(employeeNumber),
                jobTitle VARCHAR(50) NOT NULL DEFAULT 'Sales Rep',
                active BOOLEAN NOT NULL DEFAULT 1,
                salary DECIMAL(10,2),
                file_url VARCHAR(255),
                password VARCHAR(255),
                hired_at DATETIME
            );
            CREATE TABLE customers (
                customerNumber INTEGER PRIMARY KEY,
                customerName VARCHAR(50) NOT NULL,
                salesRepEmployeeNumber INTEGER REFERENCES employees(employeeNumber),
                creditLimit DECIMAL(10,2)
            );
            CREATE TABLE actor (actor_id INTEGER PRIMARY KEY, fullname VARCHAR(100) NOT NULL);
            CREATE TABLE film (
                film_id INTEGER PRIMARY KEY,
                title VARCHAR(255) NOT NULL UNIQUE,
                rating VARCHAR(5) DEFAULT 'G',
                metadata TEXT
            );
            CREATE TABLE film_actor (
                film_id INTEGER NOT NULL REFERENCES film(film_id),
                actor_id INTEGER NOT NULL REFERENCES actor(actor_id),
                priority INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (film_id, actor_id)
            );
            CREATE TABLE secrets (id INTEGER PRIMARY KEY, owner_id VARCHAR(20) NOT NULL, note TEXT NOT NULL);

            INSERT INTO offices VALUES ('1', 'San Francisco', 'USA', '+1 650 219 4782'), ('2', 'Boston', 'USA', '+1 215 837 0825'),
                                       ('4', 'Paris', 'France', '+33 14 723 4404'), ('7', 'London', 'UK', '+44 20 7877 2041');
            INSERT INTO employees (employeeNumber, lastName, firstName, email, officeCode, reportsTo, jobTitle, active, salary) VALUES
                (1002, 'Murphy', 'Diane', 'dmurphy@example.com', '1', NULL, 'President', 1, 9000.50),
                (1056, 'Patterson', 'Mary', 'mpatterso@example.com', '1', 1002, 'VP Sales', 1, 7000),
                (1102, 'Bondur', 'Gerard', 'gbondur@example.com', '4', 1056, 'Sale Manager (EMEA)', 1, 6500),
                (1337, 'Bondur', 'Loui', 'lbondur@example.com', '4', 1102, 'Sales Rep', 0, 3000),
                (1501, 'Bott', 'Larry', 'lbott@example.com', '7', 1102, 'Sales Rep', 1, NULL);
            INSERT INTO customers VALUES (103, 'Atelier graphique', 1337, 21000), (112, 'Signal Gift Stores', 1102, 71800), (114, 'Australian Collectors', 1501, 117300);
            INSERT INTO actor VALUES (1, 'Penelope Guiness'), (2, 'Nick Wahlberg'), (3, 'Ed Chase');
            INSERT INTO film VALUES (1, 'Academy Dinosaur', 'PG', '{"year": 2006}'), (2, 'Ace Goldfinger', 'G', NULL);
            INSERT INTO film_actor VALUES (1, 2, 1), (1, 1, 0), (2, 3, 0);
            INSERT INTO secrets VALUES (1, 'alice', 'alice note'), (2, 'bob', 'bob note');
            SQL);

        return $file;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function api(string $dir, array $settings = ['auth_mode' => 'none']): GroceryRestable
    {
        $api = GroceryRestable::create([
            'storagePath' => $dir . DIRECTORY_SEPARATOR . 'storage',
            'encryptionKey' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'apiUrl' => '/api',
            'adminUrl' => '/admin',
            'debug' => true,
            'logger' => static fn (): null => null,
        ]);
        $api->store()->saveConnection('default', ['driver' => 'sqlite', 'database' => self::database($dir)]);
        $api->store()->saveSettings(['setup_completed' => true] + $settings);

        return $api;
    }

    /**
     * A new engine instance on the same storage, i.e. what the next HTTP request sees.
     */
    public static function reopen(GroceryRestable $api): GroceryRestable
    {
        return GroceryRestable::create(array_diff_key($api->config()->all(), array_flip(['configDatabase', 'uploadPath', 'bucketPath'])) + [
            'configDatabase' => $api->config()->configDatabase(),
        ]);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, NativeUploadedFile> $files
     */
    public static function request(string $method, string $uri, mixed $body = null, array $headers = [], array $files = [], array $form = []): Request
    {
        [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
        $raw = '';
        if ($body !== null) {
            $raw = json_encode($body, JSON_THROW_ON_ERROR);
            $headers += ['Content-Type' => 'application/json'];
        } elseif ($files !== [] || $form !== []) {
            $headers += ['Content-Type' => 'multipart/form-data; boundary=x'];
        }

        return new Request($method, $path, $query, $headers, $raw, $form, $files, '127.0.0.1', 'http://localhost/api');
    }

    /**
     * @return array{0: int, 1: mixed, 2: Response}
     */
    public static function call(GroceryRestable $api, string $method, string $uri, mixed $body = null, array $headers = [], array $files = [], array $form = []): array
    {
        $response = $api->handle(self::request($method, $uri, $body, $headers, $files, $form));

        return [$response->getStatus(), $response->decodedJson(), $response];
    }

    public static function uploadedFile(string $dir, string $name, string $content): NativeUploadedFile
    {
        $tmp = $dir . DIRECTORY_SEPARATOR . 'upload-' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $content);

        return new NativeUploadedFile($tmp, $name, 'application/octet-stream', strlen($content), UPLOAD_ERR_OK, false);
    }

    public static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    }

    public static function remove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
