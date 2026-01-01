<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Integration\Meilisearch;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Scout\Console\DeleteIndexCommand;
use Hypervel\Scout\Console\FlushCommand;
use Hypervel\Scout\Console\ImportCommand;
use Hypervel\Scout\Console\IndexCommand;
use Hypervel\Scout\Console\SyncIndexSettingsCommand;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\MeilisearchEngine;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use Meilisearch\Client as MeilisearchClient;
use Throwable;

/**
 * Base test case for Meilisearch Scout integration tests.
 *
 * Combines database support with Meilisearch connectivity for testing
 * the full Scout workflow with real Meilisearch instance.
 *
 * @group integration
 * @group meilisearch-integration
 *
 * @internal
 * @coversNothing
 */
abstract class MeilisearchScoutIntegrationTestCase extends TestCase
{
    use RefreshDatabase;
    use RunTestsInCoroutine;

    protected bool $migrateRefresh = true;

    protected string $basePrefix = 'scout_int_';

    protected string $testPrefix;

    protected MeilisearchClient $meilisearch;

    protected MeilisearchEngine $engine;

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
        $this->registerScoutCommands();

        // Clear cached engines so they're recreated with our test config
        $this->app->get(EngineManager::class)->forgetEngines();
    }

    /**
     * Register Scout commands with the Artisan application.
     *
     * Commands registered via ServiceProvider::commands() after the app is
     * bootstrapped won't be available unless we manually resolve them.
     */
    protected function registerScoutCommands(): void
    {
        Artisan::getArtisan()->resolveCommands([
            DeleteIndexCommand::class,
            FlushCommand::class,
            ImportCommand::class,
            IndexCommand::class,
            SyncIndexSettingsCommand::class,
        ]);
    }

    protected function setUpInCoroutine(): void
    {
        $this->meilisearch = $this->app->get(MeilisearchClient::class);
        $this->engine = $this->app->get(EngineManager::class)->engine('meilisearch');
        $this->cleanupTestIndexes();
    }

    protected function tearDownInCoroutine(): void
    {
        $this->cleanupTestIndexes();
    }

    protected function computeTestPrefix(): void
    {
        $testToken = env('TEST_TOKEN', '');
        $this->testPrefix = $testToken !== ''
            ? "{$this->basePrefix}{$testToken}_"
            : "{$this->basePrefix}";
    }

    protected function configureMeilisearch(): void
    {
        $config = $this->app->get(ConfigInterface::class);

        $host = env('MEILISEARCH_HOST', '127.0.0.1');
        $port = env('MEILISEARCH_PORT', '7700');
        $key = env('MEILISEARCH_KEY', '');

        $config->set('scout.driver', 'meilisearch');
        $config->set('scout.prefix', $this->testPrefix);
        $config->set('scout.soft_delete', false);
        $config->set('scout.queue.enabled', false);
        $config->set('scout.meilisearch.host', "http://{$host}:{$port}");
        $config->set('scout.meilisearch.key', $key);
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => [
                dirname(__DIR__, 2) . '/migrations',
            ],
        ];
    }

    protected function prefixedIndexName(string $name): string
    {
        return $this->testPrefix . $name;
    }

    /**
     * Wait for all pending Meilisearch tasks to complete.
     */
    protected function waitForMeilisearchTasks(int $timeoutMs = 10000): void
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

    protected function cleanupTestIndexes(): void
    {
        try {
            $indexes = $this->meilisearch->getIndexes();

            foreach ($indexes->getResults() as $index) {
                if (str_starts_with($index->getUid(), $this->testPrefix)) {
                    $this->meilisearch->deleteIndex($index->getUid());
                }
            }

            $this->waitForMeilisearchTasks();
        } catch (Throwable) {
            // Ignore errors during cleanup
        }
    }
}
