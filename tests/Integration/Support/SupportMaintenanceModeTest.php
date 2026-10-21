<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Support\SupportMaintenanceModeTest;

use Hypervel\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Hypervel\Foundation\MaintenanceModeManager;
use Hypervel\Support\Facades\MaintenanceMode;
use Hypervel\Testbench\TestCase;

class SupportMaintenanceModeTest extends TestCase
{
    public function testExtends(): void
    {
        MaintenanceMode::extend('test', fn (): TestMaintenanceMode => new TestMaintenanceMode);

        config(['app.maintenance.driver' => 'test']);

        $driver = $this->app->make(MaintenanceModeManager::class)->driver();

        $this->assertInstanceOf(TestMaintenanceMode::class, $driver);
    }

    public function testCacheDriverPreservesZeroStoreAndEmptyFallback(): void
    {
        config([
            'app.maintenance.driver' => 'cache',
            'cache.default' => 'array',
            'cache.stores.0' => ['driver' => 'array'],
            'cache.stores.array' => ['driver' => 'array'],
        ]);

        $this->app->make('cache')->store('0')->put('hypervel:foundation:down', ['store' => 'zero']);
        config(['app.maintenance.store' => '0']);

        $this->assertSame(
            ['store' => 'zero'],
            (new MaintenanceModeManager($this->app))->driver()->data(),
        );

        $this->app->make('cache')->store('array')->put('hypervel:foundation:down', ['store' => 'default']);
        config(['app.maintenance.store' => '']);

        $this->assertSame(
            ['store' => 'default'],
            (new MaintenanceModeManager($this->app))->driver()->data(),
        );
    }
}

class TestMaintenanceMode implements MaintenanceModeContract
{
    /**
     * Activate maintenance mode.
     */
    public function activate(array $payload): void
    {
    }

    /**
     * Deactivate maintenance mode.
     */
    public function deactivate(): void
    {
    }

    /**
     * Determine whether maintenance mode is active.
     */
    public function active(): bool
    {
        return false;
    }

    /**
     * Get the maintenance mode payload.
     */
    public function data(): array
    {
        return [];
    }
}
