<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Contracts\Application as ApplicationContract;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class PermissionTestCase extends TestCase
{
    use RefreshDatabase;

    protected ?ApplicationContract $app = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->get(ConfigInterface::class)
            ->set('permission', [
                'models' => [
                    'role' => \Hypervel\Permission\Models\Role::class,
                    'permission' => \Hypervel\Permission\Models\Permission::class,
                ],
                'cache' => [
                    'store' => env('PERMISSION_CACHE_STORE', 'default'),
                ],
                'storage' => [
                    'database' => [
                        'connection' => env('DB_CONNECTION', 'mysql'),
                    ],
                ],
                'table_names' => [
                    'roles' => 'roles',
                    'permissions' => 'permissions',
                    'role_has_permissions' => 'role_has_permissions',
                    'owner_has_permissions' => 'owner_has_permissions',
                    'owner_has_roles' => 'owner_has_roles',
                ],
                'column_names' => [
                    'role_pivot_key' => 'role_id',
                    'permission_pivot_key' => 'permission_id',
                    'owner_name' => 'owner',
                    'owner_morph_key' => 'owner_id',
                ],
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
                dirname(__DIR__, 2) . '/src/permission/database/migrations',
            ],
        ];
    }
}
