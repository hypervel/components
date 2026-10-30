<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Pool\DatabasePool;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Engine\Channel;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;

use function Hypervel\Coroutine\parallel;

class WriteIndexHintTest extends DatabaseTestCase
{
    private const string CONNECTION_NAME = 'write_index_hint_test';

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $connection = $config->array('database.connections.' . $config->string('database.default'));

        $connection['lock_timeout'] = 1;
        $connection['pool'] = [
            'testing_enabled' => true,
            'min_retained_connections' => 1,
            'max_connections' => 1,
            'heartbeat_interval' => null,
        ];

        $config->set('database.connections.' . self::CONNECTION_NAME, $connection);
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('hinted_posts', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->index();
            $table->integer('votes')->default(0);
        });

        DB::table('hinted_posts')->insert(array_map(static fn (int $id): array => ['title' => "post {$id}"], range(1, 30)));
    }

    public function testUpdatesTakeIndexHints(): void
    {
        // PostgreSQL has no index hints, so its update runs unhinted.
        $hints = match ($this->driver) {
            'pgsql' => [null],
            'sqlite' => ['forceIndex'],
            default => ['useIndex', 'forceIndex', 'ignoreIndex'],
        };

        foreach ($hints as $index => $hint) {
            $query = DB::table('hinted_posts');
            $updated = ($hint === null ? $query : $query->{$hint}('hinted_posts_title_index'))
                ->whereIn('title', ['post 1', 'post 2'])
                ->update(['votes' => $index + 1]);

            $this->assertSame(2, $updated);
            $this->assertSame([1 => $index + 1, 2 => $index + 1, 3 => 0], DB::table('hinted_posts')->whereIn('id', [1, 2, 3])->orderBy('id')->pluck('votes', 'id')->all());
        }
    }

    public function testDeletesTakeIndexHints(): void
    {
        $hints = match ($this->driver) {
            'pgsql' => [null],
            'sqlite' => ['forceIndex'],
            default => ['useIndex', 'forceIndex', 'ignoreIndex'],
        };

        foreach ($hints as $index => $hint) {
            $first = $index * 2 + 1;
            $query = DB::table('hinted_posts as target');
            $deleted = ($hint === null ? $query : $query->{$hint}('hinted_posts_title_index'))
                ->whereIn('target.title', ["post {$first}", 'post ' . ($first + 1)])->delete();

            $this->assertSame(2, $deleted);
            $this->assertSame(0, DB::table('hinted_posts')->whereIn('id', [$first, $first + 1])->count());
        }

        $this->assertSame(30 - count($hints) * 2, DB::table('hinted_posts')->count());

        DB::table('hinted_posts')->where('id', 28)->update(['votes' => 1]);
        $joined = DB::table('hinted_posts as target')
            ->join('hinted_posts as matches', 'target.id', '=', 'matches.id')
            ->where('matches.votes', 0)->whereIn('target.id', [27, 28]);
        if ($this->driver !== 'pgsql') {
            $joined->forceIndex('hinted_posts_title_index');
        }
        $this->assertSame(1, $joined->delete());
        $this->assertSame([28 => 1], DB::table('hinted_posts')->whereIn('id', [27, 28])->pluck('votes', 'id')->all());
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    public function testAWriteForcingAnIndexThatDoesNotExistFails(string $operation): void
    {
        $this->skipIfDriver('pgsql');

        try {
            $query = DB::table('hinted_posts')->forceIndex('hinted_posts_missing_index')->where('id', 1);
            $operation === 'update' ? $query->update(['votes' => 1]) : $query->delete();

            $this->fail('The write ran without its index hint.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('hinted_posts_missing_index', $exception->getMessage());
        }

        $this->assertSame(0, (int) DB::table('hinted_posts')->sum('votes'));
        $this->assertSame(30, DB::table('hinted_posts')->count());
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    public function testAWriteForcingThePrimaryKeyLocksOnlyTheRowsItNames(string $operation): void
    {
        if (! in_array($this->driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('InnoDB locks the rows a statement reads; other databases are not affected.');
        }

        if ($operation === 'delete') {
            DB::table('hinted_posts')->where('id', 1)->update(['votes' => 1]);
        }

        $holderPool = new DatabasePool($this->app, self::CONNECTION_NAME);
        $contenderPool = new DatabasePool($this->app, self::CONNECTION_NAME);
        $written = new Channel(1);
        $released = new Channel(1);

        try {
            [$count, $free] = parallel([
                function () use ($holderPool, $written, $released, $operation): int {
                    /** @var PooledConnection $pooledConnection */
                    $pooledConnection = $holderPool->borrow();
                    $connection = $pooledConnection->getConnection();

                    try {
                        $connection->beginTransaction();
                        // Most of a small table, which both servers otherwise read whole, locking every row.
                        $query = $connection->table('hinted_posts')->forceIndex('primary')->whereIn('id', range(1, 20));
                        // Deletes retain their real eligibility condition. An ID-only
                        // delete can still choose a covering scan despite the hint.
                        $count = $operation === 'update' ? $query->update(['votes' => 1]) : $query->where('votes', 0)->delete();
                        $written->push(true);
                        $released->pop(5);
                        $connection->commit();

                        return $count;
                    } finally {
                        if ($connection->transactionLevel() > 0) {
                            $connection->rollBack();
                        }

                        $pooledConnection->release();
                    }
                },
                function () use ($contenderPool, $written, $released): ?int {
                    $written->pop(5);

                    /** @var PooledConnection $pooledConnection */
                    $pooledConnection = $contenderPool->borrow();
                    $connection = $pooledConnection->getConnection();

                    try {
                        return $connection->transaction(static fn (): ?int => $connection->table('hinted_posts')->where('id', 30)->lockForUpdate()->value('id'));
                    } finally {
                        $released->push(true);
                        $pooledConnection->release();
                    }
                },
            ]);
        } finally {
            $holderPool->close();
            $contenderPool->close();
        }

        $this->assertSame($operation === 'update' ? 20 : 19, $count);
        $this->assertSame(30, $free);
        $this->assertSame($operation === 'update' ? 20 : 1, (int) DB::table('hinted_posts')->sum('votes'));
        $this->assertSame($operation === 'update' ? 30 : 11, DB::table('hinted_posts')->count());
        $this->assertSame(1, DB::table('hinted_posts')->where('id', 1)->value('votes'));
    }
}
