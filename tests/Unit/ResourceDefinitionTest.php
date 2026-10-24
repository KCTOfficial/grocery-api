<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Unit;

use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Resource\CodeExporter;
use GroceryRestable\Resource\Conditions;
use GroceryRestable\Resource\Relation;
use GroceryRestable\Resource\Resource;
use PHPUnit\Framework\TestCase;

final class ResourceDefinitionTest extends TestCase
{
    public function testGroceryCrudSnakeCaseAliases(): void
    {
        $resource = new Resource('films', 'film');
        $resource->set_subject('Film')
            ->set_relation('language_id', 'language', 'name')
            ->set_relation_n_n('actors', 'film_actor', 'actor', 'film_id', 'actor_id', 'fullname', 'priority')
            ->unset_delete()
            ->required_fields('title')
            ->display_as('title', 'Title')
            ->callback_before_insert(fn (array $d): array => $d);

        self::assertSame('Film', $resource->getSubject());
        self::assertSame(Relation::BELONGS_TO, $resource->getRelation('language')->type);
        self::assertSame(Relation::MANY_TO_MANY, $resource->getRelation('actors')->type);
        self::assertFalse($resource->isEnabled('delete'));
        self::assertSame(['title'], $resource->getRequiredFields());
        self::assertCount(1, $resource->getCallbacks('before_insert'));
    }

    public function testUnknownMethodThrows(): void
    {
        $this->expectException(\BadMethodCallException::class);
        (new Resource('a', 'a'))->set_theme('flexigrid');
    }

    public function testConditionsUseCodeIgniterSemantics(): void
    {
        $c = (new Conditions())
            ->where('status', 'active')
            ->where('price >=', 10)
            ->where(['a' => null, 'b !=' => null])
            ->where('id', [1, 2])
            ->where('qty', '<', 5)
            ->like('name', '50%', 'after')
            ->orWhere('vip', 1);

        self::assertSame(
            [['=', 'active'], ['>=', 10], ['is null', null], ['is not null', null], ['in', [1, 2]], ['<', 5], ['like', '50\%%'], ['=', 1]],
            array_map(static fn (array $i): array => [$i['operator'], $i['value']], $c->items()),
        );
        self::assertSame('or', $c->items()[7]['boolean']);
    }

    public function testConditionsRejectRawSql(): void
    {
        $this->expectException(ConfigurationException::class);
        (new Conditions())->where('1=1 OR 1', 1);
    }

    public function testRoundTripThroughArray(): void
    {
        $resource = (new Resource('staff', 'employees'))
            ->setSubject('Employee', 'Employees')
            ->columns('lastName', 'firstName', 'offices')
            ->setRelation('officeCode', 'offices', '{city} ({country})', ['active' => 1], 'city desc')
            ->setRelationHasMany('customers', 'customers', 'salesRepEmployeeNumber')
            ->setFieldUpload('photo', 'staff', 'jpg|png', '2MB')
            ->fieldType('password', 'password')
            ->fieldType('status', 'enum', ['a', 'b'])
            ->setRules('email', 'E-mail', 'required|valid_email')
            ->where('deleted', 0)
            ->orderBy('lastName')
            ->limit(50)
            ->setPermissions('anon', ['select'])
            ->setClone();

        $copy = Resource::fromArray($resource->toArray());

        self::assertSame($resource->toArray(), $copy->toArray());
        self::assertSame(['city', 'desc'], $copy->getRelation('offices')->orderBy);
        self::assertSame('E-mail', $copy->getRules()['email']['label']);
    }

    public function testRawWhereIsNotAcceptedFromStoredDefinitions(): void
    {
        $resource = (new Resource('a', 'a'))->setRelation('b_id', 'b', 'name', "status = 'x'");
        self::assertSame("status = 'x'", $resource->getRelation('b')->rawWhere);

        $copy = Resource::fromArray($resource->toArray());
        self::assertNull($copy->getRelation('b')->rawWhere);
    }

    public function testCodeExporterProducesRunnableCode(): void
    {
        $resource = (new Resource('staff', 'employees'))
            ->setSubject('Employee')
            ->setRelation('officeCode', 'offices', 'city')
            ->requiredFields('lastName')
            ->unsetDelete();

        $code = (new CodeExporter())->export($resource);
        self::assertStringContainsString("\$api->resource('staff', 'employees')", $code);
        self::assertStringContainsString("->setRelation('officeCode', 'offices', 'city')", $code);
        self::assertStringContainsString('->unsetDelete()', $code);

        // The exported code rebuilds an identical definition.
        $api = new class () {
            public ?Resource $resource = null;

            public function resource(string $name, ?string $table = null): Resource
            {
                return $this->resource = new Resource($name, $table ?? $name);
            }
        };
        eval('$api = $api; ' . $code);
        self::assertSame($resource->toArray(), $api->resource->toArray());
    }
}
