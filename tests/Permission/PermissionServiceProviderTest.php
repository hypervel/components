<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Permission\DefaultTeamResolver;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\PermissionServiceProvider;
use Hypervel\Testbench\TestCase;
use RuntimeException;

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

    public function testCanonicalOptionalDefaultsAreDeclared(): void
    {
        $config = require dirname(__DIR__, 2) . '/src/permission/config/permission.php';

        $this->assertSame(DefaultTeamResolver::class, $config['team_resolver']);
        $this->assertSame(PermissionRegistrar::DEFAULT_CACHE_EXPIRATION_SECONDS, $config['cache']['expiration_seconds']);
        $this->assertSame(PermissionRegistrar::DEFAULT_CACHE_COLUMN_NAMES_EXCEPT, $config['cache']['column_names_except']);
        $this->assertNull($config['column_names']['role_pivot_key']);
        $this->assertNull($config['column_names']['permission_pivot_key']);
        $this->assertArrayNotHasKey('wildcard_permission', $config);
    }

    public function testMigrationReportsWhenPermissionConfigurationIsNotLoaded(): void
    {
        config(['permission.table_names' => null]);
        $migration = require dirname(__DIR__, 2)
            . '/src/permission/database/migrations/2025_07_02_000000_create_permission_tables.php';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Error: config/permission.php not loaded.');

        $migration->up();
    }
}
