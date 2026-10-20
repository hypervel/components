<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Pennant\Redis;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Pennant\Feature;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Pennant\TestCase;

class StoredValueCacheTest extends TestCase
{
    use InteractsWithRedis;
    use RefreshDatabase;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('pennant.default', 'cached');
        $config->set('pennant.stores.cached', [
            'driver' => 'database',
            'cache' => ['store' => 'redis'],
        ]);
    }

    protected function setUpInCoroutine(): void
    {
        Feature::define('foo', fn () => 'resolved');
    }

    public function testStoredValuesAreReadFromRedis(): void
    {
        Feature::for(['taylor', 'otwell'])->load('foo');
        Feature::flushCache();
        $queries = $this->countQueries();

        $this->assertSame(['resolved', 'resolved'], Feature::for(['taylor', 'otwell'])->load('foo')['foo']);
        $this->assertSame(0, $queries->count);
    }

    public function testWritesInvalidateTheirScope(): void
    {
        Feature::for(['taylor', 'otwell'])->load('foo');
        Feature::for('taylor')->activate('foo', 'changed');
        Feature::flushCache();
        $queries = $this->countQueries();

        $this->assertSame('resolved', Feature::for('otwell')->value('foo'));
        $this->assertSame(0, $queries->count);
        $this->assertSame('changed', Feature::for('taylor')->value('foo'));
    }

    public function testBulkWritesInvalidateEveryAffectedScope(): void
    {
        Feature::for(['taylor', 'otwell'])->load('foo');
        Feature::for(['taylor', 'otwell'])->activate('foo', 'changed');
        Feature::flushCache();

        $this->assertSame(['changed', 'changed'], Feature::for(['taylor', 'otwell'])->load('foo')['foo']);

        Feature::activateForEveryone('foo', 'everyone');
        Feature::flushCache();

        $this->assertSame(['everyone', 'everyone'], Feature::for(['taylor', 'otwell'])->load('foo')['foo']);

        Feature::purge('foo');
        Feature::flushCache();

        $this->assertSame(['resolved', 'resolved'], Feature::for(['taylor', 'otwell'])->load('foo')['foo']);
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
