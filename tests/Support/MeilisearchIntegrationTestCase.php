<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
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
 * NOTE: Concrete test classes extending this MUST add @group integration
 * and @group meilisearch-integration for proper test filtering in CI.
 *
 * @internal
 * @coversNothing
 */
abstract class MeilisearchIntegrationTestCase extends TestCase
{
    use RunTestsInCoroutine;

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
     * Set up inside coroutine context.
     *
     * Creates the Meilisearch client here so curl handles are initialized
     * within the coroutine context (required for Swoole's curl hooks).
     */
    protected function setUpInCoroutine(): void
    {
        $this->meilisearch = $this->app->get(MeilisearchClient::class);
        $this->cleanupTestIndexes();
    }

    /**
     * Tear down inside coroutine context.
     */
    protected function tearDownInCoroutine(): void
    {
        $this->cleanupTestIndexes();
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
