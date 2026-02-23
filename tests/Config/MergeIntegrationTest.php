<?php

declare(strict_types=1);

namespace Hypervel\Tests\Config;

use Hypervel\Config\ProviderConfig;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Integration tests for config merging using real fixture files.
 *
 * These tests verify that ProviderConfig::merge() correctly handles
 * all the patterns found in real Hyperf/Hypervel configs:
 *
 * - Nested associative arrays with scalar overrides (database connections)
 * - Mixed arrays with priority listeners (numeric + string keys)
 * - Pure lists with deduplication (commands)
 * - Deep nesting with type preservation (floats, ints, bools)
 *
 * The fixtures in base/, override/, and expected/ directories represent
 * realistic config structures without env() dependencies.
 *
 * @internal
 * @coversNothing
 */
class MergeIntegrationTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/fixtures';

    private const CONFIG_KEYS = [
        'database',
        'listeners',
        'commands',
        'cache',
        'app',
    ];

    /**
     * Test that merging all fixture configs produces the expected results.
     *
     * This is the main integration test that verifies the complete merge
     * behavior across all config types.
     */
    public function testMergeAllConfigsMatchesExpected(): void
    {
        $base = $this->loadConfigsFromDir(self::FIXTURES_DIR . '/base');
        $override = $this->loadConfigsFromDir(self::FIXTURES_DIR . '/override');
        $expected = $this->loadConfigsFromDir(self::FIXTURES_DIR . '/expected');

        // Merge base and override using ProviderConfig::merge()
        $merged = $this->callMerge($base, $override);

        foreach (self::CONFIG_KEYS as $key) {
            $this->assertArrayHasKey($key, $merged, "Merged config should have key: {$key}");
            $this->assertArrayHasKey($key, $expected, "Expected config should have key: {$key}");

            $this->assertSame(
                $expected[$key],
                $merged[$key],
                "Config '{$key}' should match expected after merge"
            );
        }
    }

    /**
     * Test database config merge - nested associative arrays with scalar overrides.
     *
     * Verifies:
     * - Scalar values in nested arrays are overridden (not converted to arrays)
     * - New nested keys are added
     * - Existing nested keys are preserved when not overridden
     */
    public function testDatabaseConfigMerge(): void
    {
        $base = ['database' => require self::FIXTURES_DIR . '/base/database.php'];
        $override = ['database' => require self::FIXTURES_DIR . '/override/database.php'];

        $merged = $this->callMerge($base, $override);

        // Critical: driver must remain a string, not become an array
        $this->assertIsString(
            $merged['database']['connections']['pgsql']['driver'],
            'pgsql driver should be a string, not an array'
        );
        $this->assertSame('pgsql', $merged['database']['connections']['pgsql']['driver']);

        // Host should be overridden
        $this->assertSame('db.example.com', $merged['database']['connections']['pgsql']['host']);

        // Pool should be added from override
        $this->assertArrayHasKey('pool', $merged['database']['connections']['pgsql']);
        $this->assertSame(10.0, $merged['database']['connections']['pgsql']['pool']['connect_timeout']);

        // MySQL should be preserved from base
        $this->assertSame('mysql', $merged['database']['connections']['mysql']['driver']);

        // SQLite should be added from override
        $this->assertArrayHasKey('sqlite', $merged['database']['connections']);
        $this->assertSame('sqlite', $merged['database']['connections']['sqlite']['driver']);
    }

    /**
     * Test listeners config merge - THE CRITICAL TEST.
     *
     * This tests the mixed array pattern where:
     * - Numeric keys are regular listeners (appended)
     * - String keys are listeners with priority values (must be preserved)
     *
     * This was the original bug: Arr::merge lost the string keys.
     */
    public function testListenersConfigMergePreservesPriorityKeys(): void
    {
        $base = ['listeners' => require self::FIXTURES_DIR . '/base/listeners.php'];
        $override = ['listeners' => require self::FIXTURES_DIR . '/override/listeners.php'];

        $merged = $this->callMerge($base, $override);

        // All numeric-keyed listeners should be present
        $this->assertContains('App\Listeners\EventLoggerListener', $merged['listeners']);
        $this->assertContains('App\Listeners\AuditListener', $merged['listeners']);
        $this->assertContains('Hyperf\Command\Listener\RegisterCommandListener', $merged['listeners']);
        $this->assertContains('Hyperf\ModelListener\Listener\ModelEventListener', $merged['listeners']);
        $this->assertContains('Hypervel\ServerProcess\Listeners\BootProcessListener', $merged['listeners']);

        // Priority listeners MUST have their string keys preserved
        $this->assertArrayHasKey(
            'Hyperf\ModelListener\Listener\ModelHookEventListener',
            $merged['listeners'],
            'ModelHookEventListener string key must be preserved'
        );
        $this->assertSame(
            99,
            $merged['listeners']['Hyperf\ModelListener\Listener\ModelHookEventListener'],
            'ModelHookEventListener priority must be 99'
        );

        $this->assertArrayHasKey(
            'Hyperf\Signal\Listener\SignalRegisterListener',
            $merged['listeners'],
            'SignalRegisterListener string key must be preserved'
        );
        $this->assertSame(
            PHP_INT_MAX,
            $merged['listeners']['Hyperf\Signal\Listener\SignalRegisterListener'],
            'SignalRegisterListener priority must be PHP_INT_MAX'
        );

        // Priority values should NOT appear as standalone numeric entries
        $numericValues = array_values(array_filter(
            $merged['listeners'],
            fn ($v, $k) => is_int($k),
            ARRAY_FILTER_USE_BOTH
        ));
        $this->assertNotContains(99, $numericValues, 'Priority 99 should not be a standalone entry');
        $this->assertNotContains(PHP_INT_MAX, $numericValues, 'Priority PHP_INT_MAX should not be a standalone entry');
    }

    /**
     * Test commands config merge - pure list with deduplication.
     */
    public function testCommandsConfigMergeDeduplicates(): void
    {
        $base = ['commands' => require self::FIXTURES_DIR . '/base/commands.php'];
        $override = ['commands' => require self::FIXTURES_DIR . '/override/commands.php'];

        $merged = $this->callMerge($base, $override);

        // All unique commands should be present
        $this->assertContains('App\Commands\MigrateCommand', $merged['commands']);
        $this->assertContains('App\Commands\SeedCommand', $merged['commands']);
        $this->assertContains('App\Commands\CacheCommand', $merged['commands']);
        $this->assertContains('App\Commands\QueueCommand', $merged['commands']);
        $this->assertContains('App\Commands\ScheduleCommand', $merged['commands']);

        // CacheCommand should appear only once (deduplicated)
        $cacheCommandCount = count(array_filter(
            $merged['commands'],
            fn ($cmd) => $cmd === 'App\Commands\CacheCommand'
        ));
        $this->assertSame(1, $cacheCommandCount, 'CacheCommand should appear exactly once');

        // Total should be 5 (3 from base + 2 new from override, 1 duplicate skipped)
        $this->assertCount(5, $merged['commands']);
    }

    /**
     * Test cache config merge - deep nesting with type preservation.
     */
    public function testCacheConfigMergePreservesTypes(): void
    {
        $base = ['cache' => require self::FIXTURES_DIR . '/base/cache.php'];
        $override = ['cache' => require self::FIXTURES_DIR . '/override/cache.php'];

        $merged = $this->callMerge($base, $override);

        // Floats must remain floats
        $this->assertIsFloat($merged['cache']['stores']['swoole']['memory_limit_buffer']);
        $this->assertSame(0.05, $merged['cache']['stores']['swoole']['memory_limit_buffer']);

        $this->assertIsFloat($merged['cache']['stores']['swoole']['eviction_proportion']);
        $this->assertSame(0.05, $merged['cache']['stores']['swoole']['eviction_proportion']);

        $this->assertIsFloat($merged['cache']['swoole_tables']['default']['conflict_proportion']);
        $this->assertSame(0.2, $merged['cache']['swoole_tables']['default']['conflict_proportion']);

        // Integers must remain integers and be overridden correctly
        $this->assertIsInt($merged['cache']['stores']['swoole']['eviction_interval']);
        $this->assertSame(5000, $merged['cache']['stores']['swoole']['eviction_interval']); // Overridden

        $this->assertIsInt($merged['cache']['swoole_tables']['default']['rows']);
        $this->assertSame(2048, $merged['cache']['swoole_tables']['default']['rows']); // Overridden

        // Boolean must remain boolean
        $this->assertIsBool($merged['cache']['stores']['array']['serialize']);
        $this->assertFalse($merged['cache']['stores']['array']['serialize']);

        // New store should be added
        $this->assertArrayHasKey('database', $merged['cache']['stores']);
        $this->assertSame('database', $merged['cache']['stores']['database']['driver']);

        // Numeric array inside should be preserved
        $this->assertSame([2, 100], $merged['cache']['stores']['database']['lock_lottery']);
    }

    /**
     * Test app config merge - scalar overrides and list appending.
     */
    public function testAppConfigMergeScalarsAndLists(): void
    {
        $base = ['app' => require self::FIXTURES_DIR . '/base/app.php'];
        $override = ['app' => require self::FIXTURES_DIR . '/override/app.php'];

        $merged = $this->callMerge($base, $override);

        // Strings should be overridden
        $this->assertSame('OverrideApp', $merged['app']['name']);
        $this->assertSame('local', $merged['app']['env']);

        // Boolean should be overridden
        $this->assertTrue($merged['app']['debug']);

        // Null should be overridden with value
        $this->assertSame('base64:testkey123', $merged['app']['key']);

        // New key should be added
        $this->assertArrayHasKey('new_setting', $merged['app']);
        $this->assertSame('new_value', $merged['app']['new_setting']);

        // Preserved values should remain
        $this->assertSame('http://localhost', $merged['app']['url']);
        $this->assertSame('UTC', $merged['app']['timezone']);

        // Providers should be combined (not replaced)
        $this->assertContains('App\Providers\AppServiceProvider', $merged['app']['providers']);
        $this->assertContains('App\Providers\EventServiceProvider', $merged['app']['providers']);
        $this->assertContains('App\Providers\RouteServiceProvider', $merged['app']['providers']);
        $this->assertCount(3, $merged['app']['providers']);
    }

    /**
     * Load all PHP config files from a directory into a single array.
     *
     * @return array<string, mixed>
     */
    private function loadConfigsFromDir(string $dir): array
    {
        $configs = [];
        $files = glob($dir . '/*.php');

        foreach ($files as $file) {
            $key = basename($file, '.php');
            $configs[$key] = require $file;
        }

        return $configs;
    }

    /**
     * Call ProviderConfig::merge() via reflection.
     *
     * @return array<string, mixed>
     */
    private function callMerge(array ...$arrays): array
    {
        $method = new ReflectionMethod(ProviderConfig::class, 'merge');

        return $method->invoke(null, ...$arrays);
    }
}
