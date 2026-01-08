<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Integration\Meilisearch;

use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Scout\Console\DeleteAllIndexesCommand;
use Hypervel\Scout\Console\DeleteIndexCommand;
use Hypervel\Scout\Console\FlushCommand;
use Hypervel\Scout\Console\ImportCommand;
use Hypervel\Scout\Console\IndexCommand;
use Hypervel\Scout\Console\SyncIndexSettingsCommand;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\MeilisearchEngine;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Tests\Support\MeilisearchIntegrationTestCase;

/**
 * Base test case for Meilisearch Scout integration tests.
 *
 * Extends the generic Meilisearch test case with Scout-specific setup:
 * database migrations, Scout commands, and engine initialization.
 *
 * @group integration
 * @group meilisearch-integration
 *
 * @internal
 * @coversNothing
 */
abstract class MeilisearchScoutIntegrationTestCase extends MeilisearchIntegrationTestCase
{
    use RefreshDatabase;
    use RunTestsInCoroutine;

    protected bool $migrateRefresh = true;

    protected string $basePrefix = 'scout_int_';

    protected MeilisearchEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerScoutCommands();

        // Clear cached engines so they're recreated with our test config
        $this->app->get(EngineManager::class)->forgetEngines();
    }

    protected function setUpInCoroutine(): void
    {
        $this->initializeMeilisearch();
        $this->engine = $this->app->get(EngineManager::class)->engine('meilisearch');
    }

    protected function tearDownInCoroutine(): void
    {
        $this->cleanupTestIndexes();
    }

    /**
     * Register Scout commands with the Artisan application.
     */
    protected function registerScoutCommands(): void
    {
        Artisan::getArtisan()->resolveCommands([
            DeleteAllIndexesCommand::class,
            DeleteIndexCommand::class,
            FlushCommand::class,
            ImportCommand::class,
            IndexCommand::class,
            SyncIndexSettingsCommand::class,
        ]);
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

    /**
     * Wait for all pending Meilisearch tasks to complete.
     */
    protected function waitForMeilisearchTasks(int $timeoutMs = 10000): void
    {
        $this->waitForTasks($timeoutMs);
    }
}
