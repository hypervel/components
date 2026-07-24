<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\SupportMaintenanceModeTest;

use Hypervel\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Hypervel\Foundation\MaintenanceModeManager;
use Hypervel\Support\Facades\MaintenanceMode;
use Hypervel\Testbench\TestCase;

class SupportMaintenanceModeTest extends TestCase
{
    public function testExtend()
    {
        MaintenanceMode::extend('test', fn () => new TestMaintenanceMode);

        $this->app->config->set('app.maintenance.driver', 'test');

        $driver = $this->app->make(MaintenanceModeManager::class)->driver();

        $this->assertInstanceOf(TestMaintenanceMode::class, $driver);
    }

    public function testCacheDriverPreservesZeroStoreAndEmptyFallback(): void
    {
        $this->app->config->set([
            'app.maintenance.driver' => 'cache',
            'cache.default' => 'array',
            'cache.stores.0' => ['driver' => 'array'],
            'cache.stores.array' => ['driver' => 'array'],
        ]);

        $this->app->make('cache')->store('0')->put('hypervel:foundation:down', ['store' => 'zero']);
        $this->app->config->set('app.maintenance.store', '0');

        $this->assertSame(
            ['store' => 'zero'],
            (new MaintenanceModeManager($this->app))->driver()->data(),
        );

        $this->app->make('cache')->store('array')->put('hypervel:foundation:down', ['store' => 'default']);
        $this->app->config->set('app.maintenance.store', '');

        $this->assertSame(
            ['store' => 'default'],
            (new MaintenanceModeManager($this->app))->driver()->data(),
        );
    }
}

class TestMaintenanceMode implements MaintenanceModeContract
{
    public function activate(array $payload): void
    {
    }

    public function deactivate(): void
    {
    }

    public function active(): bool
    {
        return false;
    }

    public function data(): array
    {
        return [];
    }
}
