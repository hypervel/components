<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\PermissionServiceProvider;
use Hypervel\Testbench\TestCase;

class PermissionServiceProviderTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [PermissionServiceProvider::class];
    }

    public function testReloadConfigurationUpdatesTheRetainedRegistrar(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertSame('hypervel.permission.cache.roles', $registrar->getCacheKey());

        $this->app->make('config')->set('permission.cache.keys.roles', 'permissions.refreshed');
        $this->app->getProvider(PermissionServiceProvider::class)->reloadConfiguration();

        $this->assertSame($registrar, $this->app->make(PermissionRegistrar::class));
        $this->assertSame('permissions.refreshed', $registrar->getCacheKey());
    }

    public function testReloadConfigurationDoesNotResolveAnUnusedRegistrar(): void
    {
        $application = new Application;
        $application->instance('config', new Repository);
        $provider = new PermissionServiceProvider($application);
        $provider->register();

        $provider->reloadConfiguration();

        $this->assertFalse($application->resolved(PermissionRegistrar::class));
    }
}
