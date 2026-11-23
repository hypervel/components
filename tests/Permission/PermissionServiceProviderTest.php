<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\PermissionServiceProvider;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class PermissionServiceProviderTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [PermissionServiceProvider::class];
    }

    public function testCanonicalOptionalDefaultsAreDeclared(): void
    {
        $config = require dirname(__DIR__, 2) . '/src/permission/config/permission.php';

        $this->assertSame(PermissionRegistrar::DEFAULT_CACHE_EXPIRATION_SECONDS, $config['cache']['expiration_seconds']);
        $this->assertSame(PermissionRegistrar::DEFAULT_CACHE_COLUMN_NAMES_EXCEPT, $config['cache']['column_names_except']);
        $this->assertSame(PermissionRegistrar::DEFAULT_TEAM_FOREIGN_KEY, $config['column_names']['team_foreign_key']);
        $this->assertSame(PermissionRegistrar::ROLE_CATALOG_CACHE_KEY, $config['cache']['keys']['roles']);
        $this->assertSame(PermissionRegistrar::MODEL_ROLES_CACHE_KEY_PREFIX, $config['cache']['keys']['model_roles']);
        $this->assertSame(PermissionRegistrar::MODEL_PERMISSIONS_CACHE_KEY_PREFIX, $config['cache']['keys']['model_permissions']);
        $this->assertSame(PermissionRegistrar::MODEL_CACHE_TOKEN_KEY, $config['cache']['keys']['model_token']);
        $this->assertNull($config['column_names']['role_pivot_key']);
        $this->assertNull($config['column_names']['permission_pivot_key']);
        $this->assertArrayNotHasKey('wildcard_permission', $config);
    }

    #[DataProvider('migrations')]
    public function testMigrationReportsWhenPermissionConfigurationIsNotLoaded(string $file): void
    {
        config(['permission.table_names' => null]);
        $migration = require dirname(__DIR__, 2) . '/src/permission/database/migrations/' . $file;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Error: config/permission.php not loaded.');

        $migration->up();
    }

    /**
     * Get the package migration files.
     *
     * @return array<string, array{string}>
     */
    public static function migrations(): array
    {
        return [
            'create permission tables' => ['2025_07_02_000000_create_permission_tables.php'],
            'add teams fields' => ['add_teams_fields.php.stub'],
        ];
    }
}
