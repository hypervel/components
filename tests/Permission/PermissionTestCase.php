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

    protected bool $migrateRefresh = true;

    protected ?ApplicationContract $app = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->get(ConfigInterface::class)
            ->set('cache', [
                'default' => env('CACHE_DRIVER', 'array'),
                'stores' => [
                    'array' => [
                        'driver' => 'array',
                    ],
                ],
                'prefix' => env('CACHE_PREFIX', 'hypervel_cache'),
            ]);

        $this->app->get(ConfigInterface::class)
            ->set('permission', [
                'models' => [
                    'role' => \Hypervel\Permission\Models\Role::class,
                    'permission' => \Hypervel\Permission\Models\Permission::class,
                ],
                'cache' => [
                    'store' => env('PERMISSION_CACHE_STORE', 'default'),
                    'expiration_seconds' => 86400, // 24 hours
                    'keys' => [
                        'roles' => 'hypervel.permission.roles',
                        'owner' => 'hypervel.permission.owner',
                        'owner_roles' => 'hypervel.permission.owner.roles',
                        'owner_permissions' => 'hypervel.permission.owner.permissions',
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
        // $this->createUsersTable();
    }

    // protected function createUsersTable()
    // {
    //    Schema::create('users', function (Blueprint $table) {
    //        $table->id();
    //        $table->string('name');
    //        $table->string('email')->unique();
    //        $table->string('password');
    //        $table->timestamps();
    //    });
    // }

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => [
                dirname(__DIR__, 2) . '/src/permission/database/migrations',
                __DIR__ . '/migrations',
            ],
        ];
    }
}
