<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithConsole;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class DatabaseMigrationsTest extends TestCase
{
    use HasMockedApplication;
    use DatabaseMigrations;
    use InteractsWithConsole;

    protected bool $dropViews = false;

    protected bool $seed = false;

    protected ?string $seeder = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->withoutMockingConsoleOutput();
    }

    public function tearDown(): void
    {
        $this->dropViews = false;
        $this->seed = false;
        $this->seeder = null;
        parent::tearDown();
    }

    protected function setUpTraits(): array
    {
        return [];
    }

    public function testRefreshTestDatabaseDefault()
    {
        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:fresh', [
                '--drop-views' => false,
                '--database' => 'default',
                '--seed' => false,
            ])->andReturn(0);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:rollback', [])
            ->andReturn(0);
        $this->app = $this->getApplication([
            ConfigInterface::class => fn () => $this->getConfig(),
            KernelContract::class => fn () => $kernel,
        ]);

        $this->runDatabaseMigrations();
    }

    public function testRefreshTestDatabaseWithDropViewsOption()
    {
        $this->dropViews = true;

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:fresh', [
                '--drop-views' => true,
                '--database' => 'default',
                '--seed' => false,
            ])->andReturn(0);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:rollback', [])
            ->andReturn(0);
        $this->app = $this->getApplication([
            ConfigInterface::class => fn () => $this->getConfig(),
            KernelContract::class => fn () => $kernel,
        ]);

        $this->runDatabaseMigrations();
    }

    public function testRefreshTestDatabaseWithSeedOption()
    {
        $this->seed = true;

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:fresh', [
                '--drop-views' => false,
                '--database' => 'default',
                '--seed' => true,
            ])->andReturn(0);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:rollback', [])
            ->andReturn(0);
        $this->app = $this->getApplication([
            ConfigInterface::class => fn () => $this->getConfig(),
            KernelContract::class => fn () => $kernel,
        ]);

        $this->runDatabaseMigrations();
    }

    public function testRefreshTestDatabaseWithSeederOption()
    {
        $this->seeder = 'seeder';

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:fresh', [
                '--drop-views' => false,
                '--database' => 'default',
                '--seeder' => 'seeder',
            ])->andReturn(0);
        $kernel->shouldReceive('call')
            ->once()
            ->with('migrate:rollback', [])
            ->andReturn(0);
        $this->app = $this->getApplication([
            ConfigInterface::class => fn () => $this->getConfig(),
            KernelContract::class => fn () => $kernel,
        ]);

        $this->runDatabaseMigrations();
    }

    protected function getConfig(array $config = []): Config
    {
        return new Config(array_merge([
            'database' => [
                'default' => 'default',
            ],
        ], $config));
    }
}
