<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

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
