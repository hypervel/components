<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Doctor\Diagnostics\CacheStoreIsReachable;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class CacheStoreIsReachableTest extends TestCase
{
    private string $directory;

    /**
     * Create isolated cache and environment directories.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = ParallelTesting::tempDir('DoctorCacheStore');
        File::deleteDirectory($this->directory);
        File::makeDirectory($this->directory . '/cache', 0775, true);
        File::makeDirectory($this->directory . '/database/migrations', 0775, true);
        $this->app->useEnvironmentPath($this->directory);
        $this->app->useDatabasePath($this->directory . '/database');
    }

    /**
     * Remove the diagnostic's files.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->directory);
        } finally {
            parent::tearDown();
        }
    }

    public function testPassesWhenTheDefaultCacheStoreCanBeReached(): void
    {
        config([
            'cache.default' => 'array',
            'cache.stores.array' => ['driver' => 'array'],
        ]);

        $result = (new CacheStoreIsReachable)->check();

        $this->assertSame('pass', $result->status->value);
    }

    public function testLinksToCacheConfigurationWhenTheDefaultStoreCannotBeReached(): void
    {
        config(['cache.default' => 'missing']);

        $result = (new CacheStoreIsReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertEquals([Link::docs('cache', 'configuration')], $result->links);
    }

    public function testOffersWorkingAlternativeStoresWhenTheDefaultStoreIsUnreachableLocally(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $path = $this->directory . '/cache';

        config(['cache' => [
            'default' => 'broken',
            'prefix' => '',
            'stores' => [
                'broken' => ['driver' => 'broken-driver'],
                'file' => ['driver' => 'file', 'path' => $path],
            ],
        ]]);

        $result = (new CacheStoreIsReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('The application cannot reach the default cache store [broken].', $result->summary);
        $this->assertTrue($result->fixable);
        $this->assertSame(['file' => 'File'], $result->fixOptions);
        $this->assertSame('Keep Broken (repair it manually)', $result->fixDeclineLabel);
        $this->assertSame('Which cache store should the application use?', $result->confirmation);
    }

    public function testExcludesCandidatesSharingTheFailingDriverEvenUnderACustomStoreName(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache' => [
                'default' => 'custom',
                'prefix' => '',
                'stores' => [
                    'custom' => ['driver' => 'database', 'table' => 'cache', 'connection' => null],
                    'database' => ['driver' => 'database', 'table' => 'cache', 'connection' => null],
                ],
            ],
        ]);

        $result = (new CacheStoreIsReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertFalse($result->fixable);
        $this->assertSame([], $result->fixOptions);
    }

    public function testLeavesTheFailureNonFixableWhenNoAlternativeStoresAreViable(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        config(['cache' => [
            'default' => 'broken',
            'prefix' => '',
            'stores' => [
                'broken' => ['driver' => 'broken-driver'],
            ],
        ]]);

        $result = (new CacheStoreIsReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertFalse($result->fixable);
        $this->assertSame([], $result->fixOptions);
        $this->assertSame('Check CACHE_STORE and the backing cache service configuration.', $result->remediation);
    }

    public function testOffersTheDatabaseStoreWithASetupLabelWhenTheCacheTablesAreMissing(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache' => [
                'default' => 'broken',
                'prefix' => '',
                'stores' => [
                    'broken' => ['driver' => 'broken-driver'],
                    'database' => ['driver' => 'database', 'table' => 'cache', 'connection' => null],
                ],
            ],
        ]);

        $result = (new CacheStoreIsReachable)->check();

        $this->assertTrue($result->fixable);
        $this->assertSame(['database' => 'Database (creates cache tables and runs all pending migrations)'], $result->fixOptions);
    }

    #[DataProvider('migrationStates')]
    public function testCreatesTheCacheTablesBeforeSwitchingToTheDatabaseStore(bool $alreadyGenerated): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $basePath = $this->directory;
        file_put_contents($basePath . '/.env', "CACHE_STORE=broken\n");

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache' => [
                'default' => 'broken',
                'prefix' => '',
                'stores' => [
                    'broken' => ['driver' => 'broken-driver'],
                    'database' => ['driver' => 'database', 'table' => 'cache', 'connection' => null],
                ],
            ],
        ]);

        $diagnostic = new CacheStoreIsReachable;
        $result = $diagnostic->check();

        $this->assertSame(['database' => 'Database (creates cache tables and runs all pending migrations)'], $result->fixOptions);

        if ($alreadyGenerated) {
            $this->assertSame(0, Artisan::call('make:cache-table'));
        }

        $fix = $diagnostic->fix($result, 'database');

        $this->assertSame('pass', $fix->status->value, $fix->details ?? '');
        $this->assertSame('The default cache store was switched to [database].', $fix->summary);
        $this->assertTrue(Schema::hasTable('cache'));
        $this->assertTrue(Schema::hasTable('cache_locks'));
        $this->assertStringContainsString('CACHE_STORE=database', file_get_contents($basePath . '/.env'));
        $this->assertSame('database', config('cache.default'));
        $this->assertSame('pass', (new CacheStoreIsReachable)->check()->status->value);
    }

    /**
     * Provide generated and ungenerated migration states.
     */
    public static function migrationStates(): array
    {
        return ['new migrations' => [false], 'pending migrations' => [true]];
    }

    public function testSwitchesTheDefaultStoreByWritingTheEnvironmentFile(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $environmentPath = $this->directory;
        $cachePath = $environmentPath . '/cache';

        file_put_contents($environmentPath . '/.env', "CACHE_STORE=broken\n");

        $this->app->useEnvironmentPath($environmentPath);

        config(['cache' => [
            'default' => 'broken',
            'prefix' => '',
            'stores' => [
                'broken' => ['driver' => 'broken-driver'],
                'file' => ['driver' => 'file', 'path' => $cachePath],
            ],
        ]]);

        $diagnostic = new CacheStoreIsReachable;

        $fix = $diagnostic->fix($diagnostic->check(), 'file');

        $this->assertSame('pass', $fix->status->value);
        $this->assertSame('cache-store-is-reachable.fix.switched', $fix->code);
        $this->assertStringContainsString('CACHE_STORE=file', file_get_contents($environmentPath . '/.env'));
        $this->assertSame('file', config('cache.default'));
    }

    public function testFailsTheSwitchWhenTheEnvironmentFileCannotBeUpdated(): void
    {
        $environmentPath = $this->directory;

        $this->app->useEnvironmentPath($environmentPath);

        $fix = (new CacheStoreIsReachable)->fix(DiagnosticResult::fail('The application cannot reach the default cache store.'), 'file');

        $this->assertSame('fail', $fix->status->value);
        $this->assertSame('cache-store-is-reachable.fix.switch-failed', $fix->code);
        $this->assertStringContainsString($environmentPath, $fix->details);
    }

    public function testDoesNotSwitchToAStoreThatFailsItsReProbe(): void
    {
        $environmentPath = $this->directory;
        file_put_contents($environmentPath . '/.env', "CACHE_STORE=broken\n");

        $this->app->useEnvironmentPath($environmentPath);

        config(['cache.stores.file' => ['driver' => 'broken-driver']]);

        $fix = (new CacheStoreIsReachable)->fix(DiagnosticResult::fail('The application cannot reach the default cache store.'), 'file');

        $this->assertSame('fail', $fix->status->value);
        $this->assertSame('cache-store-is-reachable.fix.switch-failed', $fix->code);
        $this->assertStringNotContainsString('CACHE_STORE=file', file_get_contents($environmentPath . '/.env'));
    }

    public function testDoesNotProbeOrOfferAlternativeStoresForProductionFailures(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $path = $this->directory . '/cache';

        config(['cache' => [
            'default' => 'broken',
            'prefix' => '',
            'stores' => [
                'broken' => ['driver' => 'broken-driver'],
                'file' => ['driver' => 'file', 'path' => $path],
            ],
        ]]);

        $result = (new CacheStoreIsReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertFalse($result->fixable);
        $this->assertSame([], $result->fixOptions);
        $this->assertSame('Check CACHE_STORE and the backing cache service configuration.', $result->remediation);
    }

    #[DataProvider('probeFailures')]
    public function testRejectsFailedRoundTrips(string $mode, string $detail): void
    {
        config(['cache.default' => 'probe', 'cache.stores.probe' => ['driver' => 'probe']]);
        Cache::extend('probe', fn (): Repository => new Repository(new DoctorProbeStore($mode)));

        $result = (new CacheStoreIsReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString($detail, $result->details);
    }

    /**
     * Provide meaningful failures in each part of the round trip.
     */
    public static function probeFailures(): array
    {
        return [
            'write rejected' => ['false-write', 'did not accept'],
            'wrong value' => ['wrong-read', 'did not return'],
            'cleanup rejected' => ['false-cleanup', 'could not be removed'],
            'cleanup throws' => ['cleanup-throws', 'cleanup failed'],
        ];
    }

    #[DataProvider('probeExceptions')]
    public function testCleanupPreservesPrimaryFailuresAndCancellation(bool $primaryCanceled, bool $cleanupCanceled): void
    {
        $primary = $primaryCanceled ? new CanceledException('primary canceled') : new RuntimeException('primary failed');
        $cleanup = $cleanupCanceled ? new CanceledException('cleanup canceled') : new RuntimeException('cleanup failed');
        $store = m::mock(Store::class);
        $store->expects('put')->andThrow($primary);
        $store->expects('forget')->andThrow($cleanup);
        Cache::extend('probe', fn (): Repository => new Repository($store));
        config(['cache.default' => 'probe', 'cache.stores.probe' => ['driver' => 'probe']]);
        $caught = null;
        $result = null;

        try {
            $result = (new CacheStoreIsReachable)->check();
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        if ($primaryCanceled || $cleanupCanceled) {
            $this->assertSame($primaryCanceled ? $primary : $cleanup, $caught);
        } else {
            $this->assertNull($caught);
            $this->assertSame('fail', $result->status->value);
            $this->assertSame('primary failed', $result->details);
        }
    }

    /**
     * Provide exception precedence cases.
     */
    public static function probeExceptions(): array
    {
        return [[false, false], [true, false], [false, true], [true, true]];
    }

    public function testNullStoreIsSkippedAndSessionStoreCanRoundTrip(): void
    {
        config(['cache.default' => 'null']);
        $this->assertSame('skip', (new CacheStoreIsReachable)->check()->status->value);

        config(['cache.default' => 'session', 'session.driver' => 'array']);
        $this->assertSame('pass', (new CacheStoreIsReachable)->check()->status->value);
    }

    #[DataProvider('wrapperConfigurations')]
    public function testChecksActualWrapperOperationsAndFailoverCapacity(array $stores, string $expected, bool $unconfirmedCleanup = false): void
    {
        config(['cache.default' => 'selected', 'cache.stores' => $stores]);
        Cache::extend('false-write', fn (): Repository => new Repository(new DoctorProbeStore('false-write')));

        $result = (new CacheStoreIsReachable)->check();

        $this->assertSame($expected, $result->status->value, $result->details ?? '');

        if ($unconfirmedCleanup) {
            $this->assertStringContainsString('Cleanup could not be confirmed', $result->details);
        }
    }

    /**
     * Provide supported wrappers and meaningful degraded configurations.
     */
    public static function wrapperConfigurations(): array
    {
        $failover = ['driver' => 'failover', 'stores' => ['first', 'second']];
        $healthy = ['driver' => 'array'];
        $unreachable = ['driver' => 'unreachable'];
        $falseWrite = ['driver' => 'false-write'];

        return [
            'healthy failover' => [['selected' => $failover, 'first' => $healthy, 'second' => $healthy], 'pass'],
            'null backup' => [[
                'selected' => ['driver' => 'failover', 'stores' => ['first', 'null']],
                'first' => $healthy,
            ], 'pass'],
            'named null backup' => [['selected' => $failover, 'first' => $healthy, 'second' => ['driver' => 'null']], 'pass'],
            'null primary cannot retain values' => [['selected' => $failover, 'first' => ['driver' => 'null'], 'second' => $healthy], 'fail'],
            'dead primary' => [['selected' => $failover, 'first' => $unreachable, 'second' => $healthy], 'warn', true],
            'dead backup' => [['selected' => $failover, 'first' => $healthy, 'second' => $unreachable], 'warn', true],
            'all unreachable' => [['selected' => $failover, 'first' => $unreachable, 'second' => $unreachable], 'fail'],
            'false writing primary' => [['selected' => $failover, 'first' => $falseWrite, 'second' => $healthy], 'fail'],
            'false writing backup' => [['selected' => $failover, 'first' => $healthy, 'second' => $falseWrite], 'warn'],
            'numeric stack' => [[
                'selected' => ['driver' => 'stack', 'stores' => ['first', 'second']],
                'first' => $healthy, 'second' => $healthy,
            ], 'pass'],
            'named stack' => [[
                'selected' => ['driver' => 'stack', 'stores' => ['first' => ['ttl' => 20], 'second' => []]],
                'first' => $healthy, 'second' => $healthy,
            ], 'pass'],
            'stack with degraded failover' => [[
                'selected' => ['driver' => 'stack', 'stores' => ['memory', 'fallback']],
                'memory' => $healthy, 'fallback' => $failover, 'first' => $unreachable, 'second' => $healthy,
            ], 'warn', true],
            'failover with stack child' => [[
                'selected' => $failover,
                'first' => ['driver' => 'stack', 'stores' => ['memory', 'disk']],
                'memory' => $healthy, 'disk' => $healthy, 'second' => $healthy,
            ], 'pass'],
            'empty failover' => [['selected' => ['driver' => 'failover', 'stores' => []]], 'fail'],
            'cycle' => [[
                'selected' => ['driver' => 'stack', 'stores' => ['nested']],
                'nested' => ['driver' => 'failover', 'stores' => ['selected']],
            ], 'fail'],
        ];
    }

    public function testMissingLockTableUsesTheMigrationSetupLabel(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'broken',
            'cache.stores' => [
                'broken' => ['driver' => 'broken-driver'],
                'database' => ['driver' => 'database', 'table' => 'cache'],
            ],
        ]);
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value');
            $table->integer('expiration');
        });

        $result = (new CacheStoreIsReachable)->check();

        $this->assertSame(['database' => 'Database (creates cache tables and runs all pending migrations)'], $result->fixOptions);
    }

    #[DataProvider('databaseCreationConfigurations')]
    public function testOnlyOffersTableCreationForTheGeneratorLayout(array $overrides, bool $eligible): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'broken',
            'cache.stores' => [
                'broken' => ['driver' => 'broken-driver'],
                'database' => [...['driver' => 'database', 'table' => 'cache'], ...$overrides],
            ],
        ]);

        $result = (new CacheStoreIsReachable)->check();

        $this->assertSame($eligible, array_key_exists('database', $result->fixOptions));
    }

    /**
     * Provide supported defaults and layouts the generator cannot create.
     */
    public static function databaseCreationConfigurations(): array
    {
        return [
            'omitted optional values' => [[], true],
            'null optional values' => [['connection' => null, 'lock_table' => null, 'lock_connection' => null], true],
            'explicit default connection' => [['connection' => 'sqlite', 'lock_connection' => 'sqlite'], true],
            'different data table' => [['table' => 'custom_cache'], false],
            'different lock table' => [['lock_table' => 'custom_locks'], false],
            'different data connection' => [['connection' => 'other'], false],
            'different lock connection' => [['lock_connection' => 'other'], false],
            'missing required table' => [['table' => null], false],
            'different configured driver' => [['driver' => 'unavailable'], false],
        ];
    }

    public function testRepeatedFailoverChildrenHaveOneFailureDetail(): void
    {
        config([
            'cache.default' => 'selected',
            'cache.stores' => [
                'selected' => ['driver' => 'failover', 'stores' => ['healthy', 'offline', 'offline']],
                'healthy' => ['driver' => 'array'],
                'offline' => ['driver' => 'unavailable'],
            ],
        ]);

        $result = (new CacheStoreIsReachable)->check();

        $this->assertSame('warn', $result->status->value);
        $this->assertSame(1, substr_count($result->details, '[offline]:'));
    }
}

class DoctorProbeStore extends ArrayStore
{
    /**
     * Create a store with one failing round-trip operation.
     */
    public function __construct(private string $mode)
    {
        parent::__construct();
    }

    /**
     * Store the value unless the write is rejected.
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        return $this->mode !== 'false-write' && parent::put($key, $value, $seconds);
    }

    /**
     * Read the value or simulate a mismatched response.
     */
    public function get(string $key): mixed
    {
        return $this->mode === 'wrong-read' ? 'wrong' : parent::get($key);
    }

    /**
     * Remove the value unless cleanup fails.
     */
    public function forget(string $key): bool
    {
        return match ($this->mode) {
            'false-cleanup' => false,
            'cleanup-throws' => throw new RuntimeException('cleanup failed'),
            default => parent::forget($key),
        };
    }
}
