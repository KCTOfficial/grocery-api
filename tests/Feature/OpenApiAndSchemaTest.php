<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Feature;

use GroceryRestable\Resource\Relation;
use GroceryRestable\Tests\Support\ApiTestCase;

final class OpenApiAndSchemaTest extends ApiTestCase
{
    protected function settings(): array
    {
        return ['auth_mode' => 'none', 'auto_expose' => true];
    }

    public function testSqliteIntrospection(): void
    {
        $table = $this->api->schema()->find('employees');

        self::assertSame(['employeeNumber'], $table->primaryKey);
        self::assertTrue($table->columns['employeeNumber']->autoIncrement);
        self::assertSame('boolean', $table->columns['active']->type);
        self::assertSame('number', $table->columns['salary']->type);
        self::assertSame(50, $table->columns['lastName']->maxLength);
        self::assertSame(['officeCode'], $this->api->schema()->find('offices')->primaryKey);
        self::assertSame(['film_id', 'actor_id'], $this->api->schema()->find('film_actor')->primaryKey);
        self::assertCount(2, $table->foreignKeys);
    }

    public function testAutoRelationsFromForeignKeys(): void
    {
        $employees = $this->api->registry()->find('employees');
        $film = $this->api->registry()->find('film');

        self::assertSame(Relation::BELONGS_TO, $employees->getRelation('offices')->type);
        self::assertSame('city', $employees->getRelation('offices')->titleField);
        self::assertSame(Relation::HAS_MANY, $employees->getRelation('customers')->type);
        self::assertSame(Relation::MANY_TO_MANY, $film->getRelation('actor')->type);
        self::assertSame('film_actor', $film->getRelation('actor')->junctionTable);
    }

    public function testSchemaChangesAreDetectedWithoutWaitingForTheCache(): void
    {
        self::assertSame(404, $this->call('GET', 'notes')[0]);

        $this->db()->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
        $this->api = \GroceryRestable\Tests\Support\Fixture::reopen($this->api);    // next request
        self::assertSame(200, $this->call('GET', 'notes')[0], 'A new table is exposed on the next request');

        $this->db()->exec('ALTER TABLE notes ADD COLUMN title VARCHAR(20)');
        $this->api = \GroceryRestable\Tests\Support\Fixture::reopen($this->api);
        self::assertSame(201, $this->call('POST', 'notes', ['body' => 'b', 'title' => 't'])[0], 'A new column is writable on the next request');
    }

    public function testExcludedTablesAreNotExposed(): void
    {
        $this->api->store()->saveSettings(['auto_exclude_tables' => ['secrets']]);
        $this->api->registry()->reload();

        self::assertSame(404, $this->call('GET', 'secrets')[0]);
    }

    public function testOpenApiDocument(): void
    {
        [$status, $spec] = $this->call('GET', 'openapi.json');

        self::assertSame(200, $status);
        self::assertSame('3.1.0', $spec['openapi']);
        self::assertArrayHasKey('/employees', $spec['paths']);
        self::assertArrayHasKey('/employees/{id}', $spec['paths']);
        self::assertSame(['integer'], (array) $spec['components']['schemas']['Employees']['properties']['employeeNumber']['type']);
        self::assertContains('lastName', $spec['components']['schemas']['EmployeesInput']['required']);

        $this->api->store()->saveSettings(['expose_openapi' => false, 'auth_mode' => 'api_key']);
        self::assertSame(404, $this->call('GET', 'openapi.json')[0]);
    }
}
