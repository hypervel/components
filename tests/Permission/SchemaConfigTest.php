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

    public function testMigrationUsesConventionalPivotKeysWhenTheyAreOmitted(): void
    {
        $original = config()->array('permission');
        $originalDatabase = config()->string('database.default');
        config()->set([
            'database.default' => 'permission_defaults',
            'database.connections.permission_defaults' => config()->array('database.connections.testing'),
            'permission.table_names' => [
                'roles' => 'default_roles',
                'permissions' => 'default_permissions',
                'model_has_permissions' => 'default_model_has_permissions',
                'model_has_roles' => 'default_model_has_roles',
                'role_has_permissions' => 'default_role_has_permissions',
            ],
            'permission.column_names' => [
                'model_morph_key' => 'model_id',
            ],
            'permission.teams' => false,
            'permission.cache.keys' => [],
        ]);
        $migration = require dirname(__DIR__, 2)
            . '/src/permission/database/migrations/2025_07_02_000000_create_permission_tables.php';

        try {
            $migration->up();

            $this->assertTrue(Schema::hasColumn('default_role_has_permissions', 'role_id'));
            $this->assertTrue(Schema::hasColumn('default_role_has_permissions', 'permission_id'));
            $this->assertTrue(Schema::hasColumn('default_model_has_roles', 'role_id'));
            $this->assertTrue(Schema::hasColumn('default_model_has_permissions', 'permission_id'));
        } finally {
            $migration->down();
            config()->set('permission', $original);
            config()->set('database.default', $originalDatabase);
        }
    }

    public function testTeamMigrationAcceptsOmittedOptionalColumnNamesWhenTeamsAreDisabled(): void
    {
        $original = config()->array('permission');
        config()->set([
            'permission.teams' => false,
            'permission.column_names' => [
                'model_morph_key' => 'model_id',
            ],
        ]);
        $migration = require dirname(__DIR__, 2)
            . '/src/permission/database/migrations/add_teams_fields.php.stub';

        try {
            $migration->up();

            $this->addToAssertionCount(1);
        } finally {
            config()->set('permission', $original);
        }
    }
}
