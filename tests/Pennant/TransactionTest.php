<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Pennant\Drivers\DatabaseDriver;
use Hypervel\Pennant\Feature;
use Hypervel\Support\Facades\DB;
use RuntimeException;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');

        $config->set('pennant.default', 'database');
        $config->set('pennant.stores.secondary', [
            'driver' => 'database',
        ]);
        $config->set('pennant.stores.writer', [
            'driver' => 'database',
            'connection' => 'testing::write',
        ]);
        $config->set('pennant.stores.cached', [
            'driver' => 'database',
            'cache' => ['store' => 'array'],
        ]);
    }

    protected function setUpInCoroutine(): void
    {
        Feature::define('foo', fn () => 'resolved');
    }

    public function testRollingBackForgetsValuesMemoizedInsideTheTransaction(): void
    {
        DB::beginTransaction();
        Feature::for('taylor')->activate('foo', 'inside');
        $this->assertSame('inside', Feature::for('taylor')->value('foo'));
        DB::rollBack();

        $this->assertSame('resolved', Feature::for('taylor')->value('foo'));
    }

    public function testRollingBackASavepointForgetsValuesMemoizedInsideIt(): void
    {
        DB::transaction(function () {
            DB::beginTransaction();
            Feature::for('taylor')->activate('foo', 'inside');
            DB::rollBack();

            $this->assertSame('resolved', Feature::for('taylor')->value('foo'));
        });
    }

    public function testRollingBackForgetsValuesMemoizedInsideCommittedSavepoints(): void
    {
        DB::beginTransaction();
        DB::transaction(fn () => Feature::for('taylor')->activate('foo', 'inside'));
        DB::rollBack();

        $this->assertSame('resolved', Feature::for('taylor')->value('foo'));
    }

    public function testRollingBackForgetsBeforeHookValues(): void
    {
        $this->app->instance(TransactionFeatureWithBeforeHook::class, $feature = new TransactionFeatureWithBeforeHook);
        Feature::define(TransactionFeatureWithBeforeHook::class);
        $feature->value = 'inside';

        DB::beginTransaction();
        Feature::for('taylor')->value(TransactionFeatureWithBeforeHook::class);
        DB::rollBack();

        $feature->value = 'after';

        $this->assertSame('after', Feature::for('taylor')->value(TransactionFeatureWithBeforeHook::class));
    }

    public function testRollingBackForgetsValuesOfEveryConnectionSharingTheTransaction(): void
    {
        Feature::store('writer')->define('foo', fn () => 'resolved');

        DB::beginTransaction();
        Feature::for('taylor')->activate('foo', 'inside');
        Feature::store('writer')->for('otwell')->activate('foo', 'inside');
        DB::rollBack();

        $this->assertSame('resolved', Feature::for('taylor')->value('foo'));
        $this->assertSame('resolved', Feature::store('writer')->for('otwell')->value('foo'));
    }

    public function testCommittingKeepsValuesMemoizedInsideTheTransaction(): void
    {
        DB::transaction(fn () => Feature::for('taylor')->activate('foo', 'inside'));
        $queries = $this->countQueries();

        $this->assertSame('inside', Feature::for('taylor')->value('foo'));
        $this->assertSame(0, $queries->count);
    }

    public function testFlushingAStoreKeepsOtherStoresValuesTrackedForRollback(): void
    {
        Feature::store('secondary')->define('foo', fn () => 'resolved');

        DB::beginTransaction();
        Feature::store('secondary')->for('taylor')->activate('foo', 'inside');
        Feature::store('database')->flushCache();
        DB::rollBack();

        $this->assertSame('resolved', Feature::store('secondary')->for('taylor')->value('foo'));
    }

    public function testSettingManyValuesAcrossChunksRollsBackTogether(): void
    {
        $exception = new RuntimeException('The second chunk failed.');
        $inserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$inserts, $exception) {
            if (str_starts_with($query->sql, 'insert') && ++$inserts === 2) {
                throw $exception;
            }
        });

        try {
            Feature::for(range(1, DatabaseDriver::WRITE_ROW_LIMIT + 1))->activate('foo');
            $this->fail('The second chunk should have failed.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(2, $inserts);
        $this->assertSame(0, DB::table('features')->count());
    }

    public function testSharedCacheIsBypassedInsideATransaction(): void
    {
        Feature::store('cached')->define('foo', fn () => 'resolved');
        Feature::store('cached')->for('taylor')->value('foo');
        DB::table('features')->update(['value' => json_encode('changed')]);
        Feature::store('cached')->flushCache();

        DB::transaction(function () {
            $this->assertSame('changed', Feature::store('cached')->for('taylor')->value('foo'));
        });

        Feature::store('cached')->flushCache();

        $this->assertSame('resolved', Feature::store('cached')->for('taylor')->value('foo'));
    }

    public function testSharedCacheIsInvalidatedAfterTheOutermostCommit(): void
    {
        Feature::store('cached')->define('foo', fn () => 'resolved');
        Feature::store('cached')->for('taylor')->value('foo');

        DB::transaction(function () {
            DB::transaction(fn () => Feature::store('cached')->for('taylor')->activate('foo', 'inside'));
        });
        Feature::store('cached')->flushCache();

        $this->assertSame('inside', Feature::store('cached')->for('taylor')->value('foo'));
    }

    public function testSharedCacheIsNotInvalidatedWhenTheTransactionRollsBack(): void
    {
        Feature::store('cached')->define('foo', fn () => 'resolved');
        Feature::store('cached')->for('taylor')->value('foo');

        DB::beginTransaction();
        DB::transaction(fn () => Feature::store('cached')->for('taylor')->activate('foo', 'inside'));
        DB::rollBack();
        $queries = $this->countQueries();

        $this->assertSame('resolved', Feature::store('cached')->for('taylor')->value('foo'));
        $this->assertSame(0, $queries->count);
    }

    /**
     * Count the queries executed from now on.
     */
    protected function countQueries(): object
    {
        $queries = (object) ['count' => 0];

        DB::listen(function () use ($queries) {
            ++$queries->count;
        });

        return $queries;
    }
}

class TransactionFeatureWithBeforeHook
{
    public ?string $value = null;

    public function resolve(): string
    {
        return 'resolved';
    }

    public function before(): ?string
    {
        return $this->value;
    }
}
