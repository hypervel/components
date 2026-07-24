<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Permission\Contracts\Permission;
use Hypervel\Permission\Contracts\Role;
use Hypervel\Support\Facades\Schema;

class CustomSchemaConfigTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set([
            'permission.table_names.roles' => 'custom_roles',
            'permission.table_names.permissions' => 'custom_permissions',
            'permission.table_names.model_has_permissions' => 'custom_model_has_permissions',
            'permission.table_names.model_has_roles' => 'custom_model_has_roles',
            'permission.table_names.role_has_permissions' => 'custom_role_has_permissions',
        ]);
    }

    public function testCustomTableNamesAreUsedBySchemaModelsAndRelations(): void
    {
        $this->assertTrue(Schema::hasTable('custom_roles'));
        $this->assertTrue(Schema::hasTable('custom_permissions'));
        $this->assertTrue(Schema::hasTable('custom_model_has_roles'));
        $this->assertTrue(Schema::hasTable('custom_model_has_permissions'));
        $this->assertTrue(Schema::hasTable('custom_role_has_permissions'));
        $this->assertFalse(Schema::hasTable('roles'));
        $this->assertFalse(Schema::hasTable('permissions'));

        $this->testUser->assignRole('testRole');
        $this->testUser->givePermissionTo('edit-articles');
        $this->testUserRole->givePermissionTo('edit-news');

        $this->assertDatabaseHas('custom_roles', ['name' => 'testRole']);
        $this->assertDatabaseHas('custom_permissions', ['name' => 'edit-articles']);
        $this->assertDatabaseHas('custom_model_has_roles', [
            'role_test_id' => $this->testUserRole->getKey(),
            'model_test_id' => $this->testUser->getKey(),
        ]);
        $this->assertDatabaseHas('custom_model_has_permissions', [
            'permission_test_id' => $this->app->make(Permission::class)::findByName('edit-articles')->getKey(),
            'model_test_id' => $this->testUser->getKey(),
        ]);
        $this->assertDatabaseHas('custom_role_has_permissions', [
            'role_test_id' => $this->testUserRole->getKey(),
            'permission_test_id' => $this->app->make(Permission::class)::findByName('edit-news')->getKey(),
        ]);

        $this->assertTrue($this->app->make(Role::class)::where('name', 'testRole')->exists());
        $this->assertTrue($this->app->make(Permission::class)::where('name', 'edit-articles')->exists());
    }

    public function testDeniedPermissionUpdatesExistingCustomTableAssignmentEdge(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $this->testUser->denyPermissionTo('edit-articles');

        $this->testUser->refresh();

        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertTrue($this->testUser->hasDeniedPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
    }
}
