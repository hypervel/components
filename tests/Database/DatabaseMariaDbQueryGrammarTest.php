<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\MariaDbGrammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JsonException;

class DatabaseMariaDbQueryGrammarTest extends TestCase
{
    use VerifiesDoubles;

    public function testUpdateBindingsRejectUnencodableArrays(): void
    {
        $this->expectException(JsonException::class);

        (new MariaDbGrammar(Double::for(Connection::class)->strict()))
            ->prepareBindingsForUpdate([], ['payload' => [NAN]]);
    }

    public function testToRawSql(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('escape')->with('foo', false)->returns("'foo'");
        $grammar = new MariaDbGrammar($connection);

        $query = $grammar->substituteBindingsIntoRawSql(
            'select * from "users" where \'Hello\\\'World?\' IS NOT NULL AND "email" = ?',
            ['foo'],
        );

        $this->assertSame('select * from "users" where \'Hello\\\'World?\' IS NOT NULL AND "email" = \'foo\'', $query);
    }

    public function testTimeoutDecoratesSimpleAggregateAndLockingStatements(): void
    {
        $simple = $this->getBuilder()->from('users')->where('active', true)->timeout(5);
        $this->assertSame(
            'SET STATEMENT max_statement_time=5 FOR select * from `users` where `active` = ?',
            $simple->toSql()
        );

        $aggregate = $this->getBuilder()->from('users')->timeout(4);
        $aggregate->aggregate = ['function' => 'count', 'columns' => ['*']];
        $this->assertSame(
            'SET STATEMENT max_statement_time=4 FOR select count(*) as `aggregate` from `users`',
            $aggregate->toSql()
        );

        $locking = $this->getBuilder()->from('users')->where('id', 1)->lockForUpdate()->timeout(3);
        $this->assertSame(
            'SET STATEMENT max_statement_time=3 FOR select * from `users` where `id` = ? for update',
            $locking->toSql()
        );
    }

    public function testTimeoutDecoratesOrdinaryAndAggregateUnionStatementsOnce(): void
    {
        $builder = $this->getBuilder()
            ->from('posts')
            ->where('published', true)
            ->unionAll($this->getBuilder()->from('videos')->where('published', false))
            ->timeout(5);

        $this->assertSame(
            'SET STATEMENT max_statement_time=5 FOR (select * from `posts` where `published` = ?) union all (select * from `videos` where `published` = ?)',
            $builder->toSql()
        );

        $builder->aggregate = ['function' => 'count', 'columns' => ['*']];

        $sql = $builder->toSql();

        $this->assertSame(
            'SET STATEMENT max_statement_time=5 FOR select count(*) as `aggregate` from ((select * from `posts` where `published` = ?) union all (select * from `videos` where `published` = ?)) as `temp_table`',
            $sql
        );
        $this->assertSame(1, substr_count($sql, 'SET STATEMENT'));
        $this->assertSame([true, false], $builder->getBindings());
    }

    public function testTimeoutDecoratesExistsAtTheStatementRoot(): void
    {
        $builder = $this->getBuilder()->from('users')->where('active', true)->timeout(4);

        $this->assertSame(
            'SET STATEMENT max_statement_time=4 FOR select exists(select * from `users` where `active` = ?) as `exists`',
            $builder->getGrammar()->compileExists($builder)
        );
    }

    public function testTimeoutCanBeCleared(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->timeout(60)->timeout(null);

        $this->assertSame('select * from `users`', $builder->toSql());
    }

    protected function getBuilder(): Builder
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->allows('getDatabaseName')->returns('database');
        $connection->allows('getTablePrefix')->returns('');

        return new Builder(
            $connection,
            new MariaDbGrammar($connection),
            Double::for(Processor::class)->strict()
        );
    }
}
