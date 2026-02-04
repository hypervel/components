<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Typesense;

use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Scout\Console\DeleteIndexCommand;
use Hypervel\Scout\Console\FlushCommand;
use Hypervel\Scout\Console\ImportCommand;
use Hypervel\Scout\Console\IndexCommand;
use Hypervel\Scout\Console\SyncIndexSettingsCommand;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\TypesenseEngine;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Tests\Support\TypesenseIntegrationTestCase;

/**
 * Base test case for Typesense Scout integration tests.
 *
 * Extends the generic Typesense test case with Scout-specific setup:
 * database migrations, Scout commands, and engine initialization.
 *
 * @group integration
 * @group typesense-integration
 *
 * @internal
 * @coversNothing
 */
abstract class TypesenseScoutIntegrationTestCase extends TypesenseIntegrationTestCase
{
    use RefreshDatabase;
    use RunTestsInCoroutine;

    protected bool $migrateRefresh = true;

    protected string $basePrefix = 'scout_int_';

    protected TypesenseEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerScoutCommands();

        // Clear cached engines so they're recreated with our test config
        $this->app->get(EngineManager::class)->forgetEngines();
    }

    protected function setUpInCoroutine(): void
    {
        $this->initializeTypesense();
        $this->engine = $this->app->get(EngineManager::class)->engine('typesense');
    }

    protected function tearDownInCoroutine(): void
    {
        $this->cleanupTestCollections();
    }

    /**
     * Register Scout commands with the Artisan application.
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
