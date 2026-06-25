<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Models\RoleTest;

use BackedEnum;
use Hypervel\Permission\Contracts\Role;
use Hypervel\Permission\Exceptions\GuardDoesNotMatch;
use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use Hypervel\Permission\Exceptions\RoleAlreadyExists;
use Hypervel\Permission\Exceptions\RoleDoesNotExist;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Tests\Permission\Fixtures\Models\Admin;
use Hypervel\Tests\Permission\Fixtures\Models\RuntimeRole;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

enum TestRoleEnum: string
{
    case TestRole = 'test-role';
}

class RoleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'other-permission']);
        Permission::create(['name' => 'wrong-guard-permission', 'guard_name' => 'admin']);
    }

    public function testItGetsUserModelsUsingWith(): void
    {
        $this->testUser->assignRole($this->testUserRole);

        $role = $this->app->make(Role::class)::with('users')
            ->where($this->testUserRole->getKeyName(), $this->testUserRole->getKey())
            ->first();

        $this->assertSame($this->testUserRole->getKey(), $role->getKey());
        $this->assertCount(1, $role->users);
        $this->assertSame($this->testUser->id, $role->users[0]->id);
    }

    public function testItHasUserModelsOfTheRightClass(): void
    {
        $this->testAdmin->assignRole($this->testAdminRole);
        $this->testUser->assignRole($this->testUserRole);

        $this->assertCount(1, $this->testUserRole->users);
        $this->assertTrue($this->testUserRole->users->first()->is($this->testUser));
        $this->assertInstanceOf(User::class, $this->testUserRole->users->first());

        $this->assertCount(1, $this->testAdminRole->users);
        $this->assertTrue($this->testAdminRole->users->first()->is($this->testAdmin));
        $this->assertInstanceOf(Admin::class, $this->testAdminRole->users->first());
    }

    #[DataProvider('roleNameProvider')]
    public function testItCanBeCreated(string|BackedEnum $name, string $expected): void
    {
        $role = $this->app->make(Role::class)->create(['name' => $name]);

        $this->assertSame($expected, $role->name);
    }

    #[DataProvider('roleNameOnlyProvider')]
    public function testItThrowsAnExceptionWhenTheRoleAlreadyExists(string|BackedEnum $name): void
    {
        $this->app->make(Role::class)->create(['name' => $name]);

        $this->expectException(RoleAlreadyExists::class);

        $this->app->make(Role::class)->create(['name' => $name]);
    }

    /**
     * Provide role names without expected values.
     */
    public static function roleNameOnlyProvider(): array
    {
        return [
            'string' => ['test-role'],
            'enum' => [TestRoleEnum::TestRole],
        ];
    }

    public function testItCanBeGivenAPermission(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->assertTrue($this->testUserRole->hasPermissionTo('edit-articles'));
    }

    public function testItThrowsAnExceptionWhenGivenAPermissionThatDoesNotExist(): void
    {
        $this->expectException(PermissionDoesNotExist::class);

        $this->testUserRole->givePermissionTo('create-evil-empire');
    }

    public function testItThrowsAnExceptionWhenGivenAPermissionThatBelongsToAnotherGuard(): void
    {
        $this->expectException(PermissionDoesNotExist::class);

        $this->testUserRole->givePermissionTo('admin-permission');
    }

    public function testItThrowsGuardMismatchWhenGivenAPermissionObjectFromAnotherGuard(): void
    {
        $this->expectException(GuardDoesNotMatch::class);

        $this->testUserRole->givePermissionTo($this->testAdminPermission);
    }

    public function testItCanBeGivenMultiplePermissionsUsingAnArray(): void
    {
        $this->testUserRole->givePermissionTo(['edit-articles', 'edit-news']);

        $this->assertTrue($this->testUserRole->hasPermissionTo('edit-articles'));
        $this->assertTrue($this->testUserRole->hasPermissionTo('edit-news'));
    }

    public function testItCanBeGivenMultiplePermissionsUsingMultipleArguments(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles', 'edit-news');

        $this->assertTrue($this->testUserRole->hasPermissionTo('edit-articles'));
        $this->assertTrue($this->testUserRole->hasPermissionTo('edit-news'));
    }

    public function testItCanSyncPermissions(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->testUserRole->syncPermissions('edit-news');

        $this->assertFalse($this->testUserRole->hasPermissionTo('edit-articles'));
        $this->assertTrue($this->testUserRole->hasPermissionTo('edit-news'));
    }

    public function testItThrowsAnExceptionWhenSyncingPermissionsThatDoNotExist(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->expectException(PermissionDoesNotExist::class);

        $this->testUserRole->syncPermissions('permission-does-not-exist');
    }

    public function testItThrowsAnExceptionWhenSyncingPermissionsThatBelongToADifferentGuardByName(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->expectException(PermissionDoesNotExist::class);

        $this->testUserRole->syncPermissions('admin-permission');
    }

    public function testItThrowsGuardMismatchWhenSyncingPermissionObjectsFromAnotherGuard(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->expectException(GuardDoesNotMatch::class);

        $this->testUserRole->syncPermissions($this->testAdminPermission);
    }

    public function testSyncPermissionErrorDoesNotDetachPermissions(): void
    {
        $this->testUserRole->givePermissionTo('edit-news');

        try {
            $this->testUserRole->syncPermissions('edit-articles', 'permission-that-does-not-exist');
        } catch (PermissionDoesNotExist) {
            $this->assertTrue($this->testUserRole->fresh()->hasDirectPermission('edit-news'));

            return;
        }

        $this->fail('Expected missing permission exception.');
    }

    public function testItCanRevokeAPermission(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->assertTrue($this->testUserRole->hasPermissionTo('edit-articles'));

        $this->testUserRole->revokePermissionTo('edit-articles');
        $this->testUserRole = $this->testUserRole->fresh();

        $this->assertFalse($this->testUserRole->hasPermissionTo('edit-articles'));
    }

    public function testItCanBeGivenAPermissionUsingObjects(): void
    {
        $this->testUserRole->givePermissionTo($this->testUserPermission);

        $this->assertTrue($this->testUserRole->hasPermissionTo($this->testUserPermission));
    }

    public function testItReturnsFalseIfItDoesNotHaveThePermission(): void
    {
        $this->assertFalse($this->testUserRole->hasPermissionTo('other-permission'));
    }

    public function testItThrowsAnExceptionIfThePermissionDoesNotExist(): void
    {
        $this->expectException(PermissionDoesNotExist::class);

        $this->testUserRole->hasPermissionTo('doesnt-exist');
    }

    #[DataProvider('roleNameProvider')]
    public function testItCanBeFoundByName(string|BackedEnum $name, string $expected): void
    {
        $this->app->make(Role::class)->create(['name' => $name]);

        $role = $this->app->make(Role::class)->findByName($name);

        $this->assertSame($expected, $role->name);
    }

    public function testItReturnsFalseIfItDoesNotHaveAPermissionObject(): void
    {
        $permission = Permission::findByName('other-permission');

        $this->assertFalse($this->testUserRole->hasPermissionTo($permission));
    }

    public function testItCreatesPermissionObjectWithFindOrCreateIfItDoesNotHaveAPermissionObject(): void
    {
        $permission = Permission::findOrCreate('another-permission');

        $this->assertFalse($this->testUserRole->hasPermissionTo($permission));

        $this->testUserRole->givePermissionTo($permission);
        $this->testUserRole = $this->testUserRole->fresh();

        $this->assertTrue($this->testUserRole->hasPermissionTo('another-permission'));
    }

    #[DataProvider('roleNameProvider')]
    public function testItCreatesARoleWithFindOrCreateIfTheNamedRoleDoesNotExist(string|BackedEnum $name, string $expected): void
    {
        try {
            $this->app->make(Role::class)->findByName($name);
        } catch (RoleDoesNotExist) {
            $role = $this->app->make(Role::class)->findOrCreate($name);

            $this->assertSame($expected, $role->name);

            return;
        }

        $this->fail('Expected missing role exception.');
    }

    /**
     * Provide role names.
     */
    public static function roleNameProvider(): array
    {
        return [
            'string' => ['test-role', 'test-role'],
            'enum' => [TestRoleEnum::TestRole, TestRoleEnum::TestRole->value],
        ];
    }

    public function testItBelongsToAGuard(): void
    {
        $role = $this->app->make(Role::class)->create(['name' => 'admin', 'guard_name' => 'admin']);

        $this->assertSame('admin', $role->guard_name);
    }

    public function testItBelongsToTheDefaultGuardByDefault(): void
    {
        $this->assertSame(
            $this->app->make('config')->get('auth.defaults.guard'),
            $this->testUserRole->guard_name,
        );
    }

    public function testItCanChangeRoleClassAtRuntime(): void
    {
        $role = $this->app->make(Role::class)->create(['name' => 'test-role-old']);

        $this->assertNotInstanceOf(RuntimeRole::class, $role);

        $role->givePermissionTo('edit-articles');

        $this->app->make('config')->set('permission.models.role', RuntimeRole::class);
        $this->app->bind(Role::class, RuntimeRole::class);
        $this->app->make(PermissionRegistrar::class)->setRoleClass(RuntimeRole::class);

        $permission = Permission::findByName('edit-articles');
        $this->assertInstanceOf(RuntimeRole::class, $permission->roles[0]);
        $this->assertSame('test-role-old', $permission->roles[0]->name);

        $role = $this->app->make(Role::class)->create(['name' => 'test-role']);
        $this->assertInstanceOf(RuntimeRole::class, $role);

        $this->testUser->assignRole('test-role');
        $this->assertTrue($this->testUser->hasRole('test-role'));
        $this->assertInstanceOf(RuntimeRole::class, $this->testUser->roles[0]);
        $this->assertSame('test-role', $this->testUser->roles[0]->name);
    }

    public function testItDoesNotTreatStringZeroAsEmptyWhenAssigningRole(): void
    {
        $this->app->make(Role::class)->create(['name' => '0']);

        $this->testUser->assignRole('0');

        $this->assertTrue($this->testUser->hasRole('0'));
    }
}
