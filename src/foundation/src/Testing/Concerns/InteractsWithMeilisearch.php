<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Meilisearch\Client as MeilisearchClient;
use Throwable;

/**
 * Add Meilisearch support to an integration test.
 *
 * Use this trait on a test case and set MEILISEARCH_HOST. MEILISEARCH_PORT
 * and MEILISEARCH_KEY may also be configured. Test indexes are isolated and
 * cleaned up using a TEST_TOKEN-based prefix.
 */
trait InteractsWithMeilisearch
{
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
     * Meilisearch integration tests are opt-in via MEILISEARCH_HOST. Port and
     * key settings are only read after MEILISEARCH_HOST is present.
     */
    protected function setUpInteractsWithMeilisearch(): void
    {
        if (env('MEILISEARCH_HOST') === null) {
            $this->markTestSkipped(
                'Set MEILISEARCH_HOST to run Meilisearch integration tests for ' . static::class
            );
        }

        if ($this->meilisearchTestPrefix === '') {
            $this->computeMeilisearchTestPrefix();
        }

        $this->initializeMeilisearchClient();
        $this->meilisearch->health();
        // getIndexes() requires auth - use it to verify credentials
        $this->meilisearch->getIndexes();
        $this->cleanupMeilisearchIndexes();
    }

    /**
     * Tear down Meilisearch (auto-called via beforeApplicationDestroyed).
     */
    protected function tearDownInteractsWithMeilisearch(): void
    {
        if ($this->meilisearch === null) {
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
