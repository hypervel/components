<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;
use ReflectionClass;

class DatabaseQueryGrammarTest extends TestCase
{
    public function testWhereRawReturnsStringWhenExpressionPassed()
    {
        $builder = m::mock(Builder::class);
        $grammar = new Grammar(m::mock(Connection::class));
        $reflection = new ReflectionClass($grammar);
        $method = $reflection->getMethod('whereRaw');
        $expressionArray = ['sql' => new Expression('select * from "users"')];

        $rawQuery = $method->invoke($grammar, $builder, $expressionArray);

        $this->assertSame('select * from "users"', $rawQuery);
    }

    public function testWhereRawReturnsStringWhenStringPassed()
    {
        $builder = m::mock(Builder::class);
        $grammar = new Grammar(m::mock(Connection::class));
        $reflection = new ReflectionClass($grammar);
        $method = $reflection->getMethod('whereRaw');
        $stringArray = ['sql' => 'select * from "users"'];

        $rawQuery = $method->invoke($grammar, $builder, $stringArray);

        $this->assertSame('select * from "users"', $rawQuery);
    }

    public function testCompileOrdersAcceptsExpression()
    {
        $builder = m::mock(Builder::class);
        $grammar = new Grammar(m::mock(Connection::class));

        // compileOrders() calls $query->getGrammar() → return our $grammar
        $builder->shouldReceive('getGrammar')->andReturn($grammar);

        $orders = [
            ['sql' => new Expression('length("name") desc')], // mimics orderByRaw(DB::raw(...))
        ];

        $ref = new ReflectionClass($grammar);
        $method = $ref->getMethod('compileOrders'); // protected
        $sql = $method->invoke($grammar, $builder, $orders);

        $this->assertSame('order by length("name") desc', strtolower($sql));
    }

    public function testCompileOrdersAcceptsExpressionWithPlaceholders()
    {
        $builder = m::mock(Builder::class);
        $grammar = new Grammar(m::mock(Connection::class));
        $builder->shouldReceive('getGrammar')->andReturn($grammar);

        $orders = [
            ['sql' => new Expression('field(status, ?, ?) asc')],
        ];

        $ref = new ReflectionClass($grammar);
        $method = $ref->getMethod('compileOrders');
        $sql = $method->invoke($grammar, $builder, $orders);

        $this->assertSame('order by field(status, ?, ?) asc', strtolower($sql));
    }

    public function testRawSqlBindingSubstitutionPreservesParserSemantics(): void
    {
        $resource = fopen('php://memory', 'r');
        $resourceIdentity = (string) $resource;

        try {
            $connection = m::mock(Connection::class);
            $connection->shouldReceive('escape')->once()->with('first', false)->andReturn("'first'");
            $connection->shouldReceive('escape')->once()->with($resourceIdentity, false)->andReturn("'resource'");

            $sql = (new Grammar($connection))->substituteBindingsIntoRawSql(
                "select ?, '?', ??, ?, ?",
                ['first', $resource]
            );

            $this->assertSame("select 'first', '?', ??, 'resource', ?", $sql);
        } finally {
            fclose($resource);
        }
    }

    public function testRawSqlBindingSubstitutionRendersLiveAndClosedResourceIdentities(): void
    {
        $liveResource = fopen('php://memory', 'r');
        $closedResource = fopen('php://memory', 'r');
        $liveResourceIdentity = (string) $liveResource;
        $closedResourceIdentity = (string) $closedResource;

        fclose($closedResource);

        try {
            $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');

            $sql = $connection->getQueryGrammar()->substituteBindingsIntoRawSql(
                'select ?, ?',
                [$liveResource, $closedResource]
            );

            $this->assertSame(
                "select '{$liveResourceIdentity}', '{$closedResourceIdentity}'",
                $sql
            );
        } finally {
            fclose($liveResource);
        }
    }

    public function testRawSqlBindingSubstitutionHandlesLongBindingLists(): void
    {
        $bindings = range(1, 250);
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('escape')
            ->times(count($bindings))
            ->andReturnUsing(static fn (int $value, bool $binary): string => (string) $value);

        $sql = (new Grammar($connection))->substituteBindingsIntoRawSql(
            implode(', ', array_fill(0, count($bindings), '?')),
            $bindings
        );

        $this->assertSame(implode(', ', $bindings), $sql);
    }
}
