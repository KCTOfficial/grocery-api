<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Unit;

use GroceryRestable\Admin\Installer;
use GroceryRestable\Database\ConnectionFactory;
use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class ConnectionFactoryTest extends TestCase
{
    public function testCatalogListsEnginesAndDrivers(): void
    {
        $catalog = ConnectionFactory::catalog();

        self::assertSame(['pdo', 'mysqli'], array_keys($catalog['mysql']['clients']));
        self::assertSame(['pdo'], array_keys($catalog['pgsql']['clients']));
        self::assertTrue($catalog['sqlite']['clients']['pdo']['installed']);
        self::assertFalse($catalog['sqlsrv']['supported']);
    }

    public function testInvalidEngineDriverCombinationsAreRejected(): void
    {
        foreach ([['driver' => 'pgsql', 'client' => 'mysqli'], ['driver' => 'sqlsrv'], ['driver' => 'oracle'], ['driver' => 'sqlite', 'client' => 'odbc']] as $config) {
            try {
                ConnectionFactory::client($config);
                self::fail('Should be rejected: ' . json_encode($config));
            } catch (ConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('pdo', ConnectionFactory::client(['driver' => 'sqlite']));
    }

    public function testInstallerNormalizesConnectionForms(): void
    {
        $config = Installer::connectionConfig(['driver' => 'mysql', 'client' => 'mysqli', 'database' => 'app', 'port' => '']);
        self::assertSame(['driver' => 'mysql', 'client' => 'mysqli', 'database' => 'app', 'host' => '127.0.0.1', 'port' => 3306, 'username' => '', 'password' => '', 'charset' => 'utf8mb4'], $config);

        $this->expectException(ValidationException::class);
        Installer::connectionConfig(['driver' => 'pgsql', 'client' => 'mysqli', 'database' => 'app']);
    }
}
