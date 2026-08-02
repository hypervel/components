<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Meilisearch;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\MeilisearchEngine;
use Hypervel\Tests\Support\MeilisearchIntegrationTestCase;

/**
 * Base test case for Meilisearch Scout integration tests.
 *
 * Extends the generic Meilisearch test case with Scout-specific setup:
 * database migrations and engine initialization.
 */
abstract class MeilisearchScoutIntegrationTestCase extends MeilisearchIntegrationTestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected string $basePrefix = 'scout_int_';

    protected MeilisearchEngine $engine;

    protected function setUpInCoroutine(): void
    {
        $this->engine = $this->app->make(EngineManager::class)->engine('meilisearch');
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

    /**
     * Wait for all pending Meilisearch tasks to complete.
     */
    protected function waitForMeilisearchTasks(int $timeoutMs = 10000): void
    {
        parent::waitForMeilisearchTasks($timeoutMs);
    }
}
