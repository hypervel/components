<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Contracts\Config\Repository;
use Meilisearch\Client as MeilisearchClient;
use Throwable;

/**
 * Provides Meilisearch integration testing support.
 *
 * Auto-called by TestCase via setUpTraits():
 * - setUpInteractsWithMeilisearch() runs after app boots
 * - tearDownInteractsWithMeilisearch() runs via beforeApplicationDestroyed()
 *
 * Features:
 * - Auto-skip: Skips tests if Meilisearch unavailable
 * - Parallel-safe: Uses TEST_TOKEN for unique index prefixes
 * - Auto-cleanup: Removes test indexes in teardown
 *
 * Usage: Add `use InteractsWithMeilisearch;` to your test case and call
 * configureMeilisearchForTesting() from defineEnvironment().
 *
 * Environment Variables:
 * - MEILISEARCH_HOST: Host (default: 127.0.0.1)
 * - MEILISEARCH_PORT: Port (default: 7700)
 * - MEILISEARCH_KEY: API key (optional)
 * - TEST_TOKEN: Parallel test token from paratest (auto-set)
 */
trait InteractsWithMeilisearch
{
    /**
     * Indicates if connection failed once, skip all subsequent tests.
     */
    private static bool $meilisearchConnectionFailed = false;

    /**
     * The test prefix for index isolation.
     */
    protected string $meilisearchTestPrefix = '';

    /**
     * The Meilisearch client instance.
     */
    protected ?MeilisearchClient $meilisearch = null;

    /**
     * Set up Meilisearch for testing (auto-called by setUpTraits).
     *
     * Follows Laravel's InteractsWithRedis pattern:
     * - Only skips if using default host/port AND no explicit MEILISEARCH_HOST env var
     * - If explicit config exists and fails, the exception propagates (misconfiguration)
     */
    protected function setUpInteractsWithMeilisearch(): void
    {
        if (static::$meilisearchConnectionFailed) {
            $this->markTestSkipped(
                'Meilisearch connection failed with defaults. Set MEILISEARCH_HOST & MEILISEARCH_PORT to enable ' . static::class
            );
        }

        $host = env('MEILISEARCH_HOST', '127.0.0.1');
        $port = env('MEILISEARCH_PORT', '7700');

        $this->initializeMeilisearchClient();

        try {
            $this->meilisearch->health();
            // getIndexes() requires auth - use it to verify credentials
            $this->meilisearch->getIndexes();
            $this->cleanupMeilisearchIndexes();
        } catch (Throwable $e) {
            if ($host === '127.0.0.1' && $port === '7700' && env('MEILISEARCH_HOST') === null) {
                static::$meilisearchConnectionFailed = true;
                $this->markTestSkipped(
                    'Meilisearch connection failed with defaults. Set MEILISEARCH_HOST & MEILISEARCH_PORT to enable ' . static::class
                );
            }
            // Explicit config exists but failed - rethrow so test fails (misconfiguration)
            throw $e;
        }
    }

    /**
     * Tear down Meilisearch (auto-called via beforeApplicationDestroyed).
     */
    protected function tearDownInteractsWithMeilisearch(): void
    {
        if (static::$meilisearchConnectionFailed || $this->meilisearch === null) {
            return;
        }

        try {
            $this->cleanupMeilisearchIndexes();
        } catch (Throwable) {
            // Ignore cleanup errors
        }

        $this->meilisearch = null;
    }

    /**
     * Configure Meilisearch for testing.
     *
     * Call from defineEnvironment() to set up Scout config.
     */
    protected function configureMeilisearchForTesting(Repository $config): void
    {
        $this->computeMeilisearchTestPrefix();

        $config->set('scout.driver', 'meilisearch');
        $config->set('scout.prefix', $this->meilisearchTestPrefix);
        $config->set('scout.meilisearch.host', $this->getMeilisearchHost());
        $config->set('scout.meilisearch.key', env('MEILISEARCH_KEY', ''));
    }

    /**
     * Initialize the Meilisearch client.
     */
    protected function initializeMeilisearchClient(): void
    {
        $this->meilisearch = new MeilisearchClient(
            $this->getMeilisearchHost(),
            env('MEILISEARCH_KEY', '')
        );
    }

    /**
     * Compute the test prefix for parallel-safe index names.
     */
    protected function computeMeilisearchTestPrefix(): void
    {
        $base = 'test_';
        $token = env('TEST_TOKEN', '');

        $this->meilisearchTestPrefix = $token !== '' ? "{$base}{$token}_" : $base;
    }

    /**
     * Get the Meilisearch host URL.
     *
     * Builds URL from MEILISEARCH_HOST and MEILISEARCH_PORT env vars.
     */
    protected function getMeilisearchHost(): string
    {
        $host = env('MEILISEARCH_HOST', '127.0.0.1');
        $port = env('MEILISEARCH_PORT', '7700');

        return "http://{$host}:{$port}";
    }

    /**
     * Check if MEILISEARCH_HOST was explicitly set.
     */
    protected function hasExplicitMeilisearchConfig(): bool
    {
        return env('MEILISEARCH_HOST') !== null;
    }

    /**
     * Get a prefixed index name.
     */
    protected function meilisearchIndex(string $name): string
    {
        return $this->meilisearchTestPrefix . $name;
    }

    /**
     * Clean up all test indexes matching the test prefix.
     */
    protected function cleanupMeilisearchIndexes(): void
    {
        if ($this->meilisearch === null) {
            return;
        }

        try {
            $indexes = $this->meilisearch->getIndexes();

            foreach ($indexes->getResults() as $index) {
                if (str_starts_with($index->getUid(), $this->meilisearchTestPrefix)) {
                    $this->meilisearch->deleteIndex($index->getUid());
                }
            }
        } catch (Throwable) {
            // Ignore errors during cleanup
        }
    }

    /**
     * Wait for all pending Meilisearch tasks to complete.
     */
    protected function waitForMeilisearchTasks(int $timeoutMs = 5000): void
    {
        if ($this->meilisearch === null) {
            return;
        }

        try {
            $tasks = $this->meilisearch->getTasks();
            foreach ($tasks->getResults() as $task) {
                if (in_array($task['status'], ['enqueued', 'processing'], true)) {
                    $this->meilisearch->waitForTask($task['uid'], $timeoutMs);
                }
            }
        } catch (Throwable) {
            // Ignore timeout errors
        }
    }
}
