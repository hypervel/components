<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Support\Env;
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

    public function testMeilisearchRetryEnvironmentValuesAreLoadedAsIntegers(): void
    {
        $retriesKey = 'MEILISEARCH_RETRIES';
        $delayKey = 'MEILISEARCH_INITIAL_RETRY_DELAY_MS';
        $originalRetriesPutenv = getenv($retriesKey);
        $originalDelayPutenv = getenv($delayKey);
        $originalRetriesServerExists = array_key_exists($retriesKey, $_SERVER);
        $originalDelayServerExists = array_key_exists($delayKey, $_SERVER);
        $originalRetriesServer = $_SERVER[$retriesKey] ?? null;
        $originalDelayServer = $_SERVER[$delayKey] ?? null;
        $originalRetriesEnvExists = array_key_exists($retriesKey, $_ENV);
        $originalDelayEnvExists = array_key_exists($delayKey, $_ENV);
        $originalRetriesEnv = $_ENV[$retriesKey] ?? null;
        $originalDelayEnv = $_ENV[$delayKey] ?? null;

        try {
            unset($_SERVER[$retriesKey], $_SERVER[$delayKey], $_ENV[$retriesKey], $_ENV[$delayKey]);
            putenv("{$retriesKey}=7");
            putenv("{$delayKey}=250");
            Env::flushRepository();

            $config = require dirname(__DIR__, 3) . '/src/scout/config/scout.php';

            $this->assertSame(7, $config['meilisearch']['retries']);
            $this->assertSame(250, $config['meilisearch']['initial_retry_delay_ms']);
        } finally {
            $originalRetriesPutenv === false
                ? putenv($retriesKey)
                : putenv("{$retriesKey}={$originalRetriesPutenv}");
            $originalDelayPutenv === false
                ? putenv($delayKey)
                : putenv("{$delayKey}={$originalDelayPutenv}");

            if ($originalRetriesServerExists) {
                $_SERVER[$retriesKey] = $originalRetriesServer;
            } else {
                unset($_SERVER[$retriesKey]);
            }

            if ($originalDelayServerExists) {
                $_SERVER[$delayKey] = $originalDelayServer;
            } else {
                unset($_SERVER[$delayKey]);
            }

            if ($originalRetriesEnvExists) {
                $_ENV[$retriesKey] = $originalRetriesEnv;
            } else {
                unset($_ENV[$retriesKey]);
            }

            if ($originalDelayEnvExists) {
                $_ENV[$delayKey] = $originalDelayEnv;
            } else {
                unset($_ENV[$delayKey]);
            }

            Env::flushRepository();
        }
    }

    public function testSoftDeleteEnvironmentValueIsLoadedAsBoolean(): void
    {
        $key = 'SCOUT_SOFT_DELETE';
        $originalPutenv = getenv($key);
        $originalServerExists = array_key_exists($key, $_SERVER);
        $originalServer = $_SERVER[$key] ?? null;
        $originalEnvExists = array_key_exists($key, $_ENV);
        $originalEnv = $_ENV[$key] ?? null;

        try {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv("{$key}=1");
            Env::flushRepository();

            $config = require dirname(__DIR__, 3) . '/src/scout/config/scout.php';

            $this->assertTrue($config['soft_delete']);
        } finally {
            $originalPutenv === false
                ? putenv($key)
                : putenv("{$key}={$originalPutenv}");

            if ($originalServerExists) {
                $_SERVER[$key] = $originalServer;
            } else {
                unset($_SERVER[$key]);
            }

            if ($originalEnvExists) {
                $_ENV[$key] = $originalEnv;
            } else {
                unset($_ENV[$key]);
            }

            Env::flushRepository();
        }
    }
}
