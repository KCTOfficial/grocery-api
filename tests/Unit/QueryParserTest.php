<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Unit;

use GroceryRestable\Exception\ApiException;
use GroceryRestable\Query\Filter;
use GroceryRestable\Query\FilterGroup;
use GroceryRestable\Query\QueryParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryParserTest extends TestCase
{
    private function parse(string $query): \GroceryRestable\Query\QueryOptions
    {
        return (new QueryParser())->parse(QueryParser::pairs($query));
    }

    public function testPairsKeepDotsAndRepeatedKeys(): void
    {
        self::assertSame([['offices.city', 'eq.Paris'], ['a', '1'], ['a', '2']], QueryParser::pairs('offices.city=eq.Paris&a=1&a=2'));
    }

    public function testSelectWithAliasesAndNestedEmbeds(): void
    {
        $node = (new QueryParser())->parseSelect('id,full:name,office:offices(city,country),orders(*,orderdetails(qty))');

        self::assertSame([['column' => 'id', 'alias' => 'id'], ['column' => 'name', 'alias' => 'full']], $node->columns);
        self::assertSame('offices', $node->embeds['office']['relation']);
        self::assertSame(['city', 'country'], array_column($node->embeds['office']['node']->columns, 'column'));
        self::assertTrue($node->embeds['orders']['node']->star);
        self::assertSame('orderdetails', $node->embeds['orders']['node']->embeds['orderdetails']['relation']);
        self::assertSame(2, $node->depth());
    }

    public function testFiltersAndOperators(): void
    {
        $options = $this->parse('age=gte.18&status=in.(active,"on hold")&name=ilike.*jo*&deleted_at=is.null&role=not.eq.admin');
        /** @var list<Filter> $f */
        $f = $options->filters->items;

        self::assertSame(['age', 'gte', '18', false], [$f[0]->column, $f[0]->operator, $f[0]->value, $f[0]->negated]);
        self::assertSame(['active', 'on hold'], $f[1]->value);
        self::assertSame('%jo%', $f[2]->value);
        self::assertNull($f[3]->value);
        self::assertTrue($f[4]->negated);
    }

    public function testRelationFilter(): void
    {
        $filter = $this->parse('offices.city=eq.Paris')->filters->items[0];

        self::assertInstanceOf(Filter::class, $filter);
        self::assertSame('offices', $filter->relation);
        self::assertSame('city', $filter->column);
    }

    public function testNestedLogicalGroups(): void
    {
        $group = $this->parse('or=(age.lt.18,and(age.gt.65,offices.country.eq.USA),name.not.like.*x*)')->filters->items[0];

        self::assertInstanceOf(FilterGroup::class, $group);
        self::assertSame('or', $group->boolean);
        self::assertCount(3, $group->items);
        self::assertInstanceOf(FilterGroup::class, $group->items[1]);
        self::assertSame('offices', $group->items[1]->items[1]->relation);
        self::assertTrue($group->items[2]->negated);
    }

    public function testOrderAndPaging(): void
    {
        $options = $this->parse('order=lastName.desc.nullslast,firstName&page=3&per_page=20&count=exact&q=bob&format=csv');

        self::assertSame([
            ['column' => 'lastName', 'direction' => 'desc', 'nulls' => 'last'],
            ['column' => 'firstName', 'direction' => 'asc', 'nulls' => null],
        ], $options->order);
        self::assertSame(20, $options->limit);
        self::assertSame(3, $options->page);
        self::assertTrue($options->count);
        self::assertSame('bob', $options->search);
        self::assertSame('csv', $options->format);
    }

    public function testPreferHeader(): void
    {
        self::assertSame(['return' => 'minimal', 'count' => 'exact'], QueryParser::parsePrefer('return=minimal, count=exact'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'unknown operator' => ['age=between.1'];
        yield 'missing operator' => ['age=18'];
        yield 'bad identifier' => ['select=id;drop'];
        yield 'unbalanced' => ['select=offices(city'];
        yield 'bad in' => ['id=in.1,2'];
        yield 'bad is' => ['id=is.maybe'];
        yield 'bad limit' => ['limit=-1'];
        yield 'bad order modifier' => ['order=id.sideways'];
        yield 'injection in column' => ["name'--=eq.1"];
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueriesAreRejected(string $query): void
    {
        $this->expectException(ApiException::class);
        $this->parse($query);
    }
}
