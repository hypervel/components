<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the on-disk scout config file defaults.
 *
 * Deliberately does NOT extend ScoutTestCase — that base class's setUp()
 * replaces the entire scout config array with a minimal fixture, so reading
 * config('scout.algolia') there tests the fixture, not the real defaults
 * shipped in src/scout/config/scout.php. Loading the file directly bypasses
 * any container/config harness.
 */
class ConfigFileTest extends TestCase
{
    public function testAlgoliaDefaultsArePresentInConfigFile(): void
    {
        $config = require dirname(__DIR__, 3) . '/src/scout/config/scout.php';

        $this->assertIsArray($config);

        $this->assertArrayHasKey('identify', $config);
        $this->assertFalse($config['identify']);

        $this->assertArrayHasKey('algolia', $config);
        $this->assertIsArray($config['algolia']);
        $this->assertArrayHasKey('id', $config['algolia']);
        $this->assertArrayHasKey('secret', $config['algolia']);
        $this->assertArrayHasKey('connect_timeout', $config['algolia']);
        $this->assertNull($config['algolia']['connect_timeout']);
        $this->assertArrayHasKey('read_timeout', $config['algolia']);
        $this->assertNull($config['algolia']['read_timeout']);
        $this->assertArrayHasKey('write_timeout', $config['algolia']);
        $this->assertNull($config['algolia']['write_timeout']);
        $this->assertArrayHasKey('index-settings', $config['algolia']);
        $this->assertIsArray($config['algolia']['index-settings']);
    }

    public function testQueuedJobDefaultsArePresentInConfigFile(): void
    {
        $config = require dirname(__DIR__, 3) . '/src/scout/config/scout.php';

        $this->assertSame([
            'tries' => null,
            'backoff' => null,
            'max_exceptions' => null,
        ], $config['jobs']);
    }

    public function testAfterCommitHasOneTopLevelOwner(): void
    {
        $config = require dirname(__DIR__, 3) . '/src/scout/config/scout.php';

        $this->assertFalse($config['after_commit']);
        $this->assertArrayNotHasKey('after_commit', $config['queue']);
    }

    public function testMeilisearchRetryDefaultsArePresentInConfigFile(): void
    {
        $config = require dirname(__DIR__, 3) . '/src/scout/config/scout.php';

        $this->assertArrayHasKey('meilisearch', $config);
        $this->assertIsArray($config['meilisearch']);

        $this->assertArrayHasKey('retries', $config['meilisearch']);
        $this->assertSame(3, $config['meilisearch']['retries']);

        $this->assertArrayHasKey('initial_retry_delay_ms', $config['meilisearch']);
        $this->assertSame(100, $config['meilisearch']['initial_retry_delay_ms']);
    }

    #[DataProvider('integerEnvironmentValues')]
    public function testIntegerEnvironmentValuesAreLoadedAsIntegers(
        string $environmentKey,
        string $configKey,
        string $environmentValue,
        int $expected,
    ): void {
        $config = $this->withEnvironmentValues([
            $environmentKey => $environmentValue,
        ], fn (): array => require dirname(__DIR__, 3) . '/src/scout/config/scout.php');

        $this->assertSame($expected, data_get($config, $configKey));
    }

    /**
     * Provide integer Scout environment values.
     *
     * @return array<string, array{string, string, string, int}>
     */
    public static function integerEnvironmentValues(): array
    {
        return [
            'command concurrency' => ['SCOUT_COMMAND_CONCURRENCY', 'command_concurrency', '75', 75],
            'Meilisearch retries' => ['MEILISEARCH_RETRIES', 'meilisearch.retries', '7', 7],
            'Meilisearch retry delay' => ['MEILISEARCH_INITIAL_RETRY_DELAY_MS', 'meilisearch.initial_retry_delay_ms', '250', 250],
            'Typesense connection timeout' => ['TYPESENSE_CONNECTION_TIMEOUT_SECONDS', 'typesense.client-settings.connection_timeout_seconds', '4', 4],
            'Typesense healthcheck interval' => ['TYPESENSE_HEALTHCHECK_INTERVAL_SECONDS', 'typesense.client-settings.healthcheck_interval_seconds', '45', 45],
            'Typesense retries' => ['TYPESENSE_NUM_RETRIES', 'typesense.client-settings.num_retries', '5', 5],
            'Typesense retry interval' => ['TYPESENSE_RETRY_INTERVAL_SECONDS', 'typesense.client-settings.retry_interval_seconds', '2', 2],
        ];
    }

    #[DataProvider('booleanEnvironmentValues')]
    public function testBooleanEnvironmentValuesAreLoadedAsBooleans(string $environmentKey, string $configKey): void
    {
        $config = $this->withEnvironmentValues([
            $environmentKey => '1',
        ], fn (): array => require dirname(__DIR__, 3) . '/src/scout/config/scout.php');

        $this->assertTrue(data_get($config, $configKey));
    }

    /**
     * Provide boolean Scout environment values.
     *
     * @return array<string, array{string, string}>
     */
    public static function booleanEnvironmentValues(): array
    {
        return [
            'queue' => ['SCOUT_QUEUE', 'queue.enabled'],
            'soft deletes' => ['SCOUT_SOFT_DELETE', 'soft_delete'],
            'after commit' => ['SCOUT_AFTER_COMMIT', 'after_commit'],
            'identify' => ['SCOUT_IDENTIFY', 'identify'],
        ];
    }
}
