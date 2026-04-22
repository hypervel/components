<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Algolia;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Scout\Console\DeleteAllIndexesCommand;
use Hypervel\Scout\Console\DeleteIndexCommand;
use Hypervel\Scout\Console\FlushCommand;
use Hypervel\Scout\Console\ImportCommand;
use Hypervel\Scout\Console\IndexCommand;
use Hypervel\Scout\Console\SyncIndexSettingsCommand;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\AlgoliaEngine;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Tests\Support\AlgoliaIntegrationTestCase;

/**
 * Base test case for Algolia Scout integration tests.
 *
 * Extends the generic Algolia test case with Scout-specific setup:
 * database migrations, Scout commands, and engine initialization.
 */
abstract class AlgoliaScoutIntegrationTestCase extends AlgoliaIntegrationTestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected string $basePrefix = 'scout_int_';

    protected AlgoliaEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerScoutCommands();

        // Clear cached engines so they're recreated with our test config
        $this->app->make(EngineManager::class)->forgetEngines();
    }

    protected function setUpInCoroutine(): void
    {
        $this->initializeAlgolia();
        $this->engine = $this->app->make(EngineManager::class)->engine('algolia');
    }

    protected function tearDownInCoroutine(): void
    {
        $this->cleanupAlgoliaIndices();
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
                dirname(__DIR__, 3) . '/Scout/migrations',
            ],
        ];
    }
}
