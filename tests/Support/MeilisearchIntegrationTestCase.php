<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Testbench\TestCase;
use Meilisearch\Client as MeilisearchClient;
use Throwable;

/**
 * Base test case for Meilisearch integration tests.
 *
 * Provides parallel-safe Meilisearch testing infrastructure:
 * - Uses TEST_TOKEN env var (from paratest) to create unique index prefixes
 * - Configures Meilisearch client from environment variables
 * - Cleans up test indexes in setUp/tearDown
 *
 * NOTE: This base class does NOT include RunTestsInCoroutine. Subclasses
 * should add the trait if they need coroutine context for their tests.
 *
 * @internal
 * @coversNothing
 */
abstract class MeilisearchIntegrationTestCase extends TestCase
{
    /**
     * Base index prefix for integration tests.
     */
    protected string $basePrefix = 'int_test';

    /**
     * Computed prefix (includes TEST_TOKEN if running in parallel).
     */
    protected string $testPrefix;

    /**
     * The Meilisearch client instance.
     */
    protected MeilisearchClient $meilisearch;

    /**
     * Track indexes created during tests for cleanup.
     *
     * @var array<string>
     */
    protected array $createdIndexes = [];

    protected function setUp(): void
    {
        if (! env('RUN_MEILISEARCH_INTEGRATION_TESTS', false)) {
            $this->markTestSkipped(
                'Meilisearch integration tests are disabled. Set RUN_MEILISEARCH_INTEGRATION_TESTS=true to enable.'
            );
        }

        $this->computeTestPrefix();

        parent::setUp();

        $this->app->register(ScoutServiceProvider::class);
        $this->configureMeilisearch();
    }

    /**
     * Initialize the Meilisearch client and clean up indexes.
     *
     * Subclasses using RunTestsInCoroutine should call this in setUpInCoroutine().
     * Subclasses NOT using the trait should call this at the end of setUp().
     */
    protected function initializeMeilisearch(): void
    {
        $this->meilisearch = $this->app->get(MeilisearchClient::class);
        $this->cleanupTestIndexes();
    }

    protected function tearDown(): void
    {
        if (isset($this->meilisearch)) {
            $this->cleanupTestIndexes();
        }

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
