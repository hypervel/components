<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Testbench\TestCase;

/**
 * Base test case for Scout feature tests.
 *
 * @internal
 * @coversNothing
 */
class ScoutTestCase extends TestCase
{
    use RefreshDatabase;
    use RunTestsInCoroutine;

    protected bool $migrateRefresh = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(ScoutServiceProvider::class);

        $this->app->get(ConfigInterface::class)
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
