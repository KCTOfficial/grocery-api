<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Feature;

use GroceryRestable\GroceryRestable;
use GroceryRestable\Tests\Support\Fixture;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs only when GROCERY_RESTABLE_TEST_MYSQL is set, e.g.
 *   GROCERY_RESTABLE_TEST_MYSQL=mysql://root:@127.0.0.1:3306/grocery_restable_test vendor/bin/phpunit --filter MySqlTest
 * The database must exist; the test creates and drops its own gr_test_* tables.
 */
final class MySqlTest extends TestCase
{
    private string $dir;
    private GroceryRestable $api;
    private PDO $pdo;

    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        $url = (string) getenv('GROCERY_RESTABLE_TEST_MYSQL');
        if ($url === '') {
            self::markTestSkipped('Set GROCERY_RESTABLE_TEST_MYSQL to run the MySQL integration test.');
        }
        $parts = parse_url($url);
        $config = [
            'driver' => 'mysql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? 3306,
            'database' => ltrim($parts['path'] ?? '', '/'),
            'username' => urldecode($parts['user'] ?? 'root'),
            'password' => urldecode($parts['pass'] ?? ''),
        ];

        $this->pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']), $config['username'], $config['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->dropTables();
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE gr_test_teams (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL UNIQUE, level ENUM('junior','senior') NOT NULL DEFAULT 'junior', active TINYINT(1) NOT NULL DEFAULT 1, budget DECIMAL(12,2) NULL, meta JSON NULL) ENGINE=InnoDB;
            CREATE TABLE gr_test_members (id INT AUTO_INCREMENT PRIMARY KEY, team_id INT NOT NULL, name VARCHAR(50) NOT NULL, joined_at DATETIME NULL,
                CONSTRAINT gr_test_fk_team FOREIGN KEY (team_id) REFERENCES gr_test_teams(id)) ENGINE=InnoDB;
            INSERT INTO gr_test_teams (name, level, budget, meta) VALUES ('Core', 'senior', 1000.50, '{"x": 1}'), ('Web', 'junior', NULL, NULL);
            INSERT INTO gr_test_members (team_id, name) VALUES (1, 'Ana'), (1, 'Budi'), (2, 'Citra');
            SQL);

        $this->dir = Fixture::directory();
        $this->api = GroceryRestable::create(['storagePath' => $this->dir, 'encryptionKey' => 'base64:' . base64_encode(str_repeat('m', 32))]);
        $this->config = $config;
        $this->api->store()->saveSettings(['setup_completed' => true, 'auth_mode' => 'none', 'auto_expose' => true, 'schema_cache_ttl' => 0]);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropTables();
        }
        if (isset($this->dir)) {
            unset($this->api);
            gc_collect_cycles();
            Fixture::remove($this->dir);
        }
    }

    private function dropTables(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS gr_test_members; DROP TABLE IF EXISTS gr_test_teams;');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function clients(): iterable
    {
        yield 'PDO' => ['pdo'];
        yield 'MySQLi' => ['mysqli'];
    }

    #[DataProvider('clients')]
    public function testMySqlEndToEnd(string $client): void
    {
        $this->api->store()->saveConnection('default', ['client' => $client] + $this->config);
        self::assertSame($client === 'mysqli' ? \GroceryRestable\Database\MysqliConnection::class : \GroceryRestable\Database\PdoConnection::class, $this->api->connections()->get()::class);

        $table = $this->api->schema()->find('gr_test_teams');
        self::assertSame(['junior', 'senior'], $table->columns['level']->enumValues);
        self::assertSame('boolean', $table->columns['active']->type);
        self::assertSame('json', $table->columns['meta']->type);

        [$status, $rows] = Fixture::call($this->api, 'GET', 'gr_test_teams?select=name,active,budget,meta,gr_test_members(name)&order=id');
        self::assertSame(200, $status);
        self::assertSame(['name' => 'Core', 'active' => true, 'budget' => 1000.5, 'meta' => ['x' => 1], 'gr_test_members' => [['name' => 'Ana'], ['name' => 'Budi']]], $rows[0]);

        [, $rows] = Fixture::call($this->api, 'GET', 'gr_test_members?select=name,gr_test_teams(name)&gr_test_teams.level=eq.junior');
        self::assertSame([['name' => 'Citra', 'gr_test_teams' => ['name' => 'Web']]], $rows);

        [$status, $created] = Fixture::call($this->api, 'POST', 'gr_test_teams', ['name' => 'Data', 'level' => 'senior', 'active' => false, 'meta' => ['a' => [1, 2]]]);
        self::assertSame(201, $status);
        self::assertFalse($created['active']);
        self::assertSame(['a' => [1, 2]], $created['meta']);

        self::assertSame(422, Fixture::call($this->api, 'POST', 'gr_test_teams', ['name' => 'X', 'level' => 'expert'])[0]);
        self::assertSame(409, Fixture::call($this->api, 'POST', 'gr_test_teams', ['name' => 'Core'])[0], 'Unique index violation');
        self::assertSame(409, Fixture::call($this->api, 'DELETE', 'gr_test_teams/1')[0], 'Foreign key violation');

        [, $updated] = Fixture::call($this->api, 'PATCH', 'gr_test_members/3?select=joined_at', ['joined_at' => '2025-01-02T03:04:05Z']);
        self::assertSame('2025-01-02 03:04:05', $updated['joined_at']);

        [, $found] = Fixture::call($this->api, 'GET', 'gr_test_members?select=name&name=ilike.*BU*');
        self::assertSame([['name' => 'Budi']], $found);

        self::assertSame(204, Fixture::call($this->api, 'DELETE', 'gr_test_teams/' . $created['id'])[0]);

        // Schema changes are picked up on the next request (fingerprint), without clearing the cache.
        $this->api->store()->saveSettings(['schema_cache_ttl' => 3600]);
        $this->pdo->exec('ALTER TABLE gr_test_members ADD nickname VARCHAR(20) NULL');
        $next = Fixture::reopen($this->api);
        self::assertSame(200, Fixture::call($next, 'GET', 'gr_test_members?select=nickname&limit=1')[0]);
    }
}
