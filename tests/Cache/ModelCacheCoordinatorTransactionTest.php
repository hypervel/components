<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\ModelCacheCoordinator;
use Hypervel\Cache\Repository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\DatabaseTransactionsManager;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use RuntimeException;
use UnitEnum;

use function Hypervel\Coroutine\parallel;

class ModelCacheCoordinatorTransactionTest extends TestCase
{
    /**
     * Configure isolated source databases with a single pool slot.
     */
    protected function defineEnvironment(Application $app): void
    {
        foreach (['source', 'other'] as $name) {
            $app->make('config')->set('database.connections.' . $name, [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
                'pool' => ['testing_enabled' => true, 'max_connections' => 1, 'wait_timeout' => 0.1],
            ]);
        }
    }

    public function testTransactionsSeeTheirWritesWithoutPublishingUncommittedValues(): void
    {
        $source = static fn (): CoordinatorTransactionSource => new CoordinatorTransactionSource;
        $connection = $source()->getConnection();
        $connection->getSchemaBuilder()->create('records', static function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        $source()->newQuery()->insert(['id' => 1, 'value' => 'committed']);
        $cache = new Repository(new ArrayStore);
        $coordinator = new ModelCacheCoordinator($this->app);
        $read = fn (): string => $source()->newQuery()->whereKey(1)->value('value');

        $this->assertSame('committed', $coordinator->fill($cache, $source, 'warm', 300, $read));
        $connection->beginTransaction();

        try {
            $source()->newQuery()->whereKey(1)->update(['value' => 'outer']);
            $this->assertSame('outer', $coordinator->fill($cache, $source, 'warm', 300, $read));
            $this->assertSame('outer', $coordinator->fill($cache, $source, 'cold', 300, $read));
            $connection->beginTransaction();
            $source()->newQuery()->whereKey(1)->update(['value' => 'inner']);
            $this->assertSame('inner', $coordinator->fill($cache, $source, 'cold', 300, $read));
            $connection->rollBack();
            $this->assertSame('outer', $coordinator->fill($cache, $source, 'cold', 300, $read));
        } finally {
            $connection->rollBack(0);
        }

        $this->assertNull($cache->get('cold'));
        $this->assertSame('committed', $coordinator->fill($cache, $source, 'warm', 300, $read));
        $this->assertSame('committed', $coordinator->fill($cache, $source, 'cold', 300, $read));
    }

    public function testATransactionOnAnotherConnectionDoesNotBypassTheCache(): void
    {
        $source = static fn (): CoordinatorTransactionSource => new CoordinatorTransactionSource;
        $cache = new Repository(new ArrayStore);
        $coordinator = new ModelCacheCoordinator($this->app);
        $coordinator->fill($cache, $source, 'key', 300, static fn (): string => 'cached');
        $other = DB::connection('other');
        $other->beginTransaction();

        try {
            $this->assertSame('cached', $coordinator->fill(
                $cache,
                $source,
                'key',
                300,
                static fn (): never => throw new RuntimeException('The unrelated transaction must not bypass the cache.'),
            ));
        } finally {
            $other->rollBack();
        }
    }

    public function testAWarmHitDoesNotWaitForASaturatedDatabasePool(): void
    {
        $source = static fn (): CoordinatorTransactionSource => new CoordinatorTransactionSource;
        $cache = new Repository(new WorkerArrayStore);
        $coordinator = new ModelCacheCoordinator($this->app);
        $coordinator->fill($cache, $source, 'key', 300, static fn (): string => 'cached');
        $ready = new Channel(1);
        $release = new Channel(1);

        parallel([
            static function () use ($ready, $release): void {
                $connection = DB::connection('source');
                $connection->beginTransaction();

                try {
                    $ready->push(true);
                    $release->pop(2);
                } finally {
                    $connection->rollBack();
                }
            },
            function () use ($coordinator, $cache, $source, $ready, $release): void {
                $ready->pop(2);
                $other = DB::connection('other');
                $other->beginTransaction();

                try {
                    $this->assertSame('cached', $coordinator->fill(
                        $cache,
                        $source,
                        'key',
                        300,
                        static fn (): never => throw new RuntimeException('The cache hit must not query the database.'),
                    ));
                } finally {
                    $other->rollBack();
                    $release->push(true);
                }
            },
        ]);
    }

    public function testAReboundTestingManagerExcludesItsWrapperWithoutConstructingTheSource(): void
    {
        $coordinator = new ModelCacheCoordinator($this->app);
        $cache = new Repository(new ArrayStore);
        $source = static fn (): CoordinatorTransactionSource => new CoordinatorTransactionSource;
        $coordinator->fill($cache, $source, 'key', 300, static fn (): string => 'cached');
        $manager = new DatabaseTransactionsManager(['source']);
        $this->app->instance('db.transactions', $manager);
        $connection = DB::connection('source');
        $manager->beginTransactions($this->app->make('db'));

        try {
            $this->assertSame('cached', $coordinator->fill(
                $cache,
                static fn (): never => throw new RuntimeException('The wrapper must not construct a source model.'),
                'key',
                300,
                static fn (): never => throw new RuntimeException('The wrapper must not bypass the cache.'),
            ));

            $connection->beginTransaction();
            $this->assertSame('transactional', $coordinator->fill(
                $cache,
                $source,
                'key',
                300,
                static fn (): string => 'transactional',
            ));
        } finally {
            $connection->rollBack(0);
        }

        $this->assertSame('cached', $coordinator->fill($cache, $source, 'key', 300, static fn (): string => 'unused'));
    }
}

class CoordinatorTransactionSource extends Model
{
    protected ?string $table = 'records';

    protected UnitEnum|string|null $connection = 'source';

    public bool $timestamps = false;
}
