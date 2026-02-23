<?php

declare(strict_types=1);

namespace Hypervel\Tests\Config;

use Hyperf\Di\Definition\PriorityDefinition;
use Hypervel\Config\ProviderConfig;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class ProviderConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProviderConfig::clear();
    }

    protected function tearDown(): void
    {
        ProviderConfig::clear();
        parent::tearDown();
    }

    /**
     * Test that merging configs with duplicate scalar keys preserves scalar values.
     *
     * This is a regression test for the issue where array_merge_recursive was
     * converting scalar values with the same key into arrays. For example:
     *   Config A: ['database' => ['driver' => 'pgsql']]
     *   Config B: ['database' => ['driver' => 'mysql']]
     *   Expected: ['database' => ['driver' => 'mysql']]
     *   Bug:      ['database' => ['driver' => ['pgsql', 'mysql']]]
     */
    public function testMergePreservesScalarValuesWithDuplicateKeys(): void
    {
        $configA = [
            'database' => [
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => [
                        'driver' => 'sqlite',
                        'database' => '/path/to/database.sqlite',
                    ],
                ],
            ],
        ];

        $configB = [
            'database' => [
                'connections' => [
                    'sqlite' => [
                        'driver' => 'sqlite',
                        'foreign_key_constraints' => true,
                    ],
                ],
            ],
        ];

        $result = $this->callMerge($configA, $configB);

        // The driver should remain a string, not become an array
        $this->assertIsString(
            $result['database']['connections']['sqlite']['driver'],
            'Driver should be a string, not an array. This indicates array_merge_recursive is incorrectly converting duplicate scalar keys into arrays.'
        );
        $this->assertSame('sqlite', $result['database']['connections']['sqlite']['driver']);

        // Later config values should override earlier ones
        $this->assertSame('sqlite', $result['database']['default']);

        // Both unique keys should be present
        $this->assertSame('/path/to/database.sqlite', $result['database']['connections']['sqlite']['database']);
        $this->assertTrue($result['database']['connections']['sqlite']['foreign_key_constraints']);
    }

    /**
     * Test that merging three configs still preserves scalar values.
     */
    public function testMergeThreeConfigsPreservesScalarValues(): void
    {
        $configA = [
            'app' => ['name' => 'First', 'debug' => false],
        ];

        $configB = [
            'app' => ['name' => 'Second', 'timezone' => 'UTC'],
        ];

        $configC = [
            'app' => ['name' => 'Third', 'debug' => true],
        ];

        $result = $this->callMerge($configA, $configB, $configC);

        // name should be the last value, not an array of all values
        $this->assertIsString($result['app']['name']);
        $this->assertSame('Third', $result['app']['name']);

        // debug should be the last value
        $this->assertIsBool($result['app']['debug']);
        $this->assertTrue($result['app']['debug']);

        // timezone from middle config should be preserved
        $this->assertSame('UTC', $result['app']['timezone']);
    }

    /**
     * Test that numeric arrays are combined (appended), not replaced.
     *
     * Commands, listeners, etc. use numeric arrays and should be combined
     * from all provider configs, not replaced.
     */
    public function testMergeCombinesNumericArrays(): void
    {
        $configA = [
            'commands' => ['CommandA', 'CommandB'],
        ];

        $configB = [
            'commands' => ['CommandC'],
        ];

        $result = $this->callMerge($configA, $configB);

        // Numeric arrays should be combined (all commands from all packages)
        $this->assertSame(['CommandA', 'CommandB', 'CommandC'], $result['commands']);
    }

    /**
     * Test that listeners with priority values preserve both the class and priority.
     *
     * Hyperf's listener config uses a mixed pattern where:
     * - Simple listeners are numeric-keyed: ['ListenerA', 'ListenerB']
     * - Listeners with priority use string keys: ['PriorityListener' => 99]
     */
    public function testMergePreservesListenersWithPriority(): void
    {
        $configA = [
            'listeners' => [
                'App\Listeners\ListenerA',
                'App\Listeners\ListenerB',
            ],
        ];

        $configB = [
            'listeners' => [
                'App\Listeners\ModelEventListener',
                'App\Listeners\ModelHookEventListener' => 99,
            ],
        ];

        $result = $this->callMerge($configA, $configB);

        // All simple listeners should be present
        $this->assertContains('App\Listeners\ListenerA', $result['listeners']);
        $this->assertContains('App\Listeners\ListenerB', $result['listeners']);
        $this->assertContains('App\Listeners\ModelEventListener', $result['listeners']);

        // Priority listener should have its string key preserved with the priority value
        $this->assertArrayHasKey(
            'App\Listeners\ModelHookEventListener',
            $result['listeners'],
            'Priority listener class name should be preserved as a string key'
        );
        $this->assertSame(
            99,
            $result['listeners']['App\Listeners\ModelHookEventListener'],
            'Priority value should be preserved'
        );

        // The priority value (99) should NOT appear as a standalone numeric entry
        $numericValues = array_filter($result['listeners'], fn ($v, $k) => is_int($k), ARRAY_FILTER_USE_BOTH);
        $this->assertNotContains(
            99,
            $numericValues,
            'Priority value should not be a standalone entry - this indicates the string key was lost'
        );
    }

    /**
     * Test that merging empty arrays returns an empty array.
     */
    public function testMergeWithNoArraysReturnsEmpty(): void
    {
        $result = $this->callMerge();

        $this->assertSame([], $result);
    }

    /**
     * Test that merging a single array returns it unchanged.
     */
    public function testMergeSingleArrayReturnsUnchanged(): void
    {
        $config = [
            'app' => ['name' => 'MyApp', 'debug' => true],
            'commands' => ['CommandA', 'CommandB'],
        ];

        $result = $this->callMerge($config);

        $this->assertSame($config, $result);
    }

    /**
     * Test deeply nested config structures are merged correctly.
     */
    public function testMergeDeeplyNestedConfigs(): void
    {
        $configA = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => [
                            'value' => 'original',
                            'only_in_a' => 'a_value',
                        ],
                    ],
                ],
            ],
        ];

        $configB = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => [
                            'value' => 'overridden',
                            'only_in_b' => 'b_value',
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->callMerge($configA, $configB);

        // Scalar at deep level should be overridden
        $this->assertSame('overridden', $result['level1']['level2']['level3']['level4']['value']);

        // Unique keys from both should be present
        $this->assertSame('a_value', $result['level1']['level2']['level3']['level4']['only_in_a']);
        $this->assertSame('b_value', $result['level1']['level2']['level3']['level4']['only_in_b']);
    }

    /**
     * Test that null values are handled correctly (replaced like any scalar).
     */
    public function testMergeHandlesNullValues(): void
    {
        $configA = [
            'app' => ['value' => 'not_null', 'nullable' => null],
        ];

        $configB = [
            'app' => ['value' => null, 'nullable' => 'now_has_value'],
        ];

        $result = $this->callMerge($configA, $configB);

        // Later null should override earlier value
        $this->assertNull($result['app']['value']);

        // Later value should override earlier null
        $this->assertSame('now_has_value', $result['app']['nullable']);
    }

    /**
     * Test arrays with mixed numeric and string keys.
     *
     * Numeric keys are appended (with deduplication), string keys are replaced.
     * This matches Hyperf's listener pattern: ['ListenerA', 'PriorityListener' => 99]
     */
    public function testMergeMixedNumericAndStringKeys(): void
    {
        $configA = [
            'mixed' => [
                'numeric_0',
                'string_key' => 'value_a',
                'numeric_1',
            ],
        ];

        $configB = [
            'mixed' => [
                'another_numeric',
                'string_key' => 'value_b',
            ],
        ];

        $result = $this->callMerge($configA, $configB);

        // String key should be replaced
        $this->assertSame('value_b', $result['mixed']['string_key']);

        // Numeric-keyed values should be appended
        $this->assertContains('numeric_0', $result['mixed']);
        $this->assertContains('numeric_1', $result['mixed']);
        $this->assertContains('another_numeric', $result['mixed']);

        // Should have 4 entries: 3 numeric + 1 string key
        $this->assertCount(4, $result['mixed']);
    }

    /**
     * Test that scalar value replaces array value (later scalar wins).
     */
    public function testMergeScalarReplacesArray(): void
    {
        $configA = [
            'setting' => ['complex' => 'array', 'with' => 'values'],
        ];

        $configB = [
            'setting' => 'simple_string',
        ];

        $result = $this->callMerge($configA, $configB);

        // Scalar should completely replace array
        $this->assertIsString($result['setting']);
        $this->assertSame('simple_string', $result['setting']);
    }

    /**
     * Test that duplicate values in numeric arrays are deduplicated.
     *
     *  The merge logic deduplicates by default, which prevents listeners
     * from firing twice when multiple providers include the same class.
     */
    public function testMergeDeduplicatesNumericArrays(): void
    {
        $configA = [
            'listeners' => ['ListenerA', 'SharedListener'],
        ];

        $configB = [
            'listeners' => ['SharedListener', 'ListenerB'],
        ];

        $result = $this->callMerge($configA, $configB);

        // SharedListener should only appear once (deduplicated)
        $this->assertSame(
            ['ListenerA', 'SharedListener', 'ListenerB'],
            $result['listeners']
        );
    }

    /**
     * Test that numeric string keys are converted to integers by PHP.
     *
     * PHP automatically converts numeric string keys like '80' to integers.
     * This means they're treated as numeric keys (appended with deduplication).
     * This is PHP behavior, not something we can change.
     */
    public function testMergeNumericStringKeysAreConvertedToIntegers(): void
    {
        $configA = [
            'ports' => [
                '80' => 'http',
                '443' => 'https',
            ],
        ];

        $configB = [
            'ports' => [
                '80' => 'http_updated',
                '8080' => 'alt_http',
            ],
        ];

        $result = $this->callMerge($configA, $configB);

        // PHP converts '80' to int 80, so these are numeric keys
        // Numeric keys append with deduplication
        // 'http' and 'http_updated' are different values, so both are kept
        $this->assertContains('http', $result['ports']);
        $this->assertContains('https', $result['ports']);
        $this->assertContains('http_updated', $result['ports']);
        $this->assertContains('alt_http', $result['ports']);
    }

    /**
     * Test nested numeric arrays within associative structures.
     */
    public function testMergeNestedNumericArraysWithinAssociative(): void
    {
        $configA = [
            'annotations' => [
                'scan' => [
                    'paths' => ['/path/a', '/path/b'],
                    'collectors' => ['CollectorA'],
                ],
            ],
        ];

        $configB = [
            'annotations' => [
                'scan' => [
                    'paths' => ['/path/c'],
                    'ignore_annotations' => ['IgnoreMe'],
                ],
            ],
        ];

        $result = $this->callMerge($configA, $configB);

        // Numeric arrays should be combined
        $this->assertSame(['/path/a', '/path/b', '/path/c'], $result['annotations']['scan']['paths']);
        $this->assertSame(['CollectorA'], $result['annotations']['scan']['collectors']);
        $this->assertSame(['IgnoreMe'], $result['annotations']['scan']['ignore_annotations']);
    }

    /**
     * Test boolean values are preserved correctly.
     */
    public function testMergePreservesBooleanTypes(): void
    {
        $configA = [
            'flags' => ['enabled' => true, 'verbose' => false],
        ];

        $configB = [
            'flags' => ['enabled' => false, 'debug' => true],
        ];

        $result = $this->callMerge($configA, $configB);

        $this->assertFalse($result['flags']['enabled']);
        $this->assertFalse($result['flags']['verbose']);
        $this->assertTrue($result['flags']['debug']);

        // Ensure they're actual booleans, not arrays
        $this->assertIsBool($result['flags']['enabled']);
        $this->assertIsBool($result['flags']['verbose']);
        $this->assertIsBool($result['flags']['debug']);
    }

    /**
     * Test integer values are preserved correctly.
     */
    public function testMergePreservesIntegerTypes(): void
    {
        $configA = [
            'limits' => ['timeout' => 30, 'retries' => 3],
        ];

        $configB = [
            'limits' => ['timeout' => 60, 'max_connections' => 100],
        ];

        $result = $this->callMerge($configA, $configB);

        $this->assertSame(60, $result['limits']['timeout']);
        $this->assertSame(3, $result['limits']['retries']);
        $this->assertSame(100, $result['limits']['max_connections']);

        // Ensure they're actual integers, not arrays
        $this->assertIsInt($result['limits']['timeout']);
        $this->assertIsInt($result['limits']['retries']);
        $this->assertIsInt($result['limits']['max_connections']);
    }

    /**
     * Test the real-world database config scenario that originally caused the bug.
     */
    public function testMergeRealWorldDatabaseConfigScenario(): void
    {
        // Simulates CoreServiceProvider config
        $coreConfig = [
            'database' => [
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => [
                        'driver' => 'sqlite',
                        'database' => '/app/database.sqlite',
                        'prefix' => '',
                    ],
                    'pgsql' => [
                        'driver' => 'pgsql',
                        'host' => 'localhost',
                        'port' => 5432,
                    ],
                ],
            ],
        ];

        // Simulates DatabaseServiceProvider config (overlapping sqlite config)
        $databaseConfig = [
            'database' => [
                'connections' => [
                    'sqlite' => [
                        'driver' => 'sqlite',
                        'foreign_key_constraints' => true,
                    ],
                ],
            ],
        ];

        // Simulates another package adding a mysql connection
        $mysqlConfig = [
            'database' => [
                'connections' => [
                    'mysql' => [
                        'driver' => 'mysql',
                        'host' => 'localhost',
                        'port' => 3306,
                    ],
                ],
            ],
        ];

        $result = $this->callMerge($coreConfig, $databaseConfig, $mysqlConfig);

        // All drivers must be strings, not arrays
        $this->assertIsString($result['database']['connections']['sqlite']['driver']);
        $this->assertIsString($result['database']['connections']['pgsql']['driver']);
        $this->assertIsString($result['database']['connections']['mysql']['driver']);

        $this->assertSame('sqlite', $result['database']['connections']['sqlite']['driver']);
        $this->assertSame('pgsql', $result['database']['connections']['pgsql']['driver']);
        $this->assertSame('mysql', $result['database']['connections']['mysql']['driver']);

        // All unique keys should be present
        $this->assertSame('/app/database.sqlite', $result['database']['connections']['sqlite']['database']);
        $this->assertTrue($result['database']['connections']['sqlite']['foreign_key_constraints']);
        $this->assertSame(5432, $result['database']['connections']['pgsql']['port']);
        $this->assertSame(3306, $result['database']['connections']['mysql']['port']);
    }

    /**
     * Test that PriorityDefinition objects are merged correctly.
     *
     * When multiple providers define the same dependency using PriorityDefinition,
     * the definitions should be merged and the highest priority implementation wins.
     */
    public function testMergeDependenciesWithPriorityDefinition(): void
    {
        $configA = [
            'dependencies' => [
                'SomeInterface' => new PriorityDefinition('ImplementationA', 5),
            ],
        ];

        $configB = [
            'dependencies' => [
                'SomeInterface' => new PriorityDefinition('ImplementationB', 10),
            ],
        ];

        $result = $this->callMerge($configA, $configB);

        // The result should be a PriorityDefinition
        $this->assertArrayHasKey('SomeInterface', $result['dependencies']);
        $definition = $result['dependencies']['SomeInterface'];
        $this->assertInstanceOf(PriorityDefinition::class, $definition);

        // Higher priority (10) should win when getting the definition
        $this->assertSame('ImplementationB', $definition->getDefinition());

        // Both implementations should be tracked in the definition
        $dependencies = $definition->getDependencies();
        $this->assertArrayHasKey('ImplementationA', $dependencies);
        $this->assertArrayHasKey('ImplementationB', $dependencies);
        $this->assertSame(5, $dependencies['ImplementationA']);
        $this->assertSame(10, $dependencies['ImplementationB']);
    }

    /**
     * Test merging three configs with PriorityDefinition.
     *
     * The implementation with the highest priority should win regardless of order.
     */
    public function testMergeThreeConfigsWithPriorityDefinition(): void
    {
        $configA = [
            'dependencies' => [
                'CacheInterface' => new PriorityDefinition('RedisCache', 10),
            ],
        ];

        $configB = [
            'dependencies' => [
                'CacheInterface' => new PriorityDefinition('MemoryCache', 5),
            ],
        ];

        $configC = [
            'dependencies' => [
                'CacheInterface' => new PriorityDefinition('FileCache', 15),
            ],
        ];

        $result = $this->callMerge($configA, $configB, $configC);

        $definition = $result['dependencies']['CacheInterface'];
        $this->assertInstanceOf(PriorityDefinition::class, $definition);

        // FileCache has highest priority (15), should win
        $this->assertSame('FileCache', $definition->getDefinition());

        // All three should be tracked
        $dependencies = $definition->getDependencies();
        $this->assertCount(3, $dependencies);
        $this->assertSame(10, $dependencies['RedisCache']);
        $this->assertSame(5, $dependencies['MemoryCache']);
        $this->assertSame(15, $dependencies['FileCache']);
    }

    /**
     * Test that plain dependency value followed by PriorityDefinition works.
     *
     * When a plain value is defined first and then a PriorityDefinition,
     * the PriorityDefinition should take over.
     */
    public function testMergePlainDependencyThenPriorityDefinition(): void
    {
        $configA = [
            'dependencies' => [
                'LoggerInterface' => 'FileLogger',
            ],
        ];

        $configB = [
            'dependencies' => [
                'LoggerInterface' => new PriorityDefinition('DatabaseLogger', 10),
            ],
        ];

        $result = $this->callMerge($configA, $configB);

        // The PriorityDefinition should take over
        $definition = $result['dependencies']['LoggerInterface'];
        $this->assertInstanceOf(PriorityDefinition::class, $definition);
        $this->assertSame('DatabaseLogger', $definition->getDefinition());
    }

    /**
     * Test that PriorityDefinition followed by plain value preserves PriorityDefinition.
     *
     * This is the intended Hyperf behavior: when a PriorityDefinition is defined first
     * and a plain value comes later, the plain value is ignored because PriorityDefinition
     * is only merged with other PriorityDefinitions.
     */
    public function testMergePriorityDefinitionThenPlainDependency(): void
    {
        $configA = [
            'dependencies' => [
                'LoggerInterface' => new PriorityDefinition('DatabaseLogger', 10),
            ],
        ];

        $configB = [
            'dependencies' => [
                'LoggerInterface' => 'FileLogger',
            ],
        ];

        $result = $this->callMerge($configA, $configB);

        // The PriorityDefinition should be preserved (plain value ignored)
        // This matches Hyperf's behavior - PriorityDefinition only merges with PriorityDefinition
        $definition = $result['dependencies']['LoggerInterface'];
        $this->assertInstanceOf(PriorityDefinition::class, $definition);
        $this->assertSame('DatabaseLogger', $definition->getDefinition());
    }

    /**
     * Test mixed dependencies - some plain, some with PriorityDefinition.
     */
    public function testMergeMixedDependencies(): void
    {
        $configA = [
            'dependencies' => [
                'CacheInterface' => new PriorityDefinition('RedisCache', 5),
                'LoggerInterface' => 'FileLogger',
                'QueueInterface' => 'SyncQueue',
            ],
        ];

        $configB = [
            'dependencies' => [
                'CacheInterface' => new PriorityDefinition('MemoryCache', 10),
                'LoggerInterface' => 'DatabaseLogger',
                'MailerInterface' => 'SmtpMailer',
            ],
        ];

        $result = $this->callMerge($configA, $configB);

        // CacheInterface: PriorityDefinition merged, higher priority wins
        $cacheDefinition = $result['dependencies']['CacheInterface'];
        $this->assertInstanceOf(PriorityDefinition::class, $cacheDefinition);
        $this->assertSame('MemoryCache', $cacheDefinition->getDefinition());

        // LoggerInterface: Plain values, last wins
        $this->assertSame('DatabaseLogger', $result['dependencies']['LoggerInterface']);

        // QueueInterface: Only in first config, preserved
        $this->assertSame('SyncQueue', $result['dependencies']['QueueInterface']);

        // MailerInterface: Only in second config, added
        $this->assertSame('SmtpMailer', $result['dependencies']['MailerInterface']);
    }

    /**
     * Test that mergeTwo() is callable as a public static method.
     *
     * This method is public so ConfigFactory can use the same merge semantics
     * without duplicating the implementation.
     */
    public function testMergeTwoIsPublicAndWorksDirectly(): void
    {
        $base = [
            'commands' => ['CommandA', 'CommandB'],
            'database' => [
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => ['driver' => 'sqlite'],
                ],
            ],
        ];

        $override = [
            'commands' => ['CommandC'],
            'database' => [
                'default' => 'pgsql',
                'connections' => [
                    'pgsql' => ['driver' => 'pgsql', 'host' => 'localhost'],
                ],
            ],
        ];

        // Call directly without reflection - this verifies it's public
        $result = ProviderConfig::mergeTwo($base, $override);

        // Numeric arrays are combined
        $this->assertSame(['CommandA', 'CommandB', 'CommandC'], $result['commands']);

        // Scalar values are overridden
        $this->assertSame('pgsql', $result['database']['default']);

        // Nested arrays are merged recursively
        $this->assertArrayHasKey('sqlite', $result['database']['connections']);
        $this->assertArrayHasKey('pgsql', $result['database']['connections']);
        $this->assertSame('sqlite', $result['database']['connections']['sqlite']['driver']);
        $this->assertSame('pgsql', $result['database']['connections']['pgsql']['driver']);
    }

    /**
     * Call the protected merge method via reflection.
     */
    private function callMerge(array ...$arrays): array
    {
        $method = new ReflectionMethod(ProviderConfig::class, 'merge');

        return $method->invoke(null, ...$arrays);
    }
}
