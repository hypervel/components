<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\BinaryParameter;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Grammars\MySqlGrammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Pagination\Cursor;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class DatabaseQueryBuilderPaginationTest extends TestCase
{
    #[DataProvider('keysetIterators')]
    public function testKeysetIterationVisitsEveryUnionRowOnce(string $method, bool $lazy, bool $descending): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->unprepared('create table posts (id integer)');
        $connection->unprepared('create table archives (archive_id integer)');
        $connection->unprepared('insert into posts values (1), (2), (3)');
        $connection->unprepared('insert into archives values (4), (5), (6)');
        $member = $connection->table('archives')->select('archive_id as archived_value');
        $query = $connection->table('posts')->select('id as value')->unionAll($member);
        $actual = [];

        if ($lazy) {
            $actual = $query->{$method}(2, 'id', 'value')->take(8)->pluck('value')->all();
        } else {
            $this->assertTrue($query->{$method}(2, function (Collection $rows) use (&$actual): bool {
                array_push($actual, ...$rows->pluck('value')->all());

                return count($actual) < 8;
            }, 'id', 'value'));
        }

        $this->assertSame($descending ? [6, 5, 4, 3, 2, 1] : [1, 2, 3, 4, 5, 6], $actual);
        $this->assertSame([], $member->wheres);
        $this->assertSame([], $query->getBindings());
    }

    /**
     * Exercise both shared iteration paths and traversal directions.
     */
    public static function keysetIterators(): iterable
    {
        yield 'chunks ascending' => ['chunkById', false, false];
        yield 'chunks descending' => ['chunkByIdDesc', false, true];
        yield 'lazy ascending' => ['lazyById', true, false];
        yield 'lazy descending' => ['lazyByIdDesc', true, true];
    }

    #[DataProvider('keysetIterators')]
    public function testKeysetIterationUsesTheSourceColumnWhenAnAliasShadowsIt(string $method, bool $lazy, bool $descending): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->unprepared('create table entries (id integer, code integer)');
        $connection->unprepared('insert into entries values (1, 40), (2, 30), (3, 20), (4, 10)');

        foreach ([false, true] as $union) {
            $query = $connection->table('entries')->select('code as id', 'id as original_id');
            if ($union) {
                $query->where('id', '<', 3)->unionAll(
                    $connection->table('entries')->select('code as id', 'id as original_id')->where('id', '>=', 3)
                );
            }
            $actual = [];
            if ($lazy) {
                $actual = $query->{$method}(2, 'id', 'original_id')->take(8)->pluck('original_id')->all();
            } else {
                $this->assertTrue($query->{$method}(2, function (Collection $rows) use (&$actual): bool {
                    array_push($actual, ...$rows->pluck('original_id')->all());

                    return count($actual) < 8;
                }, 'id', 'original_id'));
            }

            $this->assertSame($descending ? [4, 3, 2, 1] : [1, 2, 3, 4], $actual);
        }
    }

    public function testUnionCursorPrefersOutputAliasesOverOtherSourceColumns(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->unprepared('create table entries (id integer, name integer)');
        $connection->unprepared('insert into entries values (1, 101), (2, 102), (3, 103), (4, 104)');
        $query = $connection->table('entries')->select('name as label', 'id as name')->where('id', '<', 3)
            ->unionAll($connection->table('entries')->select('name as label', 'id as name')->where('id', '>=', 3))
            ->orderBy('name');
        $cursor = null;
        foreach ([1, 2, 3, 4] as $expected) {
            $page = (clone $query)->cursorPaginate(1, cursor: $cursor);
            $this->assertSame([$expected], $page->pluck('name')->all());
            $cursor = $page->nextCursor();
        }
        $this->assertNull($cursor);
        foreach ([3, 2, 1] as $expected) {
            $page = (clone $query)->cursorPaginate(1, cursor: $page->previousCursor());
            $this->assertSame([$expected], $page->pluck('name')->all());
        }
        $this->assertNull($page->previousCursor());
    }

    #[TestWith(['id', 'entries.id'])]
    #[TestWith(['entries.id', 'id'])]
    public function testUnionPagingMatchesQualifiedAndUnqualifiedSourceColumns(string $selected, string $column): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->unprepared('create table entries (id integer)');
        $connection->unprepared('insert into entries values (1), (2), (3), (4)');
        $query = $connection->table('entries')->select($selected . ' as cursor_id')->where('id', '<', 3)
            ->unionAll($connection->table('entries')->select('id as member_id')->where('id', '>=', 3));

        $this->assertSame([2, 3], (clone $query)->forPageAfterId(2, 1, $column)->pluck('cursor_id')->all());
        $this->assertSame([3, 2], (clone $query)->forPageBeforeId(2, 4, $column)->pluck('cursor_id')->all());
    }

    public function testDirectIdPagesResolveShadowedOrdersAfterTheProjectionIsComplete(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->unprepared('create table entries (id integer, code integer)');
        $connection->unprepared('insert into entries values (1, 40), (2, 30), (3, 20), (4, 10)');
        $query = $connection->table('entries')->forPageAfterId(2, 1, 'id');

        $this->assertSame([30, 20], $query->get(['code as id'])->pluck('id')->all());
        $query->forPageBeforeId(2, 4, 'id');
        $this->assertSame([3, 2], $query->get(['code as id', 'id as original_id'])->pluck('original_id')->all());
        $this->assertSame([20, 30], $connection->table('entries', 'source')->select('code as id')
            ->forPageBeforeId(2, 4, 'id')->pluck('id')->all());
    }

    public function testUnionIdPagingRejectsAnUnselectedSourceHiddenByAnAlias(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Select the paging column under its own output name');
        $connection->table('entries')->select('code as id')->where('id', '<', 3)
            ->unionAll($connection->table('entries')->select('code as id')->where('id', '>=', 3))
            ->forPageAfterId(2, 1, 'id')->get();
    }

    public function testUnionPagingDistinguishesQualifiedSourcesInJoinedProjections(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->unprepared('create table entries (id integer)');
        $connection->unprepared('create table owners (id integer)');
        $connection->unprepared('insert into entries values (1), (2), (3), (4)');
        $connection->unprepared('insert into owners values (101)');
        $query = $connection->table('entries')->crossJoin('owners')
            ->select('owners.id as owner_id', 'entries.id as cursor_id')->where('entries.id', '<', 3)
            ->unionAll($connection->table('entries', 'archive')->crossJoin('owners as other')
                ->select('other.id as owner_id', 'archive.id as cursor_id')->where('archive.id', '>=', 3));

        $this->assertSame([2, 3], $query->forPageAfterId(2, 1, 'entries.id')->pluck('cursor_id')->all());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testUnselectedShadowedIdColumnsRequireQualificationWhenTheirSourceIsUnknown(bool $raw): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->unprepared('create table entries (id integer, code integer)');
        $connection->unprepared('create table owners (owner_id integer)');
        $connection->unprepared('insert into entries values (1, 40), (2, 30), (3, 20), (4, 10)');
        $connection->unprepared('insert into owners values (1)');
        $query = $connection->query()->select('code as id');
        if ($raw) {
            $query->fromRaw('entries');
        } else {
            $query->from('entries')->crossJoin('owners');
        }

        $this->assertSame([30, 20], (clone $query)->forPageAfterId(2, 1, 'entries.id')->pluck('id')->all());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Qualify the paging column');
        $query->forPageAfterId(2, 1, 'id')->get();
    }

    public function testDirectKeysetPagesPreserveScopesLimitsAndCurrentBindings(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->unprepared('create table posts (id integer)');
        $connection->unprepared('create table archives (id integer)');
        $connection->unprepared('insert into posts values (1), (2), (3)');
        $connection->unprepared('insert into archives values (4), (5), (6)');
        $model = new class extends Model {
            protected ?string $table = 'posts';
        };
        $model::setConnectionResolver(m::mock(ConnectionResolverInterface::class, ['connection' => $connection]));
        $query = (new EloquentBuilder($connection->query()))->setModel($model)
            ->withGlobalScope('visible', static fn (EloquentBuilder $query): EloquentBuilder => $query->where('posts.id', '<', 3))
            ->select('id')->orderByDesc('id')->limit(2)
            ->unionAll($connection->table('archives')->select('id'))->orderBy('id');

        $scoped = (clone $query)->forPageAfterId(2, 1)->limit(1);
        $this->assertSame([2], $scoped->get()->modelKeys());
        $unscoped = (clone $query)->forPageAfterId(2, 2)->withoutGlobalScope('visible')->limit(1);
        $this->assertSame([3], $unscoped->get()->modelKeys());
        $this->assertSame(2, (clone $query)->withoutGlobalScopes()->forPageAfterId(2, 2)->count());
        $this->assertSame(4, (clone $query)->withoutGlobalScopes()->forPageAfterId(10, 2)->count());
        $this->assertSame(4, (clone $query)->withoutGlobalScopes()->forPageAfterId(2, 2)
            ->getQuery()->groupBy('id')->getCountForPagination());

        $base = (clone $query)->withoutGlobalScopes()->getQuery();
        $this->assertSame($base, $base->forPageAfterId(3, 1));
        $this->assertSame($base, $base->forPageBeforeId(3, 5));
        $base->setBindings([2, 5], 'where');
        $this->assertSame([4, 3], $base->get()->pluck('id')->all());
        $sql = $base->toSql();
        $bindings = $base->getBindings();
        $this->assertSame($sql, $base->toSql());
        $this->assertSame($bindings, $base->getBindings());
        $base->forPageAfterId(2, 3);
        $this->assertSame([4], $base->get()->pluck('id')->all());
        $this->assertSame([2, 5, 3, 2, 5, 3], $base->getBindings());
        $this->assertSame([], $base->getRawBindings()['union']);

        foreach ([false, true] as $readFirst) {
            $reused = $connection->table('posts')->forPageAfterId(1, 2);
            if ($readFirst) {
                $this->assertSame([3], $reused->get()->pluck('id')->all());
            }
            $reused->unionAll($connection->table('posts')->where('id', 1))->forPageAfterId(2, 0);
            $this->assertSame([1, 3], $reused->get()->pluck('id')->all());
        }
    }

    #[TestWith(['wildcard'])]
    #[TestWith(['raw root'])]
    #[TestWith(['raw member'])]
    public function testUnprovenUnionProjectionsRequireExplicitWrapping(string $shape): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->unprepared('create table posts (id integer, title text)');
        $connection->unprepared('create table archives (archive_id integer, title text)');
        $connection->unprepared("insert into posts values (1, 'first'), (2, 'second')");
        $connection->unprepared("insert into archives values (3, 'third')");
        $query = $connection->table('posts');
        $member = $connection->table('archives');
        if ($shape === 'raw root') {
            $query->selectRaw('id, title');
            $member->select('archive_id', 'title');
        } elseif ($shape === 'raw member') {
            $query->select('id', 'title');
            $member->selectRaw('archive_id, title');
        }
        $query->unionAll($member);
        $page = (clone $query)->forPageAfterId(2, 1);
        try {
            $page->get();
            $this->fail('The unknown projection width must not be guessed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('fromSub()', $exception->getMessage());
        }

        $this->assertSame([2, 3], $connection->query()->fromSub($query, 'entries')->forPageAfterId(2, 1)->pluck('id')->all());
    }

    public function testPreparedPagesKeepBindingsAndEmbeddingConsistent(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->unprepared('create table posts (id integer)');
        $connection->unprepared('create table archives (id integer)');
        $connection->unprepared('insert into posts values (1), (2), (3)');
        $connection->unprepared('insert into archives values (4), (5), (6)');
        $page = $connection->table('posts')->select('id')->orderByDesc('id')->limit(2)
            ->unionAll($connection->table('archives')->select('id')->where('id', '>', 4))
            ->forPageAfterId(3, 1);
        $rawBindings = $page->getRawBindings();

        $this->assertSame([2, 3, 5], $page->get()->pluck('id')->all());
        $this->assertSame([2, 3, 5], $page->cursor()->pluck('id')->all());
        $this->assertTrue($page->exists());
        $this->assertSame(4, $page->getCountForPagination());
        $this->assertSame(3, $connection->query()->fromSub($page, 'entries')->count());
        $this->assertSame(3, $connection->table('posts')->whereExists($page)->count());
        $this->assertSame([2, 3], $connection->table('posts')->whereIn('id', $page)->orderBy('id')->pluck('id')->all());
        $this->assertSame([2], $connection->table('posts')->where('id', '=', (clone $page)->limit(1))->pluck('id')->all());

        $embedded = clone $page;
        $callbackCalls = 0;
        $embedded->beforeQuery(function (Builder $query) use (&$callbackCalls): void {
            ++$callbackCalls;
            $query->where('id', '>', 2);
        });
        $merged = $connection->query()->mergeBindings($embedded)->fromRaw('(' . $embedded->toSql() . ') as entries');
        $this->assertSame([3, 5, 6], $merged->get()->pluck('id')->all());
        $this->assertSame(1, $callbackCalls);
        $this->assertSame($rawBindings, $page->getRawBindings());
        $this->assertSame('posts', $page->from);
        $this->assertSame(2, $page->limit);
        $this->assertSame(3, $page->unionLimit);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCursorComparisonsFilterGroupedExpressionResults(bool $shadowed): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->unprepared('create table events (tenant_id integer, amount integer)');
        $connection->unprepared('insert into events values (1, 2), (1, 3), (2, 5), (3, 7)');
        $groupColumn = $shadowed ? 'total' : 'tenant_id';
        $aggregateColumn = $shadowed ? 'tenant_id' : 'total';
        $query = $connection->table('events')->select('tenant_id as ' . $groupColumn)->selectRaw('sum(amount) as ' . $aggregateColumn)
            ->groupBy('events.tenant_id')->havingRaw('sum(amount) >= ?', [5])
            ->orderBy($aggregateColumn)->orderBy($groupColumn)
            ->beforeQuery(static fn (Builder $query) => $query->orderByRaw('tenant_id + ?', [100]));

        $first = (clone $query)->cursorPaginate(1);
        $second = (clone $query)->cursorPaginate(1, cursor: $first->nextCursor());
        $third = (clone $query)->cursorPaginate(1, cursor: $second->nextCursor());

        $this->assertSame([1], $first->pluck($groupColumn)->all());
        $this->assertSame([2], $second->pluck($groupColumn)->all());
        $this->assertSame([3], $third->pluck($groupColumn)->all());
        $this->assertSame([1], (clone $query)->cursorPaginate(1, cursor: $second->previousCursor())->pluck($groupColumn)->all());
        $this->assertSame([], (clone $query)->cursorPaginate(1, cursor: new Cursor([$aggregateColumn => 7, $groupColumn => 3]))->items());
    }

    public function testBinaryStringsCanBePagedUsingAnExplicitCursorAlias(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->unprepared('create table files (hash blob primary key)');
        $hashes = [pack('N', 1), pack('N', 2), pack('N', 3)];

        foreach ($hashes as $hash) {
            $connection->table('files')->insert(['hash' => new BinaryParameter($hash)]);
        }

        $actual = [];
        $connection->table('files')->afterQuery(function (Collection $rows): void {
            foreach ($rows as $row) {
                $row->cursor = new BinaryParameter($row->hash);
            }
        })->chunkById(2, function (Collection $rows) use (&$actual): bool {
            array_push($actual, ...$rows->pluck('hash')->all());

            return count($actual) < 4;
        }, 'hash', 'cursor');

        $this->assertSame($hashes, $actual);
    }

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

    #[TestWith([false])]
    #[TestWith([true])]
    public function testAggregatePreparesCallbacksBeforeSelectingItsBindings(bool $grouped): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->unprepared('create table events (tenant_id integer)');
        $connection->unprepared('insert into events values (1), (2), (2)');
        $connection->enableQueryLog();
        $query = $connection->table('events')->where('tenant_id', '>', 0)
            ->beforeQuery(function (Builder $query) use ($grouped): void {
                $query->selectRaw('tenant_id + ? as bucket', [10])->orderByRaw('tenant_id + ?', [20]);

                if ($grouped) {
                    $query->groupBy('tenant_id')->havingRaw('count(*) > ?', [0]);
                }
            });

        $this->assertSame($grouped ? 2 : 3, $query->count());
        $this->assertSame($grouped ? [10, 0, 0, 20] : [0], $connection->getQueryLog()[0]['bindings']);
        $this->assertNull($query->columns);
        $this->assertNull($query->orders);
        $this->assertCount(1, $query->beforeQueryCallbacks);
        $this->assertSame($grouped ? [11, 12] : [11, 12, 12], $query->get()->pluck('bucket')->all());
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
