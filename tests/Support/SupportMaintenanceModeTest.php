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
