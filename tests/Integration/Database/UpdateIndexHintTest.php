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

use function Hypervel\Coroutine\parallel;

class UpdateIndexHintTest extends DatabaseTestCase
{
    private const string CONNECTION_NAME = 'update_index_hint_test';

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

    public function testAnUpdateForcingAnIndexThatDoesNotExistFails(): void
    {
        $this->skipIfDriver('pgsql');

        try {
            DB::table('hinted_posts')->forceIndex('hinted_posts_missing_index')->where('id', 1)->update(['votes' => 1]);

            $this->fail('The update ran without its index hint.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('hinted_posts_missing_index', $exception->getMessage());
        }

        $this->assertSame(0, (int) DB::table('hinted_posts')->sum('votes'));
    }

    public function testAnUpdateForcingThePrimaryKeyLocksOnlyTheRowsItNames(): void
    {
        if (! in_array($this->driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('InnoDB locks the rows a statement reads; other databases are not affected.');
        }

        $holderPool = new DatabasePool($this->app, self::CONNECTION_NAME);
        $contenderPool = new DatabasePool($this->app, self::CONNECTION_NAME);
        $updated = new Channel(1);
        $released = new Channel(1);

        try {
            [$count, $free] = parallel([
                function () use ($holderPool, $updated, $released): int {
                    /** @var PooledConnection $pooledConnection */
                    $pooledConnection = $holderPool->borrow();
                    $connection = $pooledConnection->getConnection();

                    try {
                        $connection->beginTransaction();
                        // Most of a small table, which both servers otherwise read whole, locking every row.
                        $count = $connection->table('hinted_posts')->forceIndex('primary')->whereIn('id', range(1, 20))->update(['votes' => 1]);
                        $updated->push(true);
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
                function () use ($contenderPool, $updated, $released): ?int {
                    $updated->pop(5);

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

        $this->assertSame(20, $count);
        $this->assertSame(30, $free);
        $this->assertSame(20, (int) DB::table('hinted_posts')->sum('votes'));
    }
}
