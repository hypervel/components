<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Typesense;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\TypesenseEngine;
use Hypervel\Tests\Support\TypesenseIntegrationTestCase;

/**
 * Base test case for Typesense Scout integration tests.
 *
 * Extends the generic Typesense test case with Scout-specific setup:
 * database migrations and engine initialization.
 */
abstract class TypesenseScoutIntegrationTestCase extends TypesenseIntegrationTestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected string $basePrefix = 'scout_int_';

    protected TypesenseEngine $engine;

    protected function setUpInCoroutine(): void
    {
        $this->initializeTypesense();
        $this->engine = $this->app->make(EngineManager::class)->engine('typesense');
    }

    protected function tearDownInCoroutine(): void
    {
        $this->cleanupTestCollections();
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
