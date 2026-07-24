<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithMeilisearch;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Testbench\TestCase;
use Throwable;

/**
 * Base test case for Meilisearch integration tests.
 *
 * Uses InteractsWithMeilisearch trait for:
 * - Opt-in skip: Skips unless MEILISEARCH_HOST is set
 * - Parallel-safe: Uses TEST_TOKEN for unique index prefixes
 * - Auto-cleanup: Removes test indexes in teardown
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

    protected function setUp(): void
    {
        $this->computeTestPrefix();
        $this->meilisearchTestPrefix = $this->testPrefix; // Sync trait's prefix

        parent::setUp();
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            ScoutServiceProvider::class,
        ];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $this->configureMeilisearch($app);
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
    protected function configureMeilisearch(ApplicationContract $app): void
    {
        $config = $app->make('config');

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
     * Create a test index.
     *
     * @param array<string, mixed> $options
     */
    protected function createTestIndex(string $name, array $options = []): void
    {
        $indexName = $this->prefixedIndexName($name);
        $this->meilisearch->createIndex($indexName, $options);
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
