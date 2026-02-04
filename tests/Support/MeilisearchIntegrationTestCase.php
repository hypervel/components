<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Testing\Concerns\InteractsWithMeilisearch;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Testbench\TestCase;
use Throwable;

/**
 * Base test case for Meilisearch integration tests.
 *
 * Uses InteractsWithMeilisearch trait for:
 * - Auto-skip: Skips tests if Meilisearch is unavailable (no env var needed)
 * - Parallel-safe: Uses TEST_TOKEN for unique index prefixes
 * - Auto-cleanup: Removes test indexes in teardown
 *
 * NOTE: This base class does NOT include RunTestsInCoroutine. Subclasses
 * should add the trait if they need coroutine context for their tests.
 *
 * @internal
 * @coversNothing
 */
abstract class MeilisearchIntegrationTestCase extends TestCase
{
    use InteractsWithMeilisearch;

    /**
     * Base index prefix for integration tests.
     */
    protected string $basePrefix = 'int_test';

    /**
     * Computed prefix (includes TEST_TOKEN if running in parallel).
     */
    protected string $testPrefix;

    /**
     * Track indexes created during tests for cleanup.
     *
     * @var array<string>
     */
    protected array $createdIndexes = [];

    protected function setUp(): void
    {
        $this->computeTestPrefix();
        $this->meilisearchTestPrefix = $this->testPrefix; // Sync trait's prefix

        parent::setUp();

        $this->app->register(ScoutServiceProvider::class);
        $this->configureMeilisearch();
    }

    /**
     * Initialize the Meilisearch client and clean up indexes.
     *
     * Subclasses using RunTestsInCoroutine should call this in setUpInCoroutine().
     * Subclasses NOT using the trait should call this at the end of setUp().
     *
     * Uses the trait's auto-skip logic - skips if Meilisearch is unavailable.
     */
    protected function initializeMeilisearch(): void
    {
        $this->setUpInteractsWithMeilisearch();
    }

    protected function tearDown(): void
    {
        $this->tearDownInteractsWithMeilisearch();
        $this->createdIndexes = [];

        parent::tearDown();
    }

    /**
     * Compute parallel-safe prefix based on TEST_TOKEN from paratest.
     */
    protected function computeTestPrefix(): void
    {
        $testToken = env('TEST_TOKEN', '');

        if ($testToken !== '') {
            $this->testPrefix = "{$this->basePrefix}_{$testToken}_";
        } else {
            $this->testPrefix = "{$this->basePrefix}_";
        }
    }

    /**
     * Configure Meilisearch from environment variables.
     */
    protected function configureMeilisearch(): void
    {
        $config = $this->app->get(ConfigInterface::class);

        $host = env('MEILISEARCH_HOST', '127.0.0.1');
        $port = env('MEILISEARCH_PORT', '7700');
        $key = env('MEILISEARCH_KEY', '');

        $config->set('scout.driver', 'meilisearch');
        $config->set('scout.prefix', $this->testPrefix);
        $config->set('scout.meilisearch.host', "http://{$host}:{$port}");
        $config->set('scout.meilisearch.key', $key);
    }

    /**
     * Get a prefixed index name.
     */
    protected function prefixedIndexName(string $name): string
    {
        return $this->testPrefix . $name;
    }

    /**
     * Create a test index and track it for cleanup.
     *
     * @param array<string, mixed> $options
     */
    protected function createTestIndex(string $name, array $options = []): void
    {
        $indexName = $this->prefixedIndexName($name);
        $this->meilisearch->createIndex($indexName, $options);
        $this->createdIndexes[] = $indexName;
    }

    /**
     * Clean up all test indexes matching the test prefix.
     */
    protected function cleanupTestIndexes(): void
    {
        try {
            $indexes = $this->meilisearch->getIndexes();

            foreach ($indexes->getResults() as $index) {
                if (str_starts_with($index->getUid(), $this->testPrefix)) {
                    $this->meilisearch->deleteIndex($index->getUid());
                }
            }
        } catch (Throwable) {
            // Ignore errors during cleanup
        }

        $this->createdIndexes = [];
    }

    /**
     * Wait for Meilisearch tasks to complete.
     */
    protected function waitForTasks(int $timeoutMs = 5000): void
    {
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
