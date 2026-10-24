<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant;

use Hypervel\Cache\Events\RetrievingManyKeys;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Pennant\Drivers\StoredValueCache;
use Hypervel\Pennant\Feature;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use InvalidArgumentException;
use JsonSerializable;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class StoredValueCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');

        $config->set('cache.stores.shared', ['driver' => 'worker-array']);
        $config->set('cache.stores.reader', ['driver' => 'worker-array']);
        $config->set('cache.stores.failing', ['driver' => 'null']);
        $config->set('database.connections.pooled', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'pool' => ['testing_enabled' => true, 'min_retained_connections' => 1, 'max_connections' => 1],
        ]);
        $config->set('pennant.default', 'cached');
        $config->set('pennant.stores.cached', [
            'driver' => 'database',
            'cache' => ['store' => 'shared'],
        ]);
        $config->set('pennant.stores.pooled', [
            'driver' => 'database',
            'connection' => 'pooled',
            'cache' => ['store' => 'shared'],
        ]);
    }

    protected function setUpInCoroutine(): void
    {
        Feature::define('foo', fn () => 'resolved');
        Feature::store('database')->define('foo', fn () => 'resolved');
    }

    public function testValuesReadBeforeAWriteAreIgnoredWhenCachedAfterIt(): void
    {
        Feature::store('database')->for('taylor')->activate('foo', 'old');
        $written = false;
        DB::listen(function (QueryExecuted $query) use (&$written) {
            if (! $written && str_starts_with($query->sql, 'select')) {
                $written = true;
                Feature::store('database')->for('taylor')->activate('foo', 'new');
            }
        });

        $this->assertSame('old', Feature::for('taylor')->value('foo'));

        Feature::flushCache();

        $this->assertSame('new', Feature::for('taylor')->value('foo'));
    }

    #[DataProvider('uncachedWriters')]
    public function testWritesThroughAnUncachedStoreInvalidateOnlyTheirScope(?string $readerRole, ?string $writerRole): void
    {
        $default = DB::getDefaultConnection();
        $connection = fn (?string $role) => match ($role) {
            null => null,
            'base' => $default,
            'write' => "{$default}::write",
        };
        config([
            'pennant.stores.reader' => ['driver' => 'database', 'connection' => $connection($readerRole), 'cache' => ['store' => 'reader']],
            'pennant.stores.writer' => ['driver' => 'database', 'connection' => $connection($writerRole)],
        ]);
        Feature::store('reader')->define('foo', fn () => 'resolved');
        Feature::store('reader')->for(['taylor', 'otwell'])->load('foo');
        Feature::store('writer')->for('taylor')->activate('foo', 'changed');
        Feature::store('reader')->flushCache();
        $queries = $this->countQueries();

        $this->assertSame('resolved', Feature::store('reader')->for('otwell')->value('foo'));
        $this->assertSame(0, $queries->count);
        $this->assertSame('changed', Feature::store('reader')->for('taylor')->value('foo'));
    }

    public static function uncachedWriters(): array
    {
        return [
            'default connection' => [null, null],
            'base reader and write writer' => ['base', 'write'],
            'write reader and base writer' => ['write', 'base'],
        ];
    }

    public function testWritesToEveryScopeInvalidateThePartition(): void
    {
        Feature::for(['taylor', 'otwell'])->load('foo');
        Feature::store('database')->activateForEveryone('foo', 'everyone');
        Feature::flushCache();

        $this->assertSame(['everyone', 'everyone'], Feature::for(['taylor', 'otwell'])->load('foo')['foo']);
    }

    public function testSharedCacheCanBeFlushedAfterRawChangesWithoutDeletingStoredValues(): void
    {
        Feature::for('taylor')->value('foo');
        DB::table('features')->where('name', 'foo')->update(['value' => '"changed"']);
        Feature::store('database')->flushSharedCache();

        // The current request retains its memoized snapshot until explicitly flushed.
        $this->assertSame('resolved', Feature::for('taylor')->value('foo'));
        Feature::flushCache();

        $this->assertSame('changed', Feature::for('taylor')->value('foo'));
        $this->assertDatabaseCount('features', 1);
    }

    public function testUnknownFeatureValuesAreNotCached(): void
    {
        $this->assertFalse(Feature::for('taylor')->value('unknown'));

        Feature::define('unknown', fn () => 'resolved');
        Feature::flushCache();

        $this->assertSame('resolved', Feature::for('taylor')->value('unknown'));
    }

    #[DataProvider('richValues')]
    public function testValuesReadFromTheCacheMatchTheDatabase(mixed $value, mixed $expected): void
    {
        Feature::define('rich', fn () => $value);
        Feature::for('taylor')->value('rich');
        Feature::flushCache();
        $queries = $this->countQueries();

        $this->assertSame($expected, Feature::for('taylor')->value('rich'));
        $this->assertSame(0, $queries->count);
        $this->assertSame($expected, Feature::store('database')->for('taylor')->value('rich'));
    }

    public static function richValues(): array
    {
        return [
            'object' => [(object) ['a' => 1], ['a' => 1]],
            'json serializable' => [new class implements JsonSerializable {
                public function jsonSerialize(): array
                {
                    return ['b' => 2];
                }
            }, ['b' => 2]],
            'false' => [false, false],
            'null' => [null, null],
        ];
    }

    public function testMemoizedValuesTouchNeitherTheDatabaseNorTheCache(): void
    {
        Feature::for('taylor')->value('foo');
        $queries = $this->countQueries();
        $cacheReads = 0;
        Event::listen(RetrievingManyKeys::class, function () use (&$cacheReads) {
            ++$cacheReads;
        });

        $this->assertSame('resolved', Feature::for('taylor')->value('foo'));
        $this->assertSame(0, $queries->count);
        $this->assertSame(0, $cacheReads);
    }

    public function testCachedValuesRunNoQueryAndHoldNoConnection(): void
    {
        Feature::store('pooled')->define('foo', fn () => 'resolved');
        parallel([function () {
            DB::usingConnection('pooled', function () {
                (require __DIR__ . '/../../src/pennant/database/migrations/2022_11_01_000001_create_features_table.php')->up();
            });
            Feature::store('pooled')->for('taylor')->value('foo');
        }]);
        $pool = $this->app->make(PoolManager::class)->pool('pooled');
        $queries = $this->countQueries();

        [$result] = parallel([fn () => [Feature::store('pooled')->for('taylor')->value('foo'), $pool->getIdleCount()]]);

        $this->assertSame(['resolved', 1], $result);
        $this->assertSame(0, $queries->count);
    }

    #[DataProvider('emptyWrites')]
    public function testEmptyWritesRunNoQueryAndHoldNoConnection(string $method): void
    {
        parallel([function () {
            DB::usingConnection('pooled', function () {
                (require __DIR__ . '/../../src/pennant/database/migrations/2022_11_01_000001_create_features_table.php')->up();
            });
        }]);
        $pool = $this->app->make(PoolManager::class)->pool('pooled');
        $queries = $this->countQueries();

        [$idle] = parallel([function () use ($method, $pool) {
            Feature::store('pooled')->{$method}([]);

            return $pool->getIdleCount();
        }]);

        $this->assertSame(1, $idle);
        $this->assertSame(0, $queries->count);
    }

    public static function emptyWrites(): array
    {
        return [
            'purge' => ['purge'],
            'activate' => ['activate'],
        ];
    }

    public function testValuesAreNotCachedWhenNewTokensCantBeStored(): void
    {
        $cache = m::mock(Repository::class);
        $cache->shouldReceive('get')->once()->andReturnUsing(fn (array $keys) => array_fill_keys($keys, null));
        $cache->shouldReceive('put')->once()->andReturnFalse();
        $this->app->make('cache')->extend('mocked', fn () => $cache);
        config([
            'cache.stores.mocked' => ['driver' => 'mocked'],
            'pennant.stores.cached.cache.store' => 'mocked',
        ]);
        Feature::forgetDrivers();
        Feature::define('foo', fn () => 'resolved');

        $this->assertSame('resolved', Feature::for('taylor')->value('foo'));
    }

    #[DefineEnvironment('withFailingInvalidationTarget')]
    public function testWriterReadsTheUpdatedValueAfterAFailedInvalidation(): void
    {
        $this->assertSame('resolved', Feature::store('database')->for('taylor')->value('foo'));

        try {
            Feature::store('database')->for('taylor')->activate('foo', 'changed');
            $this->fail('The invalidation should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to invalidate cached feature values. They may be stale until they expire.', $exception->getMessage());
        }

        $this->assertSame('changed', Feature::store('database')->for('taylor')->value('foo'));
    }

    /**
     * Add a store whose cache can't store invalidation tokens.
     */
    protected function withFailingInvalidationTarget(Application $app): void
    {
        $app->make('config')->set('pennant.stores.broken', [
            'driver' => 'database',
            'cache' => ['store' => 'failing'],
        ]);
    }

    public function testATargetThatCantStoreTokensDoesntStopTheOthers(): void
    {
        $failing = m::mock(Repository::class);
        $failing->shouldReceive('put')->once()->andReturnFalse();
        $working = m::mock(Repository::class);
        $working->shouldReceive('put')->once()->andReturnTrue();

        $this->expectExceptionObject(new RuntimeException('Unable to invalidate cached feature values. They may be stale until they expire.'));

        (new StoredValueCache('features', [[$failing, 60], [$working, 60]]))->rotateScopes('testing', null, ['taylor']);
    }

    public function testATargetThatThrowsDoesntStopTheOthers(): void
    {
        $exception = new RuntimeException('The cache is unavailable.');
        $failing = m::mock(Repository::class);
        $failing->shouldReceive('put')->once()->andThrow($exception);
        $working = m::mock(Repository::class);
        $working->shouldReceive('put')->once()->andReturnTrue();

        try {
            (new StoredValueCache('features', [[$failing, 60], [$working, 60]]))->rotatePartition('testing', null);
            $this->fail('The failing target should have thrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
    }

    #[DataProvider('invalidTtls')]
    public function testCacheTtlMustBeAPositiveInteger(mixed $ttl): void
    {
        config(['pennant.stores.cached.cache.ttl' => $ttl]);
        Feature::forgetDrivers();

        $this->expectExceptionObject(new InvalidArgumentException('The cache TTL of Pennant store [cached] must be a positive integer.'));

        Feature::store('cached');
    }

    public static function invalidTtls(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'float' => [1.5],
            'numeric string' => ['60'],
        ];
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
