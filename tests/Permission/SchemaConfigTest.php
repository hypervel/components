<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Permission\Contracts\Permission;
use Hypervel\Support\Facades\Schema;

class SchemaConfigTest extends TestCase
{
    public function testTeamsDisabledSchemaDoesNotCreateTeamColumns(): void
    {
        $this->assertFalse(Schema::hasColumn('roles', 'team_test_id'));
        $this->assertFalse(Schema::hasColumn('model_has_roles', 'team_test_id'));
        $this->assertFalse(Schema::hasColumn('model_has_permissions', 'team_test_id'));
    }

    public function testCustomMorphAndPivotKeysAreUsedByRelations(): void
    {
        $this->testUser->assignRole('testRole');
        $this->testUser->givePermissionTo('edit-articles');
        $this->testUserRole->givePermissionTo('edit-news');

        $this->assertDatabaseHas('model_has_roles', [
            'role_test_id' => $this->testUserRole->getKey(),
            'model_test_id' => $this->testUser->getKey(),
        ]);

        $this->assertDatabaseHas('model_has_permissions', [
            'permission_test_id' => $this->testUserPermission->getKey(),
            'model_test_id' => $this->testUser->getKey(),
        ]);

        $this->assertDatabaseHas('role_has_permissions', [
            'role_test_id' => $this->testUserRole->getKey(),
            'permission_test_id' => $this->app->make(Permission::class)::findByName('edit-news')->getKey(),
        ]);
    }

    public function testDeniedPermissionUpdatesExistingCustomKeyAssignmentEdge(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $this->testUser->denyPermissionTo('edit-articles');

        $this->testUser->refresh();

        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertTrue($this->testUser->hasDeniedPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
    }
}
