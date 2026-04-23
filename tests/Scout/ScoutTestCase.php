<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Testbench\TestCase;

/**
 * Base test case for Scout feature tests.
 */
class ScoutTestCase extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            ScoutServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')
            ->set('scout', [
                'driver' => 'collection',
                'prefix' => '',
                'queue' => [
                    'enabled' => false,
                    'connection' => null,
                    'queue' => null,
                    'after_commit' => false,
                ],
                'soft_delete' => false,
                'chunk' => [
                    'searchable' => 500,
                    'unsearchable' => 500,
                ],
                'command_concurrency' => 100,
            ]);
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => [
                __DIR__ . '/migrations',
            ],
        ];
    }
}
