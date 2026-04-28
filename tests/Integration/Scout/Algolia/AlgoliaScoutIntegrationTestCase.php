<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Algolia;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\AlgoliaEngine;
use Hypervel\Tests\Support\AlgoliaIntegrationTestCase;

/**
 * Base test case for Algolia Scout integration tests.
 *
 * Extends the generic Algolia test case with Scout-specific setup:
 * database migrations and engine initialization.
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
