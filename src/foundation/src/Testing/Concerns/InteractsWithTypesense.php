<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Config\Repository;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Provides Typesense integration testing support.
 *
 * Auto-called by TestCase via setUpTraits():
 * - setUpInteractsWithTypesense() runs after app boots
 * - tearDownInteractsWithTypesense() runs via beforeApplicationDestroyed()
 *
 * Features:
 * - Auto-skip: Skips tests if Typesense unavailable
 * - Parallel-safe: Uses TEST_TOKEN for unique collection prefixes
 * - Auto-cleanup: Removes test collections in teardown
 *
 * Usage: Add `use InteractsWithTypesense;` to your test case and call
 * configureTypesenseForTesting() from defineEnvironment().
 *
 * Environment Variables:
 * - TYPESENSE_HOST: Host (default: 127.0.0.1)
 * - TYPESENSE_PORT: Port (default: 8108)
 * - TYPESENSE_PROTOCOL: Protocol (default: http)
 * - TYPESENSE_API_KEY: API key (required)
 * - TEST_TOKEN: Parallel test token from paratest (auto-set)
 */
trait InteractsWithTypesense
{
    /**
     * Indicates if connection failed once, skip all subsequent tests.
     */
    private static bool $typesenseConnectionFailed = false;

    /**
     * The test prefix for collection isolation.
     */
    protected string $typesenseTestPrefix = '';

    /**
     * The Typesense client instance.
     */
    protected ?TypesenseClient $typesense = null;

    /**
     * Set up Typesense for testing (auto-called by setUpTraits).
     *
     * Follows Laravel's InteractsWithRedis pattern:
     * - Only skips if using default host/port AND no explicit TYPESENSE_HOST env var
     * - If explicit config exists and fails, the exception propagates (misconfiguration)
     */
    protected function setUpInteractsWithTypesense(): void
    {
        if (static::$typesenseConnectionFailed) {
            $this->markTestSkipped(
                'Typesense connection failed with defaults. Set TYPESENSE_HOST & TYPESENSE_PORT to enable ' . static::class
            );
        }

        $host = env('TYPESENSE_HOST', '127.0.0.1');
        $port = env('TYPESENSE_PORT', '8108');

        try {
            $this->initializeTypesenseClient();
            $this->typesense->health->retrieve();
            $this->cleanupTypesenseCollections();
        } catch (Throwable $e) {
            if ($host === '127.0.0.1' && $port === '8108' && env('TYPESENSE_HOST') === null) {
                static::$typesenseConnectionFailed = true;
                $this->markTestSkipped(
                    'Typesense connection failed with defaults. Set TYPESENSE_HOST & TYPESENSE_PORT to enable ' . static::class
                );
            }
            // Explicit config exists but failed - rethrow so test fails (misconfiguration)
            throw $e;
        }
    }

    /**
     * Tear down Typesense (auto-called via beforeApplicationDestroyed).
     */
    protected function tearDownInteractsWithTypesense(): void
    {
        if (static::$typesenseConnectionFailed || $this->typesense === null) {
            return;
        }

        try {
            $this->cleanupTypesenseCollections();
        } catch (Throwable) {
            // Ignore cleanup errors
        }

        $this->typesense = null;
    }

    /**
     * Configure Typesense for testing.
     *
     * Call from defineEnvironment() to set up Scout config.
     */
    protected function configureTypesenseForTesting(Repository $config): void
    {
        $this->computeTypesenseTestPrefix();

        $config->set('scout.driver', 'typesense');
        $config->set('scout.prefix', $this->typesenseTestPrefix);
        $config->set('scout.typesense.client-settings', $this->getTypesenseClientSettings());
    }

    /**
     * Initialize the Typesense client.
     */
    protected function initializeTypesenseClient(): void
    {
        $this->typesense = new TypesenseClient($this->getTypesenseClientSettings());
    }

    /**
     * Get Typesense client settings.
     *
     * @return array<string, mixed>
     */
    protected function getTypesenseClientSettings(): array
    {
        return [
            'api_key' => env('TYPESENSE_API_KEY', ''),
            'nodes' => [
                [
                    'host' => env('TYPESENSE_HOST', '127.0.0.1'),
                    'port' => (string) env('TYPESENSE_PORT', '8108'),
                    'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
                ],
            ],
            'connection_timeout_seconds' => 2,
        ];
    }

    /**
     * Compute the test prefix for parallel-safe collection names.
     */
    protected function computeTypesenseTestPrefix(): void
    {
        $base = 'test_';
        $token = env('TEST_TOKEN', '');

        $this->typesenseTestPrefix = $token !== '' ? "{$base}{$token}_" : $base;
    }

    /**
     * Check if TYPESENSE_HOST was explicitly set.
     */
    protected function hasExplicitTypesenseConfig(): bool
    {
        return env('TYPESENSE_HOST') !== null;
    }

    /**
     * Get a prefixed collection name.
     */
    protected function typesenseCollection(string $name): string
    {
        return $this->typesenseTestPrefix . $name;
    }

    /**
     * Clean up all test collections matching the test prefix.
     */
    protected function cleanupTypesenseCollections(): void
    {
        if ($this->typesense === null) {
            return;
        }

        try {
            $collections = $this->typesense->collections->retrieve();

            foreach ($collections as $collection) {
                if (str_starts_with($collection['name'], $this->typesenseTestPrefix)) {
                    $this->typesense->collections[$collection['name']]->delete();
                }
            }
        } catch (Throwable) {
            // Ignore errors during cleanup
        }
    }
}
