<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Feature;

use GroceryRestable\Tests\Support\ApiTestCase;

final class WriteTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->api->resource('employees')
            ->setRelation('officeCode', 'offices', 'city')
            ->requiredFields('lastName', 'email')
            ->uniqueFields('email')
            ->setRules('email', 'required|valid_email')
            ->fieldType('password', 'password')
            ->fieldType('jobTitle', 'enum', ['Sales Rep', 'Manager', 'Intern']);
        $this->api->resource('film')
            ->setRelationNtoN('actors', 'film_actor', 'actor', 'film_id', 'actor_id', 'fullname', 'priority')
            ->setClone();
        $this->api->resource('offices');
    }

    private function newEmployee(array $overrides = []): array
    {
        return $overrides + ['lastName' => 'Doe', 'firstName' => 'John', 'email' => 'john@example.com', 'officeCode' => '7'];
    }

    public function testCreateReturnsRepresentationAndLocation(): void
    {
        [$status, $row, $response] = $this->call('POST', 'employees?select=employeeNumber,lastName,offices(city)', $this->newEmployee());

        self::assertSame(201, $status);
        self::assertSame('Doe', $row['lastName']);
        self::assertSame(['city' => 'London'], $row['offices']);
        self::assertSame('http://localhost/api/employees/' . $row['employeeNumber'], $response->getHeader('Location'));
    }

    public function testValidationErrors(): void
    {
        [$status, $body] = $this->call('POST', 'employees', ['lastName' => '', 'email' => 'nope', 'unknown' => 1, 'jobTitle' => 'CEO', 'salary' => 'abc']);

        self::assertSame(422, $status);
        $fields = $body['error']['details']['fields'];
        self::assertSame('Unknown field.', $fields['unknown']);
        self::assertArrayHasKey('lastName', $fields);
        self::assertArrayHasKey('email', $fields);
        self::assertArrayHasKey('jobTitle', $fields);
        self::assertArrayHasKey('salary', $fields);
        self::assertArrayHasKey('firstName', $fields, 'NOT NULL columns without default are required');
    }

    public function testUniqueAndPrimaryKeyRules(): void
    {
        [, $row] = $this->call('POST', 'employees', $this->newEmployee());
        [$status, $body] = $this->call('POST', 'employees', $this->newEmployee());
        self::assertSame(422, $status);
        self::assertArrayHasKey('email', $body['error']['details']['fields']);

        // Sending the same id is fine; changing it is not.
        self::assertSame(200, $this->call('PATCH', 'employees/' . $row['employeeNumber'], ['employeeNumber' => $row['employeeNumber'], 'lastName' => 'Ok'])[0]);
        self::assertSame(422, $this->call('PATCH', 'employees/' . $row['employeeNumber'], ['employeeNumber' => 1])[0]);
    }

    public function testPartialUpdateAndPasswordHashing(): void
    {
        [, $row] = $this->call('POST', 'employees', $this->newEmployee(['password' => 'secret123']));
        $id = $row['employeeNumber'];
        self::assertArrayNotHasKey('password', $row);

        [$status, $updated] = $this->call('PATCH', "employees/$id?select=salary,hired_at", ['salary' => '1234.56', 'hired_at' => '2024-05-01T10:00:00Z', 'password' => '']);
        self::assertSame(200, $status);
        self::assertSame(['salary' => 1234.56, 'hired_at' => '2024-05-01 10:00:00'], $updated);

        $hash = $this->db()->query("SELECT password FROM employees WHERE employeeNumber = $id")->fetchColumn();
        self::assertTrue(password_verify('secret123', $hash), 'An empty password on update keeps the current hash');

        // PUT requires every required field.
        self::assertSame(422, $this->call('PUT', "employees/$id", ['lastName' => 'X'])[0]);
    }

    public function testDelete(): void
    {
        [, $row] = $this->call('POST', 'employees', $this->newEmployee());
        self::assertSame(204, $this->call('DELETE', 'employees/' . $row['employeeNumber'])[0]);
        self::assertSame(404, $this->call('GET', 'employees/' . $row['employeeNumber'])[0]);
    }

    public function testForeignKeyViolationIsAConflict(): void
    {
        self::assertSame(409, $this->call('DELETE', 'offices/1')[0]);
    }

    public function testManyToManyWriteKeepsOrder(): void
    {
        [$status, $film] = $this->call('POST', 'film?select=film_id,actors(actor_id)', ['title' => 'New', 'actors' => [3, 1]]);
        self::assertSame(201, $status);
        self::assertSame([3, 1], array_column($film['actors'], 'actor_id'));

        [, $film] = $this->call('PATCH', 'film/' . $film['film_id'] . '?select=actors(actor_id)', ['actors' => [1, 2]]);
        self::assertSame([1, 2], array_column($film['actors'], 'actor_id'));
    }

    public function testCloneCopiesRelations(): void
    {
        [$status, $copy] = $this->call('POST', 'film/1/clone?select=film_id,title,actors(actor_id)', ['title' => 'Copy']);

        self::assertSame(201, $status);
        self::assertNotSame(1, $copy['film_id']);
        self::assertSame([1, 2], array_column($copy['actors'], 'actor_id'));
        self::assertSame(405, $this->call('POST', 'employees/1002/clone')[0]);
    }

    public function testBulkInsertUpdateDeleteAndUpsert(): void
    {
        [$status, $rows] = $this->call('POST', 'film', [['title' => 'A'], ['title' => 'B']]);
        self::assertSame(201, $status);
        self::assertCount(2, $rows);

        [, $updated] = $this->call('PATCH', 'film?title=in.(A,B)&select=rating', ['rating' => 'R']);
        self::assertSame([['rating' => 'R'], ['rating' => 'R']], $updated);

        self::assertSame(400, $this->call('DELETE', 'film')[0], 'Bulk delete without filters is refused');

        [, $upserted] = $this->call('POST', 'film?select=film_id,title', [['film_id' => 1, 'title' => 'Renamed']], ['Prefer' => 'resolution=merge-duplicates']);
        self::assertSame([['film_id' => 1, 'title' => 'Renamed']], $upserted);

        [, $deleted] = $this->call('DELETE', 'film?rating=eq.R');
        self::assertCount(2, $deleted['deleted']);
    }

    public function testCallbacksAndPreferMinimal(): void
    {
        $events = [];
        $this->api->resource('offices')
            ->callbackBeforeInsert(function (array $data) use (&$events): array {
                $events[] = 'before';

                return ['country' => strtoupper($data['country'])] + $data;
            })
            ->callbackAfterInsert(function (array $data, $id) use (&$events): void {
                $events[] = 'after:' . $id;
            })
            ->callbackBeforeDelete(static fn (): bool => false);

        [$status, $body, $response] = $this->call('POST', 'offices', ['officeCode' => '9', 'city' => 'Jakarta', 'country' => 'id'], ['Prefer' => 'return=minimal']);
        self::assertSame(201, $status);
        self::assertNull($body);
        self::assertStringEndsWith('/offices/9', (string) $response->getHeader('Location'));
        self::assertSame(['before', 'after:9'], $events);
        self::assertSame('ID', $this->db()->query("SELECT country FROM offices WHERE officeCode = '9'")->fetchColumn());

        [$status, $body] = $this->call('DELETE', 'offices/9');
        self::assertSame(400, $status);
        self::assertSame('cancelled', $body['error']['code']);
    }

    public function testDisabledOperations(): void
    {
        $this->api->resource('offices')->unsetAdd()->unsetDelete();

        self::assertSame(405, $this->call('POST', 'offices', ['officeCode' => '9', 'city' => 'x', 'country' => 'y'])[0]);
        self::assertSame(405, $this->call('DELETE', 'offices/1')[0]);
        self::assertSame(200, $this->call('GET', 'offices/1')[0]);
    }

    public function testActionsAndRpc(): void
    {
        $this->api->resource('employees')->addAction('promote', function ($id, array $payload, $ctx) {
            $ctx->db->execute('UPDATE employees SET jobTitle = ? WHERE employeeNumber = ?', [$payload['title'], $id]);

            return ['promoted' => $id];
        });
        $this->api->rpc('ping', static fn (array $params): array => ['pong' => $params['x'] ?? null], ['methods' => ['GET'], 'roles' => ['*']]);

        [$status, $body] = $this->call('POST', 'employees/1337/actions/promote', ['title' => 'Manager']);
        self::assertSame([200, ['promoted' => 1337]], [$status, $body]);
        self::assertSame('Manager', $this->db()->query('SELECT jobTitle FROM employees WHERE employeeNumber = 1337')->fetchColumn());

        self::assertSame([200, ['pong' => '1']], array_slice($this->call('GET', 'rpc/ping?x=1'), 0, 2));
        self::assertSame(405, $this->call('POST', 'rpc/ping')[0]);
    }
}
