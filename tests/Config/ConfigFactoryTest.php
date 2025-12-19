<?php

declare(strict_types=1);

namespace Hypervel\Tests\Config;

use Hyperf\Collection\Arr;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ConfigFactory merge behavior.
 *
 * ConfigFactory merges: ProviderConfig::load() + $rootConfig + ...$autoloadConfig
 * This test verifies the merge logic handles both scalar replacement and list
 * combining correctly.
 *
 * @internal
 * @coversNothing
 */
class ConfigFactoryTest extends TestCase
{
    /**
     * Test that the merge strategy used by ConfigFactory correctly handles
     * numeric arrays (lists) by combining them, not replacing at indices.
     *
     * This is a regression test for the issue where array_replace_recursive
     * was replacing values at matching indices instead of combining lists.
     *
     * Scenario: Provider configs define commands, app config adds more commands.
     * Expected: All commands should be present in the merged result.
     */
    public function testMergePreservesListsFromProviderConfigs(): void
    {
        // Simulates ProviderConfig::load() result
        $providerConfig = [
            'commands' => [
                'App\Commands\CommandA',
                'App\Commands\CommandB',
                'App\Commands\CommandC',
            ],
            'listeners' => [
                'App\Listeners\ListenerA',
                'App\Listeners\ListenerB',
            ],
        ];

        // Simulates app config (e.g., config/commands.php adding custom commands)
        $appConfig = [
            'commands' => [
                'App\Commands\CustomCommand',
            ],
        ];

        // This simulates what ConfigFactory currently does
        $result = $this->mergeConfigs($providerConfig, $appConfig);

        // All provider commands should still be present
        $this->assertContains(
            'App\Commands\CommandA',
            $result['commands'],
            'CommandA from provider config should be preserved'
        );
        $this->assertContains(
            'App\Commands\CommandB',
            $result['commands'],
            'CommandB from provider config should be preserved'
        );
        $this->assertContains(
            'App\Commands\CommandC',
            $result['commands'],
            'CommandC from provider config should be preserved'
        );

        // App's custom command should be added
        $this->assertContains(
            'App\Commands\CustomCommand',
            $result['commands'],
            'CustomCommand from app config should be added'
        );

        // Should have 4 commands total (3 from provider + 1 from app)
        $this->assertCount(4, $result['commands'], 'Should have all 4 commands');
    }

    /**
     * Test that the merge strategy correctly replaces scalar values in
     * associative arrays (app config overrides provider config).
     */
    public function testMergeReplacesScalarsInAssociativeArrays(): void
    {
        $providerConfig = [
            'database' => [
                'default' => 'sqlite',
                'connections' => [
                    'pgsql' => [
                        'driver' => 'pgsql',
                        'host' => 'localhost',
                        'port' => 5432,
                    ],
                ],
            ],
        ];

        $appConfig = [
            'database' => [
                'default' => 'pgsql',
                'connections' => [
                    'pgsql' => [
                        'host' => 'production-db.example.com',
                    ],
                ],
            ],
        ];

        $result = $this->mergeConfigs($providerConfig, $appConfig);

        // App's default should override provider's default
        $this->assertSame('pgsql', $result['database']['default']);

        // App's host should override provider's host
        $this->assertSame(
            'production-db.example.com',
            $result['database']['connections']['pgsql']['host']
        );

        // Driver should remain a string (not become an array)
        $this->assertIsString(
            $result['database']['connections']['pgsql']['driver'],
            'Driver should remain a string, not become an array'
        );
        $this->assertSame('pgsql', $result['database']['connections']['pgsql']['driver']);

        // Provider's port should be preserved
        $this->assertSame(5432, $result['database']['connections']['pgsql']['port']);
    }

    /**
     * Test merging multiple config arrays (simulating provider + root + autoload configs).
     */
    public function testMergeMultipleConfigArrays(): void
    {
        $providerConfig = [
            'commands' => ['CommandA', 'CommandB'],
            'app' => ['name' => 'Provider Default'],
        ];

        $rootConfig = [
            'app' => ['debug' => true],
        ];

        $autoloadConfig1 = [
            'commands' => ['CommandC'],
            'app' => ['name' => 'My App'],
        ];

        $autoloadConfig2 = [
            'database' => ['default' => 'mysql'],
        ];

        // Merge all configs (simulating ConfigFactory behavior)
        $result = $this->mergeConfigs($providerConfig, $rootConfig, $autoloadConfig1, $autoloadConfig2);

        // All commands should be combined
        $this->assertContains('CommandA', $result['commands']);
        $this->assertContains('CommandB', $result['commands']);
        $this->assertContains('CommandC', $result['commands']);

        // Later app.name should win
        $this->assertSame('My App', $result['app']['name']);

        // app.debug should be merged in
        $this->assertTrue($result['app']['debug']);

        // database from autoloadConfig2 should be present
        $this->assertSame('mysql', $result['database']['default']);
    }

    /**
     * Simulate ConfigFactory's merge behavior using Arr::merge.
     *
     * Arr::merge correctly handles both list arrays (commands, listeners)
     * and associative arrays (config values).
     */
    private function mergeConfigs(array ...$configs): array
    {
        if (empty($configs)) {
            return [];
        }

        return array_reduce(
            array_slice($configs, 1),
            fn (array $carry, array $item) => Arr::merge($carry, $item),
            $configs[0]
        );
    }
}
