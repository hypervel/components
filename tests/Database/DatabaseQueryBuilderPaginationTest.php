<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Grammars\MySqlGrammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class DatabaseQueryBuilderPaginationTest extends TestCase
{
    #[DataProvider('readWriteRouting')]
    public function testGroupedCountsPreserveReadWriteRouting(bool $useWritePdo, int $expectedCount): void
    {
        $write = new PDO('sqlite::memory:');
        $read = new PDO('sqlite::memory:');
        $write->exec('create table events (tenant_id integer)');
        $read->exec('create table events (tenant_id integer)');
        $write->exec('insert into events values (1), (2), (2)');
        $read->exec('insert into events values (1)');

        $connection = new SQLiteConnection($write);
        $connection->setReadPdo($read);
        $query = $connection->table('events')->select('tenant_id')->groupBy('tenant_id');

        if ($useWritePdo) {
            $query->useWritePdo();
        }

        $this->assertCount($expectedCount, $query->get());
        $this->assertSame($expectedCount, $query->getCountForPagination());
        $this->assertSame($useWritePdo, $query->useWritePdo);
        $this->assertCount($expectedCount, $query->get());
        $this->assertSame(1, $connection->table('events')->count());
        $this->assertSame($read, $connection->getRawReadPdo());
        $this->assertSame($write, $connection->getRawPdo());
    }

    /**
     * Supply the default reader and explicit writer routes.
     */
    public static function readWriteRouting(): iterable
    {
        yield 'reader' => [false, 1];
        yield 'writer' => [true, 2];
    }

    public function testCallbackGroupingIsAppliedBeforeChoosingTheCountQuery(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->unprepared('create table events (tenant_id integer)');
        $connection->unprepared('insert into events values (1), (2), (2)');
        $callbackCalls = 0;
        $query = $connection->table('events')->select('tenant_id')->fetchUsing(PDO::FETCH_ASSOC)
            ->beforeQuery(function (Builder $query) use (&$callbackCalls): void {
                ++$callbackCalls;
                $query->groupBy('tenant_id')->orderBy('tenant_id')->fetchUsing(PDO::FETCH_COLUMN);
            });

        $this->assertSame(2, $query->getCountForPagination());
        $this->assertSame(1, $callbackCalls);
        $this->assertNull($query->groups);
        $this->assertNull($query->orders);
        $this->assertSame([PDO::FETCH_ASSOC], $query->fetchUsing);
        $this->assertCount(1, $query->beforeQueryCallbacks);
        $this->assertSame([1, 2], $query->get()->all());
        $this->assertSame(2, $callbackCalls);
        $this->assertSame([], $query->beforeQueryCallbacks);
    }

    public function testPlainCountRemovesCallbackSuppliedProjectionOrderingAndPagination(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->unprepared('create table events (tenant_id integer)');
        $connection->unprepared('insert into events values (1), (2), (2)');
        $connection->enableQueryLog();
        $query = $connection->table('events')->where('tenant_id', '>', 0)
            ->beforeQuery(function (Builder $query): void {
                $query->selectRaw('tenant_id + ? as bucket', [10])
                    ->orderByRaw('tenant_id + ?', [20])->limit(1)->offset(1);
            });

        $this->assertSame(3, $query->getCountForPagination());
        $this->assertSame('select count(*) as "aggregate" from "events" where "tenant_id" > ?', $connection->getQueryLog()[0]['query']);
        $this->assertSame([0], $connection->getQueryLog()[0]['bindings']);
        $this->assertNull($query->columns);
        $this->assertNull($query->orders);
        $this->assertNull($query->limit);
        $this->assertNull($query->offset);
        $this->assertSame([0], $query->getBindings());
        $this->assertCount(1, $query->beforeQueryCallbacks);
    }

    public function testGroupedCountTransfersCallbackTimeoutAndRoutingToTheOuterStatement(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $query = new Builder($connection, new MySqlGrammar($connection), new Processor);
        $query->from('events')->groupBy('tenant_id')->beforeQuery(function (Builder $query): void {
            $query->timeout(5)->useWritePdo();
        });
        $connection->shouldReceive('select')->once()->with(
            'select /*+ MAX_EXECUTION_TIME(5000) */ count(*) as `aggregate` from (select * from `events` group by `tenant_id`) as `aggregate_table`',
            [],
            false,
            [],
        )->andReturn([['aggregate' => 2]]);

        $this->assertSame(2, $query->getCountForPagination());
        $this->assertNull($query->timeout);
        $this->assertFalse($query->useWritePdo);
        $this->assertCount(1, $query->beforeQueryCallbacks);
    }

    public function testCallbackFailurePreservesIdentityAndRestoresTheOriginalFetchMode(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $query = new Builder($connection, new Grammar($connection), new Processor);
        $exception = new RuntimeException('Cannot prepare the query.');
        $query->from('events')->select('tenant_id')->fetchUsing(PDO::FETCH_COLUMN)
            ->beforeQuery(static function () use ($exception): never {
                throw $exception;
            });

        try {
            $query->getCountForPagination();
            $this->fail('The before-query exception was not thrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertCount(1, $query->beforeQueryCallbacks);
        $this->assertSame([PDO::FETCH_COLUMN], $query->fetchUsing);
        $query->beforeQueryCallbacks = [];
        $connection->shouldReceive('select')->once()->with(
            'select "tenant_id" from "events"',
            [],
            true,
            [PDO::FETCH_COLUMN],
        )->andReturn([1, 2]);
        $this->assertSame([1, 2], $query->get()->all());
    }

    #[DataProvider('tablePrefixes')]
    public function testRetainedInnerBindingsBelongToTheDerivedTable(string $prefix): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn($prefix);
        $processor = m::mock(Processor::class);
        $query = new PaginationWithOrderingBuilder($connection, new Grammar($connection), $processor);
        $query->from('events')->selectRaw('tenant_id + ? as bucket', [11])
            ->where('tenant_id', '>', 12)->groupBy('tenant_id')
            ->havingRaw('count(*) > ?', [13])->orderByRaw('tenant_id + ?', [14])
            ->limit(5)->offset(10)
            ->beforeQuery(function (Builder $query): void {
                $query->where('active', 15)->useWritePdo();
            });
        $originalBindings = $query->getRawBindings();
        $originalOrders = $query->orders;

        $connection->shouldReceive('select')->once()->with(
            'select count(*) as "aggregate" from (select tenant_id + ? as bucket from "' . $prefix . 'events" where "tenant_id" > ? and "active" = ? group by "tenant_id" having count(*) > ? order by tenant_id + ?) as "aggregate_table"',
            [11, 12, 15, 13, 14],
            false,
            [],
        )->andReturn([['aggregate' => 3]]);
        $processor->shouldReceive('processSelect')->once()->andReturnUsing(function (Builder $countQuery, array $results): array {
            $this->assertSame([11, 12, 15, 13, 14], $countQuery->getRawBindings()['from']);
            $this->assertSame([], $countQuery->getRawBindings()['select']);
            $this->assertSame([], $countQuery->getRawBindings()['where']);
            $this->assertSame([], $countQuery->getRawBindings()['having']);
            $this->assertSame([], $countQuery->getRawBindings()['order']);

            return $results;
        });

        $this->assertSame(3, $query->getCountForPagination());
        $this->assertSame($originalBindings, $query->getRawBindings());
        $this->assertSame($originalOrders, $query->orders);
        $this->assertSame(5, $query->limit);
        $this->assertSame(10, $query->offset);
        $this->assertFalse($query->useWritePdo);
        $this->assertCount(1, $query->beforeQueryCallbacks);
    }

    /**
     * Supply connections with and without table prefixes.
     */
    public static function tablePrefixes(): iterable
    {
        yield 'unprefixed' => [''];
        yield 'prefixed' => ['audit_'];
    }
}

class PaginationWithOrderingBuilder extends Builder
{
    /**
     * Retain ordering required by a driver's pagination subquery.
     */
    protected function cloneForPaginationCount(): static
    {
        return $this->cloneWithout(['limit', 'offset']);
    }
}
