<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Feature;

use GroceryRestable\Tests\Support\ApiTestCase;

final class ReadTest extends ApiTestCase
{
    protected function settings(): array
    {
        return ['auth_mode' => 'none', 'auto_expose' => true];
    }

    public function testIndexListsResources(): void
    {
        [$status, $body] = $this->call('GET', '');

        self::assertSame(200, $status);
        self::assertContains('employees', array_column($body['resources'], 'name'));
    }

    public function testFiltersOrderAndProjection(): void
    {
        [$status, $rows] = $this->call('GET', 'employees?select=employeeNumber,name:lastName&officeCode=eq.4&order=employeeNumber.desc');

        self::assertSame(200, $status);
        self::assertSame([['employeeNumber' => 1337, 'name' => 'Bondur'], ['employeeNumber' => 1102, 'name' => 'Bondur']], $rows);
    }

    public function testTypesAreNativeJson(): void
    {
        [, $row] = $this->call('GET', 'employees/1002');

        self::assertSame(1002, $row['employeeNumber']);
        self::assertTrue($row['active']);
        self::assertSame(9000.5, $row['salary']);
        [, $film] = $this->call('GET', 'film/1');
        self::assertSame(['year' => 2006], json_decode($film['metadata'], true) ?? $film['metadata']);
    }

    public function testEmbeddingAllRelationTypes(): void
    {
        [, $rows] = $this->call('GET', 'employees?select=lastName,offices(city),customers(customerName)&employeeNumber=eq.1102');
        self::assertSame([['lastName' => 'Bondur', 'offices' => ['city' => 'Paris'], 'customers' => [['customerName' => 'Signal Gift Stores']]]], $rows);

        [, $films] = $this->call('GET', 'film?select=title,actor(fullname)&film_id=eq.1');
        self::assertSame(['Penelope Guiness', 'Nick Wahlberg'], array_column($films[0]['actor'], 'fullname'));

        [, $nested] = $this->call('GET', 'offices?select=city,employees(lastName,customers(customerName))&officeCode=eq.7');
        self::assertSame('Australian Collectors', $nested[0]['employees'][0]['customers'][0]['customerName']);
    }

    public function testRelationFiltersAndLogicalGroups(): void
    {
        [, $rows] = $this->call('GET', 'employees?select=lastName&offices.country=eq.France&order=lastName');
        self::assertSame(['Bondur', 'Bondur'], array_column($rows, 'lastName'));

        [, $rows] = $this->call('GET', 'employees?select=employeeNumber&or=(salary.gt.8000,salary.is.null)&order=employeeNumber');
        self::assertSame([1002, 1501], array_column($rows, 'employeeNumber'));

        [, $rows] = $this->call('GET', 'employees?select=employeeNumber&employeeNumber=not.in.(1002,1056,1102)&active=is.true');
        self::assertSame([1501], array_column($rows, 'employeeNumber'));
    }

    public function testPagingCountSearchAndCsv(): void
    {
        [$status, $rows, $response] = $this->call('GET', 'employees?select=employeeNumber&limit=2&offset=1&count=exact');
        self::assertSame(200, $status);
        self::assertCount(2, $rows);
        self::assertSame('1-2/5', $response->getHeader('Content-Range'));
        self::assertSame('5', $response->getHeader('X-Total-Count'));

        [, $page] = $this->call('GET', 'employees?select=employeeNumber&page=2&per_page=2');
        self::assertSame([1102, 1337], array_column($page, 'employeeNumber'));

        [, $found] = $this->call('GET', 'employees?select=lastName&q=bott');
        self::assertSame([['lastName' => 'Bott']], $found);

        [, , $csv] = $this->call('GET', 'offices?select=officeCode,city&format=csv&order=officeCode&limit=2');
        self::assertStringContainsString("officeCode,city\n1,\"San Francisco\"\n2,Boston", $csv->getBody());
        self::assertStringStartsWith('text/csv', (string) $csv->getHeader('Content-Type'));
    }

    public function testEnvelope(): void
    {
        $this->api->store()->saveSettings(['response_envelope' => true]);
        [, $body] = $this->call('GET', 'offices?limit=2');

        self::assertCount(2, $body['data']);
        self::assertSame(4, $body['meta']['total']);
    }

    public function testInvalidRequests(): void
    {
        self::assertSame(400, $this->call('GET', 'employees?select=nope')[0]);
        self::assertSame(400, $this->call('GET', 'employees?nope=eq.1')[0]);
        self::assertSame(400, $this->call('GET', 'employees?employeeNumber=eq.abc')[0]);
        self::assertSame(400, $this->call('GET', 'employees?order=nope')[0]);
        self::assertSame(400, $this->call('GET', 'employees?select=nope(*)')[0]);
        self::assertSame(404, $this->call('GET', 'nothing')[0]);
        self::assertSame(404, $this->call('GET', 'employees/999999')[0]);
        self::assertSame(400, $this->call('GET', 'employees?select=a(b(c(d(e(*)))))')[0]);
    }

    public function testRelationOptions(): void
    {
        $this->api->resource('employees')->setRelation('officeCode', 'offices', '{city} ({country})');
        [, $options] = $this->call('GET', 'employees/_options/officeCode?q=lon');

        self::assertSame([['value' => '7', 'label' => 'London (UK)']], $options);
    }

    public function testCodeResourceColumnsAndCallbacks(): void
    {
        $this->api->resource('staff', 'employees')
            ->columns('lastName', 'offices')
            ->setRelation('officeCode', 'offices', 'city')
            ->callbackColumn('lastName', static fn (string $v): string => strtoupper($v))
            ->where('active', 1)
            ->orderBy('lastName', 'desc');

        [, $rows] = $this->call('GET', 'staff?limit=1');

        // "offices" is auto exposed, so its own visible columns are embedded.
        self::assertSame([['lastName' => 'PATTERSON', 'offices' => ['officeCode' => '1', 'city' => 'San Francisco', 'country' => 'USA', 'phone' => '+1 650 219 4782']]], $rows);
        // The table is not exposed twice by auto expose.
        self::assertSame(404, $this->call('GET', 'employees')[0]);
    }

    public function testSchemaEndpoint(): void
    {
        [$status, $schema] = $this->call('GET', '_schema/employees');

        self::assertSame(200, $status);
        self::assertSame('employeeNumber', $schema['primaryKey']);
        self::assertTrue($schema['fields']['lastName']['required']);
        self::assertSame('belongs_to', $schema['fields']['officeCode']['relation']['type']);
    }
}
