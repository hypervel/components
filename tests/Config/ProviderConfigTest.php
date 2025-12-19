<?php

declare(strict_types=1);

namespace Hypervel\Tests\Config;

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
     * Arrays with mixed keys are NOT lists, so they use associative merge
     * semantics where later values override earlier ones for ALL keys.
     */
    public function testMergeMixedNumericAndStringKeys(): void
    {
        $configA = [
            'mixed' => [
                0 => 'numeric_0',
                'string_key' => 'value_a',
                1 => 'numeric_1',
            ],
        ];

        $configB = [
            'mixed' => [
                0 => 'another_numeric',
                'string_key' => 'value_b',
            ],
        ];

        $result = $this->callMerge($configA, $configB);

        // String key should be replaced
        $this->assertSame('value_b', $result['mixed']['string_key']);

        // Numeric key 0 should be replaced (not appended) because mixed arrays aren't lists
        $this->assertSame('another_numeric', $result['mixed'][0]);

        // Numeric key 1 only exists in configA, so it's preserved
        $this->assertSame('numeric_1', $result['mixed'][1]);

        // Should have exactly 3 entries (not 4 with append)
        $this->assertCount(3, $result['mixed']);
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
     * Arr::merge deduplicates by default, which prevents listeners
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
     * Test that numeric string keys are treated as associative (not appended).
     */
    public function testMergeNumericStringKeysAreAssociative(): void
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

        // String key '80' should be replaced, not appended
        $this->assertSame('http_updated', $result['ports']['80']);
        $this->assertSame('https', $result['ports']['443']);
        $this->assertSame('alt_http', $result['ports']['8080']);

        // Should have exactly 3 keys, not 4
        $this->assertCount(3, $result['ports']);
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
     * Call the protected merge method via reflection.
     */
    private function callMerge(array ...$arrays): array
    {
        $method = new ReflectionMethod(ProviderConfig::class, 'merge');

        return $method->invoke(null, ...$arrays);
    }
}
