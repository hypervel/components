<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Database\BinaryParameter;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Casts\AsBinary;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Pagination\Cursor;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class EloquentCursorPaginateTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('test_posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->float('score')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('test_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->unsignedInteger('owner_id')->nullable();
            $table->timestamps();
        });

        Schema::create('test_owners', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();
        });

        Schema::create('test_post_user', function (Blueprint $table) {
            $table->unsignedInteger('post_id');
            $table->unsignedInteger('user_id');
        });
    }

    public function testCursorPaginationOnTopOfColumns(): void
    {
        for ($i = 1; $i <= 16; ++$i) {
            $posts[] = [
                'title' => 'Title ' . $i,
            ];
        }
        TestPost::fillAndInsert($posts);

        $this->assertCount(15, TestPost::cursorPaginate(15, ['id', 'title']));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCursorPaginationResolvesAliasesInTheColumnsArgument(bool $eloquent): void
    {
        TestPost::fillAndInsert([[], [], []]);
        $scopeCalls = 0;
        $callbackCalls = 0;
        $query = $eloquent ? TestPost::query() : DB::table('test_posts');

        if ($eloquent) {
            $query->withGlobalScope('visible', function (Builder $builder) use (&$scopeCalls): void {
                ++$scopeCalls;
                $this->assertNull($builder->getQuery()->columns);
                $builder->where('id', '>', 1);
            });
        }

        $query->beforeQuery(function (QueryBuilder $builder) use (&$callbackCalls): void {
            ++$callbackCalls;
            $this->assertSame(['id as cursor_id'], $builder->columns);
        });
        $page = $query->orderBy('cursor_id')->cursorPaginate(
            2,
            ['id as cursor_id'],
            cursor: new Cursor(['cursor_id' => 2]),
        );

        $this->assertSame([3], $page->pluck('cursor_id')->all());
        $this->assertSame($eloquent ? 1 : 0, $scopeCalls);
        $this->assertSame(1, $callbackCalls);
        $this->assertNull(($eloquent ? $query->getQuery() : $query)->columns);
    }

    public function testCursorPaginationWithNumericExpressionProjection(): void
    {
        TestPost::fillAndInsert([['title' => 'First'], ['title' => 'Second']]);

        $page = TestPost::query()->select([new Expression(1.5), 'id'])
            ->orderBy('id')->cursorPaginate(1, cursor: new Cursor(['id' => 1]));

        $this->assertSame([2], $page->getCollection()->modelKeys());
        $this->assertEquals(1.5, array_first($page->items()[0]->getAttributes()));
    }

    #[TestWith(['query'], 'query expression')]
    #[TestWith(['eloquent'], 'model expression')]
    #[TestWith(['scope'], 'scope-added expression')]
    #[TestWith(['argument'], 'lone expression argument')]
    public function testCursorPaginationUsesTheSelectedArithmeticExpression(string $selection): void
    {
        TestPost::fillAndInsert([[], [], [], []]);
        $scopeCalls = 0;
        $query = function () use ($selection, &$scopeCalls): Builder|QueryBuilder {
            $query = in_array($selection, ['eloquent', 'scope'], true)
                ? TestPost::query() : DB::table('test_posts');

            if ($selection === 'scope') {
                $query->withGlobalScope('computed-id', function (Builder $builder) use (&$scopeCalls): void {
                    ++$scopeCalls;
                    $this->assertNull($builder->getQuery()->columns);
                    $builder->selectRaw('id + 1 as id');
                });
            } elseif ($selection !== 'argument') {
                $query->selectRaw('id + 1 as id');
            }

            return $query->orderBy('id');
        };
        $columns = $selection === 'argument' ? new Expression('id + 1 as id') : ['*'];

        $first = $query()->cursorPaginate(2, $columns);
        $second = $query()->cursorPaginate(2, $columns, cursor: $first->nextCursor());
        $previous = $query()->cursorPaginate(2, $columns, cursor: $second->previousCursor());

        $this->assertSame([2, 3], $first->pluck('id')->all());
        $this->assertSame([4, 5], $second->pluck('id')->all());
        $this->assertSame([2, 3], $previous->pluck('id')->all());
        $this->assertNull($second->nextCursor());
        $this->assertSame($selection === 'scope' ? 3 : 0, $scopeCalls);
    }

    public function testCursorPaginationQuotesAliasedIdentifiersContainingParentheses(): void
    {
        Schema::table('test_posts', function (Blueprint $table): void {
            $table->integer('weight (kg)')->nullable();
        });
        TestPost::fillAndInsert([['weight (kg)' => 10], ['weight (kg)' => 20]]);
        $query = fn () => TestPost::query()->select('weight (kg) as weight')->orderBy('weight');

        $first = $query()->cursorPaginate(1);
        $second = $query()->cursorPaginate(1, cursor: $first->nextCursor());

        $this->assertSame([10], $first->pluck('weight')->all());
        $this->assertSame([20], $second->pluck('weight')->all());
        $this->assertNull($second->nextCursor());
    }

    #[DataProvider('idIteratorScopes')]
    public function testIdIterationKeepsPagingOrderThroughGlobalScopes(string $method, bool $lazy, bool $descending, bool $replace): void
    {
        TestPost::fillAndInsert(array_map(fn (int $id): array => ['id' => $id, 'user_id' => 4 - (int) ceil($id / 2)], range(1, 6)));
        $scopeCalls = 0;
        $query = TestPost::query()->withGlobalScope('sort', function (Builder $builder) use ($descending, $replace, &$scopeCalls): void {
            ++$scopeCalls;
            $builder->{$replace ? 'reorder' : 'orderBy'}('user_id', $descending ? 'desc' : 'asc');
        });
        $ids = [];
        if ($lazy) {
            $ids = $query->{$method}(2)->take(8)->pluck('id')->all();
        } else {
            $this->assertTrue($query->{$method}(2, function ($rows) use (&$ids): bool {
                array_push($ids, ...$rows->modelKeys());

                return count($ids) < 8;
            }));
        }

        $this->assertSame($descending ? [6, 5, 4, 3, 2, 1] : [1, 2, 3, 4, 5, 6], $ids);
        $this->assertSame(4, $scopeCalls);
        $this->assertSame($descending ? [3, 3, 2, 2, 1, 1] : [1, 1, 2, 2, 3, 3], $query->get()->pluck('user_id')->all());
    }

    /**
     * Cover both directions and iterator families with appended and replaced scope orders.
     */
    public static function idIteratorScopes(): iterable
    {
        foreach (['chunkById', 'chunkByIdDesc', 'lazyById', 'lazyByIdDesc'] as $method) {
            foreach ([false, true] as $replace) {
                yield $method . ($replace ? ' replaced' : ' appended') => [$method, str_starts_with($method, 'lazy'), str_ends_with($method, 'Desc'), $replace];
            }
        }
    }

    #[DataProvider('limitedIdScopeOrders')]
    public function testLimitedIdIterationValidatesReplacingScopeOrders(string $scopeOrder, bool $offsetOnly, bool $callerOrder, bool $throws, bool $descending = false): void
    {
        TestPost::fillAndInsert(array_map(fn (int $id): array => ['id' => $id, 'user_id' => (4 - $id) * 10], range(1, 4)));

        foreach ($descending ? ['chunkByIdDesc', 'lazyByIdDesc'] : ['chunkById', 'lazyById'] as $method) {
            $scopeCalls = 0;
            $query = TestPost::query()->withGlobalScope('sort', function (Builder $builder) use ($scopeOrder, &$scopeCalls): void {
                ++$scopeCalls;
                match ($scopeOrder) {
                    'replace-other' => $builder->reorder('user_id'),
                    'replace-id' => $builder->reorder('id'),
                    'replace-descending' => $builder->reorder('id', 'desc'),
                    'append-other' => $builder->orderBy('user_id'),
                    'clear' => $builder->reorder(),
                    'filter' => $builder->where('id', '>', 0),
                };
            });
            $offsetOnly ? $query->offset(1) : $query->limit(2);
            if ($callerOrder) {
                $query->orderBy('id', $descending ? 'desc' : 'asc');
            }
            $ids = [];

            try {
                if (str_starts_with($method, 'lazy')) {
                    $rows = $query->{$method}(1);
                    $this->assertSame(0, $scopeCalls);
                    $rows->each(function (TestPost $post) use (&$ids): void {
                        $ids[] = $post->id;
                    });
                } else {
                    $query->{$method}(1, function ($rows) use (&$ids): void {
                        array_push($ids, ...$rows->modelKeys());
                    });
                }
                $this->assertFalse($throws, 'A conflicting replacement must fail before processing rows.');
                $expected = $descending ? [4, 3, 2, 1] : [1, 2, 3, 4];
                $this->assertSame($offsetOnly ? array_slice($expected, 1) : array_slice($expected, 0, 2), $ids);
                $this->assertSame($offsetOnly ? 4 : 2, $scopeCalls);
            } catch (InvalidArgumentException $exception) {
                $this->assertTrue($throws);
                $this->assertStringContainsString('fromSub()', $exception->getMessage());
                $this->assertSame([], $ids);
                $this->assertSame(1, $scopeCalls);
            }
        }
    }

    /**
     * Distinguish scope replacements from appended orders and ordinary filtering.
     */
    public static function limitedIdScopeOrders(): iterable
    {
        yield 'replacement with a limit' => ['replace-other', false, false, true];
        yield 'replacement with an offset' => ['replace-other', true, false, true];
        yield 'replacement of an explicit ID order' => ['replace-other', false, true, true];
        yield 'replacement with the opposite direction' => ['replace-descending', false, false, true];
        yield 'replacement with the paging key' => ['replace-id', false, false, false];
        yield 'replacement with the descending paging key' => ['replace-descending', false, false, false, true];
        yield 'cleared ordering' => ['clear', false, false, false];
        yield 'appended order with a limit' => ['append-other', false, false, false];
        yield 'appended order with an offset' => ['append-other', true, false, false];
        yield 'appended order after an explicit ID order' => ['append-other', false, true, false];
        yield 'filtering only' => ['filter', false, false, false];
    }

    public function testCursorPaginationKeepsItsCompoundOrderWhenAScopeReplacesIt(): void
    {
        TestPost::fillAndInsert(array_map(fn (int $id): array => ['id' => $id, 'user_id' => 4 - (int) ceil($id / 2)], range(1, 6)));
        $query = fn () => TestPost::query()->orderBy('user_id')->orderBy('id')
            ->withGlobalScope('reverse', fn (Builder $builder) => $builder->reorder('id', 'desc'));

        $first = $query()->cursorPaginate(3);
        $second = $query()->cursorPaginate(3, cursor: $first->nextCursor());
        $previous = $query()->cursorPaginate(3, cursor: $second->previousCursor());

        $this->assertSame([5, 6, 3], $first->getCollection()->modelKeys());
        $this->assertSame([4, 1, 2], $second->getCollection()->modelKeys());
        $this->assertSame([5, 6, 3], $previous->getCollection()->modelKeys());
    }

    #[TestWith(['cursorPaginate'])]
    #[TestWith(['chunkById'])]
    #[TestWith(['lazyById'])]
    public function testUnionPagingRestoresItsOuterOrderAndBoundedMemberBindings(string $method): void
    {
        TestPost::fillAndInsert(array_map(fn (int $id): array => ['id' => $id], range(1, 7)));
        $query = fn () => TestPost::where('id', '>=', 4)->orderByRaw('id * ? desc', [1])->limit(2)
            ->unionAll(TestPost::where('id', '<=', 3))->orderBy('id')
            ->withGlobalScope('reverse', fn (Builder $builder) => $builder->reorder()->inOrderOf('id', [7, 6, 5, 4, 3, 2, 1]));
        $ids = [];
        if ($method === 'cursorPaginate') {
            $cursor = null;
            do {
                $page = $query()->cursorPaginate(2, cursor: $cursor);
                array_push($ids, ...$page->getCollection()->modelKeys());
                $cursor = $page->nextCursor();
            } while ($cursor !== null && count($ids) < 8);
            $this->assertSame([3, 6], $query()->cursorPaginate(2, cursor: $page->previousCursor())->getCollection()->modelKeys());
        } elseif ($method === 'lazyById') {
            $ids = $query()->lazyById(2)->take(8)->pluck('id')->all();
        } else {
            $this->assertTrue($query()->chunkById(2, function ($rows) use (&$ids): bool {
                array_push($ids, ...$rows->modelKeys());

                return count($ids) < 8;
            }));
        }

        $this->assertSame([1, 2, 3, 6, 7], $ids);
    }

    #[DataProvider('unionDefaultOrders')]
    public function testUnionPaginationAddsAnOuterDefaultOrder(string $method, string $columns, bool $bounded): void
    {
        TestPost::fillAndInsert(array_map(fn (int $id): array => ['id' => $id, 'user_id' => $id * 10], range(1, 6)));
        $query = function () use ($columns, $bounded): Builder {
            $first = TestPost::query()->select($columns)->where('id', '>=', 4);
            if ($bounded) {
                $first->orderByDesc('id')->limit(2);
            }

            return $first->unionAll(TestPost::query()->select($columns)->where('id', '<=', 3));
        };
        $key = str_contains($columns, 'record_id') ? 'record_id' : 'id';
        $ids = [];
        if ($method === 'cursorPaginate') {
            $cursor = null;
            do {
                $page = $query()->cursorPaginate(2, cursor: $cursor);
                array_push($ids, ...$page->pluck($key)->all());
                $cursor = $page->nextCursor();
            } while ($cursor !== null && count($ids) < 8);
        } elseif ($method === 'lazy') {
            $ids = $query()->lazy(2)->take(8)->pluck($key)->all();
        } else {
            $this->assertTrue($query()->chunk(2, function ($rows) use (&$ids, $key): bool {
                array_push($ids, ...$rows->pluck($key)->all());

                return count($ids) < 8;
            }));
        }

        $expected = $bounded ? [1, 2, 3, 5, 6] : range(1, 6);
        if ($columns === 'user_id as id') {
            $expected = array_map(static fn (int $id): int => $id * 10, $expected);
        }
        $this->assertSame($expected, $ids);
        $raw = $query()->getQuery();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You must specify an orderBy clause after the union');
        match ($method) {
            'chunk' => $raw->chunk(2, fn () => true),
            'lazy' => $raw->lazy(2)->first(),
            default => $raw->cursorPaginate(2),
        };
    }

    /**
     * Resolve the default union order through its actual output names.
     */
    public static function unionDefaultOrders(): iterable
    {
        foreach (['cursorPaginate', 'chunk', 'lazy'] as $method) {
            yield $method . ' plain key' => [$method, '*', true];
            yield $method . ' aliased key' => [$method, 'id as record_id', true];
            yield $method . ' qualified aliased key without an inner order' => [$method, 'test_posts.id as record_id', false];
            yield $method . ' shadowing output' => [$method, 'user_id as id', true];
        }
    }

    #[TestWith([false], 'table name')]
    #[TestWith([true], 'table alias')]
    public function testUnionDefaultOrderUsesTheModelKeyInsteadOfAJoinedKey(bool $aliased): void
    {
        TestUser::fillAndInsert([['id' => 10], ['id' => 20]]);
        TestPost::fillAndInsert(array_map(fn (int $id): array => ['id' => $id, 'user_id' => $id % 2 ? 10 : 20], range(1, 6)));
        $source = $aliased ? 'p' : 'test_posts';
        $member = fn (string $operator): Builder => TestPost::query()->from('test_posts', $aliased ? 'p' : null)
            ->join('test_users', 'test_users.id', '=', $source . '.user_id')
            ->select($aliased ? 'test_users.id' : 'test_users.id as owner_id', $source . '.id as post_id')
            ->where($source . '.id', $operator, 3);
        $ids = [];
        $cursor = null;

        do {
            $page = $member('<=')->unionAll($member('>'))->cursorPaginate(2, cursor: $cursor);
            array_push($ids, ...$page->pluck('post_id')->all());
            $cursor = $page->nextCursor();
        } while ($cursor !== null && count($ids) < 8);

        $this->assertSame([1, 2, 3, 4, 5, 6], $ids);
    }

    #[TestWith([false], 'single select')]
    #[TestWith([true], 'union members')]
    public function testCursorBoundaryAppliesToAllOrWhereBranches(bool $union): void
    {
        TestPost::fillAndInsert([[], [], [], [], []]);
        $query = function () use ($union): QueryBuilder {
            $query = DB::table('test_posts')->select('id')->where('id', 1);

            if ($union) {
                $query->orWhere('id', 3)->unionAll(
                    DB::table('test_posts')->select('id')->where('id', 4)->orWhere('id', 5)
                );
            } else {
                $query->orWhere('id', '>=', 3);
            }

            return $query->orderBy('id');
        };

        $first = $query()->cursorPaginate(2);
        $second = $query()->cursorPaginate(2, cursor: $first->nextCursor());
        $previous = $query()->cursorPaginate(2, cursor: $second->previousCursor());

        $this->assertSame([1, 3], $first->pluck('id')->all());
        $this->assertSame([4, 5], $second->pluck('id')->all());
        $this->assertSame([1, 3], $previous->pluck('id')->all());
        $this->assertNull($second->nextCursor());
    }

    public function testPaginationWithUnion(): void
    {
        TestPost::fillAndInsert([
            ['title' => 'Hello world', 'user_id' => 1],
            ['title' => 'Goodbye world', 'user_id' => 2],
            ['title' => 'Howdy', 'user_id' => 3],
            ['title' => '4th', 'user_id' => 4],
        ]);

        $table1 = TestPost::query()->whereIn('user_id', [1, 2]);
        $table2 = TestPost::query()->whereIn('user_id', [3, 4]);

        $result = $table1->unionAll($table2)
            ->orderBy('user_id', 'desc')
            ->cursorPaginate(1);

        $this->assertSame(['user_id'], $result->getOptions()['parameters']);
    }

    #[DataProvider('boundedUnionProvider')]
    public function testPaginationPreservesBoundedUnionMembers(string $shape, array $firstIds, array $secondIds): void
    {
        for ($id = 1; $id <= 7; ++$id) {
            TestPost::create(['id' => $id]);
        }

        $calls = 0;
        $query = function () use ($shape, &$calls): QueryBuilder {
            $bounded = DB::table('test_posts')->where('id', '<', 7)->orderByDesc('id')->limit(3);
            $ordinary = DB::table('test_posts')->select('id as identifier')->where('id', 7);
            $query = match ($shape) {
                'root' => $bounded->unionAll($ordinary),
                'member' => $ordinary->unionAll($bounded->select('id as identifier')),
                'group' => $ordinary->unionAll(
                    DB::table('test_posts')->select('id as identifier')->where('id', '<=', 3)
                        ->unionAll(DB::table('test_posts')->select('id as identifier')->whereBetween('id', [4, 6]))
                        ->orderByDesc('identifier')->offset(1)->limit(3)
                ),
            };

            return $query->orderByDesc('identifier')->beforeQuery(function () use (&$calls): void {
                ++$calls;
            });
        };

        $first = $query()->cursorPaginate(2, ['id as identifier']);
        $this->assertSame($firstIds, $first->pluck('identifier')->all());
        $this->assertSame(1, $calls);
        $second = $query()->cursorPaginate(2, ['id as identifier'], cursor: $first->nextCursor());
        $this->assertSame($secondIds, $second->pluck('identifier')->all());
        $this->assertSame(2, $calls);
        $previous = $query()->cursorPaginate(2, ['id as identifier'], cursor: $second->previousCursor());
        $this->assertSame($firstIds, $previous->pluck('identifier')->all());
        $this->assertSame(3, $calls);
        $after = $query()->cursorPaginate(2, ['id as identifier'], cursor: new Cursor(['identifier' => $secondIds[1]]));
        $this->assertSame([], $after->items());
        $this->assertSame(4, $calls);
    }

    /**
     * Provide bounded primary selects, members and complete union groups.
     */
    public static function boundedUnionProvider(): array
    {
        return [
            ['root', [7, 6], [5, 4]],
            ['member', [7, 6], [5, 4]],
            ['group', [7, 5], [4, 3]],
        ];
    }

    public function testBoundedEloquentUnionKeepsScopesAndEagerLoads(): void
    {
        TestUser::fillAndInsert([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]]);
        TestPost::fillAndInsert([['user_id' => 1], ['user_id' => 3], ['user_id' => 4]]);
        $scopeCalls = 0;
        $query = function () use (&$scopeCalls): Builder {
            return TestUser::with('posts')
                ->withGlobalScope('visible', function (Builder $query) use (&$scopeCalls): void {
                    ++$scopeCalls;
                    $query->where('test_users.id', '>=', 2);
                })
                ->orderByDesc('id')->limit(2)
                ->unionAll(TestUser::where('id', 1))
                ->orderByDesc('id');
        };

        $first = $query()->cursorPaginate(2);
        $this->assertSame([4, 3], $first->getCollection()->modelKeys());
        $this->assertSame(1, $scopeCalls);
        $second = $query()->cursorPaginate(2, cursor: $first->nextCursor());
        $this->assertSame([1], $second->getCollection()->modelKeys());
        $this->assertSame(2, $scopeCalls);
        $this->assertTrue($second->items()[0]->relationLoaded('posts'));
        $this->assertSame([1], $second->items()[0]->posts->pluck('user_id')->all());
        $previous = $query()->cursorPaginate(2, cursor: $second->previousCursor());
        $this->assertSame([4, 3], $previous->getCollection()->modelKeys());
        $this->assertSame(3, $scopeCalls);
    }

    public function testBoundedUnionMemberKeepsItsImplicitProjection(): void
    {
        TestPost::fillAndInsert([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]]);
        $query = fn (): QueryBuilder => DB::table('test_posts')->where('id', '<=', 2)->orderByDesc('id')->limit(1)
            ->unionAll(DB::table('test_posts', 'archive')->where('id', '>', 2)->orderByDesc('id')->limit(1))
            ->orderByDesc('id');

        $first = $query()->cursorPaginate(1, ['test_posts.*']);
        $this->assertSame([4], $first->pluck('id')->all());
        $second = $query()->cursorPaginate(1, ['test_posts.*'], cursor: $first->nextCursor());
        $this->assertSame([2], $second->pluck('id')->all());
    }

    public function testOrdinaryCursorPaginationStillOwnsItsLimit(): void
    {
        TestPost::fillAndInsert([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]]);
        $page = DB::table('test_posts')->orderBy('id')->limit(1)
            ->cursorPaginate(3, cursor: new Cursor(['id' => 1]));

        $this->assertSame([2, 3, 4], $page->pluck('id')->all());
    }

    public function testPaginationWithDistinct(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $posts[] = ['title' => 'Hello world'];
            $posts[] = ['title' => 'Goodbye world'];
        }
        TestPost::fillAndInsert($posts);

        $query = TestPost::query()->distinct();

        $this->assertCount(6, $query->get());
        $this->assertEquals(6, $query->count());
        $this->assertCount(6, $query->cursorPaginate()->items());
    }

    public function testPaginationWithWhereClause(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $posts[] = ['title' => 'Hello world', 'user_id' => null];
            $posts[] = ['title' => 'Goodbye world', 'user_id' => 2];
        }
        TestPost::fillAndInsert($posts);

        $query = TestPost::query()->whereNull('user_id');

        $this->assertCount(3, $query->get());
        $this->assertEquals(3, $query->count());
        $this->assertCount(3, $query->cursorPaginate()->items());
    }

    public function testPaginationWithHasClause(): void
    {
        TestUser::fillAndInsert([[], [], []]);

        for ($i = 1; $i <= 3; ++$i) {
            $posts[] = ['title' => 'Hello world', 'user_id' => null];
            $posts[] = ['title' => 'Goodbye world', 'user_id' => 2];
            $posts[] = ['title' => 'Howdy', 'user_id' => 3];
        }
        TestPost::fillAndInsert($posts);

        $query = TestUser::query()->has('posts');

        $this->assertCount(2, $query->get());
        $this->assertEquals(2, $query->count());
        $this->assertCount(2, $query->cursorPaginate()->items());
    }

    public function testPaginationWithWhereHasClause(): void
    {
        TestUser::fillAndInsert([[], [], []]);
        for ($i = 1; $i <= 3; ++$i) {
            $posts[] = ['title' => 'Hello world', 'user_id' => null];
            $posts[] = ['title' => 'Goodbye world', 'user_id' => 2];
            $posts[] = ['title' => 'Howdy', 'user_id' => 3];
        }
        TestPost::fillAndInsert($posts);

        $query = TestUser::query()->whereHas('posts', function ($query) {
            $query->where('title', 'Howdy');
        });

        $this->assertCount(1, $query->get());
        $this->assertEquals(1, $query->count());
        $this->assertCount(1, $query->cursorPaginate()->items());
    }

    public function testPaginationWithWhereExistsClause(): void
    {
        TestUser::fillAndInsert([[], [], []]);
        for ($i = 1; $i <= 3; ++$i) {
            $posts[] = ['title' => 'Hello world', 'user_id' => null];
            $posts[] = ['title' => 'Goodbye world', 'user_id' => 2];
            $posts[] = ['title' => 'Howdy', 'user_id' => 3];
        }
        TestPost::fillAndInsert($posts);

        $query = TestUser::query()->whereExists(function ($query) {
            $query->select(DB::raw(1))
                ->from('test_posts')
                ->whereColumn('test_posts.user_id', 'test_users.id');
        });

        $this->assertCount(2, $query->get());
        $this->assertEquals(2, $query->count());
        $this->assertCount(2, $query->cursorPaginate()->items());
    }

    public function testPaginationWithMultipleWhereClauses(): void
    {
        TestUser::fillAndInsert([[], [], [], []]);
        for ($i = 1; $i <= 4; ++$i) {
            $posts[] = ['title' => 'Hello world', 'user_id' => null];
            $posts[] = ['title' => 'Goodbye world', 'user_id' => 2];
            $posts[] = ['title' => 'Howdy', 'user_id' => 3];
            $posts[] = ['title' => 'Howdy', 'user_id' => 4];
        }
        TestPost::fillAndInsert($posts);

        $query = TestUser::query()->whereExists(function ($query) {
            $query->select(DB::raw(1))
                ->from('test_posts')
                ->whereColumn('test_posts.user_id', 'test_users.id');
        })->whereHas('posts', function ($query) {
            $query->where('title', 'Howdy');
        })->where('id', '<', 5)->orderBy('id');

        $clonedQuery = $query->clone();
        $anotherQuery = $query->clone();

        $this->assertEquals(2, $query->get()->count());
        $this->assertEquals(2, $query->count());
        $this->assertCount(2, $query->cursorPaginate()->items());
        $this->assertCount(1, $clonedQuery->cursorPaginate(1)->items());
        $this->assertCount(
            1,
            $anotherQuery->cursorPaginate(5, ['*'], 'cursor', new Cursor(['id' => 3]))
                ->items()
        );
    }

    public function testPaginationWithMultipleUnionAndMultipleWhereClauses(): void
    {
        TestPost::fillAndInsert([
            ['title' => 'Post A', 'user_id' => 100],
            ['title' => 'Post B', 'user_id' => 101],
        ]);

        $table1 = TestPost::select(['id', 'title', 'user_id'])->where('user_id', 100);
        $table2 = TestPost::select(['id', 'title', 'user_id'])->where('user_id', 101);
        $table3 = TestPost::select(['id', 'title', 'user_id'])->where('user_id', 101);

        $columns = ['id'];
        $cursorName = 'cursor-name';
        $cursor = new Cursor(['id' => 1]);

        $result = $table1->toBase()
            ->union($table2->toBase())
            ->union($table3->toBase())
            ->orderBy('id', 'asc')
            ->cursorPaginate(1, $columns, $cursorName, $cursor);

        $this->assertSame(['id'], $result->getOptions()['parameters']);

        $postB = $table2->where('id', '>', 1)->first();
        $this->assertSame('Post B', $postB->title, 'Expect `Post B` is the result of the second query');

        $this->assertCount(1, $result->items(), 'Expect cursor paginated query should have 1 result');
        $this->assertSame('Post B', current($result->items())->title, 'Expect the paginated query would return `Post B`');
    }

    public function testPaginationWithMultipleAliases(): void
    {
        TestUser::fillAndInsert([
            ['name' => 'A (user)'],
            ['name' => 'C (user)'],
        ]);

        TestPost::fillAndInsert([['title' => 'B (post)'], ['title' => 'D (post)']]);

        $table1 = TestPost::select(['title as alias']);
        $table2 = TestUser::select(['name as alias']);

        $columns = ['alias'];
        $cursorName = 'cursor-name';
        $cursor = new Cursor(['alias' => 'A (user)']);

        $result = $table1->toBase()
            ->union($table2->toBase())
            ->orderBy('alias', 'asc')
            ->cursorPaginate(1, $columns, $cursorName, $cursor);

        $this->assertSame(['alias'], $result->getOptions()['parameters']);

        $this->assertCount(1, $result->items(), 'Expect cursor paginated query should have 1 result');
        $this->assertSame('B (post)', current($result->items())->alias, 'Expect the paginated query would return `B (post)`');
    }

    public function testPaginationWithAliasedOrderBy(): void
    {
        TestUser::fillAndInsert([[], [], [], [], [], []]);

        $query = TestUser::query()->select('id as user_id')->orderBy('user_id');
        $clonedQuery = $query->clone();
        $anotherQuery = $query->clone();

        $this->assertCount(6, $query->get());
        $this->assertEquals(6, $query->count());
        $this->assertCount(6, $query->cursorPaginate()->items());
        $this->assertCount(3, $clonedQuery->cursorPaginate(3)->items());
        $this->assertCount(
            4,
            $anotherQuery->cursorPaginate(10, ['*'], 'cursor', new Cursor(['user_id' => 2]))
                ->items()
        );
    }

    public function testPaginationWithDistinctColumnsAndSelect(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $posts[] = ['title' => 'Hello world'];
            $posts[] = ['title' => 'Goodbye world'];
        }
        TestPost::fillAndInsert($posts);

        $query = TestPost::query()->orderBy('title')->distinct('title')->select('title');

        $this->assertCount(2, $query->get());
        $this->assertEquals(2, $query->count());
        $this->assertCount(2, $query->cursorPaginate()->items());
    }

    public function testPaginationWithDistinctColumnsAndSelectAndJoin(): void
    {
        TestUser::fillAndInsert([[], [], [], [], []]);
        $users = TestUser::query()->get();
        for ($i = 1; $i <= 5; ++$i) {
            $user = $users[$i - 1];

            for ($j = 1; $j <= 10; ++$j) {
                $posts[] = [
                    'title' => 'Title ' . $i,
                    'user_id' => $user->id,
                ];
            }
        }
        TestPost::fillAndInsert($posts);

        $query = TestUser::query()->join('test_posts', 'test_posts.user_id', '=', 'test_users.id')
            ->distinct('test_users.id')->select('test_users.*');

        $this->assertCount(5, $query->get());
        $this->assertEquals(5, $query->count());
        $this->assertCount(5, $query->cursorPaginate()->items());
    }

    public function testCursorPaginationPreservesFloatOrderValues(): void
    {
        TestPost::fillAndInsert([
            ['score' => 1.25],
            ['score' => 2.5],
            ['score' => 3.75],
        ]);

        $result = TestPost::query()
            ->orderBy('score')
            ->orderBy('id')
            ->cursorPaginate(1, ['*'], 'cursor', new Cursor(['score' => 1.25, 'id' => 1]));

        $this->assertSame(2, $result->items()[0]->id);
        $this->assertSame(2.5, $result->items()[0]->score);
    }

    public function testCursorPaginationUsesTheBinaryPivotCast(): void
    {
        Schema::table('test_post_user', function (Blueprint $table): void {
            $table->binary('position', length: 16, fixed: true)->nullable();
        });
        $user = TestUser::create();

        foreach ([1, 2, 3] as $position) {
            $post = TestPost::create();
            DB::table('test_post_user')->insert([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'position' => new BinaryParameter(hex2bin(str_pad((string) $position, 32, '0', STR_PAD_LEFT))),
            ]);
        }

        $scopeCalls = 0;
        $relation = function () use ($user, &$scopeCalls): BelongsToMany {
            return $user->favoritePosts()
                ->using(CursorBinaryPivot::class)
                ->withPivot('position')
                ->withGlobalScope('visible', function (Builder $builder) use (&$scopeCalls): void {
                    ++$scopeCalls;
                    $builder->whereNotNull('test_posts.id');
                })
                ->orderBy('test_post_user.position');
        };
        $first = $relation()->cursorPaginate(2);
        $next = $relation()->cursorPaginate(2, cursor: $first->nextCursor()->encode());

        $this->assertSame([3], $next->pluck('id')->all());
        $this->assertSame(2, $scopeCalls);
    }

    public function testRelationsAcceptCursorObjects(): void
    {
        TestOwner::fillAndInsert([[]]);
        TestUser::fillAndInsert([
            ['owner_id' => 1],
            ['owner_id' => 1],
        ]);
        TestPost::fillAndInsert([
            ['user_id' => 1],
            ['user_id' => 2],
        ]);
        DB::table('test_post_user')->insert([
            ['post_id' => 1, 'user_id' => 1],
            ['post_id' => 2, 'user_id' => 1],
        ]);

        $belongsToMany = TestUser::findOrFail(1)
            ->favoritePosts()
            ->orderBy('id')
            ->cursorPaginate(1, ['*'], 'cursor', new Cursor(['id' => 1]));

        $hasManyThrough = TestOwner::findOrFail(1)
            ->posts()
            ->orderBy('test_posts.id')
            ->cursorPaginate(1, ['test_posts.*'], 'cursor', new Cursor(['test_posts.id' => 1]));

        $this->assertSame(2, $belongsToMany->items()[0]->id);
        $this->assertSame(2, $hasManyThrough->items()[0]->id);
    }
}

class TestPost extends Model
{
    protected array $guarded = [];
}

class TestUser extends Model
{
    protected array $guarded = [];

    public function posts()
    {
        return $this->hasMany(TestPost::class, 'user_id');
    }

    /** @return BelongsToMany<TestPost, $this> */
    public function favoritePosts(): BelongsToMany
    {
        return $this->belongsToMany(TestPost::class, 'test_post_user', 'user_id', 'post_id');
    }
}

class CursorBinaryPivot extends Pivot
{
    protected array $casts = ['position' => AsBinary::class . ':uuid'];
}

class TestOwner extends Model
{
    protected array $guarded = [];

    /** @return HasManyThrough<TestPost, TestUser, $this> */
    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(TestPost::class, TestUser::class, 'owner_id', 'user_id');
    }
}
