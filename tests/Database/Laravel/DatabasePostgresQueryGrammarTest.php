<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\PostgresGrammar;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class DatabasePostgresQueryGrammarTest extends TestCase
{
    public function testToRawSql()
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('escape')->with('foo', false)->andReturn("'foo'");
        $grammar = new PostgresGrammar($connection);

        $query = $grammar->substituteBindingsIntoRawSql(
            'select * from "users" where \'{}\' ?? \'Hello\\\'\\\'World?\' AND "email" = ?',
            ['foo'],
        );

        $this->assertSame('select * from "users" where \'{}\' ? \'Hello\\\'\\\'World?\' AND "email" = \'foo\'', $query);
    }

    public function testCustomOperators()
    {
        PostgresGrammar::customOperators(['@@@', '@>', '']);
        PostgresGrammar::customOperators(['@@>', 1]);

        $connection = m::mock(Connection::class);
        $grammar = new PostgresGrammar($connection);

        $operators = $grammar->getOperators();

        $this->assertIsList($operators);
        $this->assertContains('@@@', $operators);
        $this->assertContains('@@>', $operators);
        $this->assertNotContains('', $operators);
        $this->assertNotContains(1, $operators);
        $this->assertSame(array_unique($operators), $operators);
    }

    public function testCompileTruncate()
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn('');

        $postgres = new PostgresGrammar($connection);
        $builder = m::mock(Builder::class);
        $builder->from = 'users';

        $this->assertEquals([
            'truncate "users" restart identity cascade' => [],
        ], $postgres->compileTruncate($builder));

        PostgresGrammar::cascadeOnTruncate(false);

        $this->assertEquals([
            'truncate "users" restart identity' => [],
        ], $postgres->compileTruncate($builder));

        PostgresGrammar::cascadeOnTruncate();

        $this->assertEquals([
            'truncate "users" restart identity cascade' => [],
        ], $postgres->compileTruncate($builder));
    }
}
