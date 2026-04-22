<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Tests\TestCase;

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
        $this->assertArrayHasKey('index-settings', $config['algolia']);
        $this->assertIsArray($config['algolia']['index-settings']);
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
}
