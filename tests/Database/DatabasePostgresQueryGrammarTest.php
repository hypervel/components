<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\PostgresGrammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JsonException;
use PDO;

class DatabasePostgresQueryGrammarTest extends TestCase
{
    use VerifiesDoubles;

    public function testUpdateBindingsRejectUnencodableArrays(): void
    {
        $this->expectException(JsonException::class);

        (new PostgresGrammar(Double::for(Connection::class)))
            ->prepareBindingsForUpdate([], ['payload' => [NAN]]);
    }

    public function testUpdateFromBindingsRejectArraysOverTheNativeDepthLimit(): void
    {
        $value = 'leaf';

        for ($index = 0; $index < 513; ++$index) {
            $value = ['value' => $value];
        }

        $this->expectException(JsonException::class);

        (new PostgresGrammar(Double::for(Connection::class)))
            ->prepareBindingsForUpdateFrom([], ['payload' => $value]);
    }

    public function testToRawSql(): void
    {
        $connection = Double::for(Connection::class);
        $connection->expects('escape')->with('foo', false)->returns("'foo'");
        $grammar = new PostgresGrammar($connection);

        $query = $grammar->substituteBindingsIntoRawSql(
            'select * from "users" where \'{}\' ?? \'Hello\\\'\\\'World?\' AND "email" = ?',
            ['foo'],
        );

        $this->assertSame('select * from "users" where \'{}\' ? \'Hello\\\'\\\'World?\' AND "email" = \'foo\'', $query);
    }

    public function testCustomOperators(): void
    {
        PostgresGrammar::customOperators(['@@@', '@>', '']);
        PostgresGrammar::customOperators(['@@>', 1]);

        $connection = new PdoConnection(new PDO('sqlite::memory:'));
        $grammar = new PostgresGrammar($connection);

        $operators = $grammar->getOperators();

        $this->assertIsList($operators);
        $this->assertContains('@@@', $operators);
        $this->assertContains('@@>', $operators);
        $this->assertNotContains('', $operators);
        $this->assertNotContains(1, $operators);
        $this->assertSame(array_unique($operators), $operators);
    }

    public function testCompileTruncate(): void
    {
        $connection = Double::for(Connection::class);
        $connection->expects('getTablePrefix')->times(3)->returns('');

        $postgres = new PostgresGrammar($connection);
        $builder = new Builder($connection, $postgres, new Processor);
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

    public function testFlushStateRestoresDefaults(): void
    {
        $connection = Double::for(Connection::class);
        $connection->allows('getTablePrefix')->returns('');

        $postgres = new PostgresGrammar($connection);
        $builder = Double::for(Builder::class);
        $builder->from = 'users';

        PostgresGrammar::customOperators(['@@@']);
        PostgresGrammar::cascadeOnTruncate(false);

        $this->assertContains('@@@', $postgres->getOperators());
        $this->assertSame([
            'truncate "users" restart identity' => [],
        ], $postgres->compileTruncate($builder));

        PostgresGrammar::flushState();

        $this->assertNotContains('@@@', $postgres->getOperators());
        $this->assertSame([
            'truncate "users" restart identity cascade' => [],
        ], $postgres->compileTruncate($builder));
    }
}
