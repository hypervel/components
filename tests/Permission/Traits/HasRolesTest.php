<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Traits;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Contracts\Permission;
use Hypervel\Permission\Contracts\Role;
use Hypervel\Permission\Events\RoleAttachedEvent;
use Hypervel\Permission\Events\RoleDetachedEvent;
use Hypervel\Permission\Exceptions\GuardDoesNotMatch;
use Hypervel\Permission\Exceptions\RoleDoesNotExist;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Permission\Fixtures\Models\Admin;
use Hypervel\Tests\Permission\Fixtures\Models\SoftDeletingUser;
use Hypervel\Tests\Permission\Fixtures\Models\TestRolePermissionsEnum;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\TestCase;
use TypeError;

class HasRolesTest extends TestCase
{
    public function testItCanDetermineThatTheUserDoesNotHaveARole(): void
    {
        $this->assertFalse($this->testUser->hasRole('testRole'));

        $role = app(Role::class)->findOrCreate('testRoleInWebGuard', 'web');

        $this->assertFalse($this->testUser->hasRole($role));

        $this->testUser->assignRole($role);
        $this->assertTrue($this->testUser->hasRole($role));
        $this->assertTrue($this->testUser->hasRole($role->name));
        $this->assertTrue($this->testUser->hasRole($role->name, $role->guard_name));
        $this->assertTrue($this->testUser->hasRole([$role->name, 'fakeRole'], $role->guard_name));
        $this->assertTrue($this->testUser->hasRole($role->getKey(), $role->guard_name));
        $this->assertTrue($this->testUser->hasRole([$role->getKey(), 'fakeRole'], $role->guard_name));

        $this->assertFalse($this->testUser->hasRole($role->name, 'fakeGuard'));
        $this->assertFalse($this->testUser->hasRole([$role->name, 'fakeRole'], 'fakeGuard'));
        $this->assertFalse($this->testUser->hasRole($role->getKey(), 'fakeGuard'));
        $this->assertFalse($this->testUser->hasRole([$role->getKey(), 'fakeRole'], 'fakeGuard'));

        $role = app(Role::class)->findOrCreate('testRoleInWebGuard2', 'web');
        $this->assertFalse($this->testUser->hasRole($role));
    }

    public function testItCanAssignAndRemoveARoleUsingEnums(): void
    {
        $enum1 = TestRolePermissionsEnum::UserManager;
        $enum2 = TestRolePermissionsEnum::Writer;
        $enum3 = TestRolePermissionsEnum::CastedEnum1;
        $enum4 = TestRolePermissionsEnum::CastedEnum2;

        app(Role::class)->findOrCreate($enum1->value, 'web');
        app(Role::class)->findOrCreate($enum2->value, 'web');
        app(Role::class)->findOrCreate($enum3->value, 'web');
        app(Role::class)->findOrCreate($enum4->value, 'web');

        $this->assertFalse($this->testUser->hasRole($enum1));
        $this->assertFalse($this->testUser->hasRole($enum2));
        $this->assertFalse($this->testUser->hasRole($enum3));
        $this->assertFalse($this->testUser->hasRole($enum4));
        $this->assertFalse($this->testUser->hasRole('user-manager'));
        $this->assertFalse($this->testUser->hasRole('writer'));
        $this->assertFalse($this->testUser->hasRole('casted_enum-1'));
        $this->assertFalse($this->testUser->hasRole('casted_enum-2'));

        $this->testUser->assignRole($enum1);
        $this->testUser->assignRole($enum2);
        $this->testUser->assignRole($enum3);
        $this->testUser->assignRole($enum4);

        $this->assertTrue($this->testUser->hasRole($enum1));
        $this->assertTrue($this->testUser->hasRole($enum2));
        $this->assertTrue($this->testUser->hasRole($enum3));
        $this->assertTrue($this->testUser->hasRole($enum4));

        $this->assertTrue($this->testUser->hasRole([$enum1, 'writer']));
        $this->assertTrue($this->testUser->hasRole([$enum3, 'casted_enum-2']));

        $this->assertTrue($this->testUser->hasAllRoles([$enum1, $enum2, $enum3, $enum4]));
        $this->assertTrue($this->testUser->hasAllRoles(['user-manager', 'writer', 'casted_enum-1', 'casted_enum-2']));
        $this->assertFalse($this->testUser->hasAllRoles([$enum1, $enum2, $enum3, $enum4, 'not exist']));
        $this->assertFalse($this->testUser->hasAllRoles(['user-manager', 'writer', 'casted_enum-1', 'casted_enum-2', 'not exist']));

        $this->assertTrue($this->testUser->hasExactRoles([$enum4, $enum3, $enum2, $enum1]));
        $this->assertTrue($this->testUser->hasExactRoles(['user-manager', 'writer', 'casted_enum-1', 'casted_enum-2']));

        $this->testUser->removeRole($enum1);

        $this->assertFalse($this->testUser->hasRole($enum1));
    }

    public function testItCanScopeARoleUsingEnums(): void
    {
        $enum1 = TestRolePermissionsEnum::UserManager;
        $enum2 = TestRolePermissionsEnum::Writer;
        app(Role::class)->findOrCreate($enum1->value, 'web');
        app(Role::class)->findOrCreate($enum2->value, 'web');

        User::all()->each(fn ($item) => $item->delete());
        User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        User::create(['email' => 'user3@test.com']);

        $user2->assignRole($enum1);
        $this->assertTrue($user2->hasRole($enum1));
        $this->assertFalse($user2->hasRole($enum2));

        $this->assertCount(1, User::role($enum1)->get());
        $this->assertCount(0, User::role($enum2)->get());
        $this->assertCount(3, User::withoutRole($enum2)->get());
    }

    public function testItCanAssignAndRemoveARole(): void
    {
        $this->assertFalse($this->testUser->hasRole('testRole'));

        $this->testUser->assignRole('testRole');

        $this->assertTrue($this->testUser->hasRole('testRole'));

        $this->testUser->removeRole('testRole');

        $this->assertFalse($this->testUser->hasRole('testRole'));
    }

    public function testItRemovesARoleAndReturnsRoles(): void
    {
        $this->testUser->assignRole('testRole');
        $this->testUser->assignRole('testRole2');

        $this->assertTrue($this->testUser->hasRole(['testRole', 'testRole2']));

        $roles = $this->testUser->removeRole('testRole');

        $this->assertFalse($roles->hasRole('testRole'));
        $this->assertTrue($roles->hasRole('testRole2'));
    }

    public function testItCanAssignAndRemoveARoleOnAPermission(): void
    {
        $this->testUserPermission->assignRole('testRole');

        $this->assertTrue($this->testUserPermission->hasRole('testRole'));

        $this->testUserPermission->removeRole('testRole');

        $this->assertFalse($this->testUserPermission->hasRole('testRole'));
    }

    public function testItCanAssignAndRemoveARoleUsingAnObject(): void
    {
        $this->testUser->assignRole($this->testUserRole);

        $this->assertTrue($this->testUser->hasRole($this->testUserRole));

        $this->testUser->removeRole($this->testUserRole);

        $this->assertFalse($this->testUser->hasRole($this->testUserRole));
    }

    public function testItCanAssignAndRemoveARoleUsingAnId(): void
    {
        $this->testUser->assignRole($this->testUserRole->getKey());

        $this->assertTrue($this->testUser->hasRole($this->testUserRole));

        $this->testUser->removeRole($this->testUserRole->getKey());

        $this->assertFalse($this->testUser->hasRole($this->testUserRole));
    }

    public function testMalformedQuotedPipeRoleStringDoesNotTrimLeadingQuote(): void
    {
        app(Role::class)->create(['name' => 'admin']);

        $this->testUser->assignRole('admin');

        $this->assertFalse($this->testUser->hasRole('"admin|editor'));
    }

    public function testItCanAssignAndRemoveMultipleRolesAtOnce(): void
    {
        $this->testUser->assignRole($this->testUserRole->getKey(), 'testRole2');

        $this->assertTrue($this->testUser->hasRole('testRole'));
        $this->assertTrue($this->testUser->hasRole('testRole2'));

        $this->testUser->removeRole($this->testUserRole->getKey(), 'testRole2');

        $this->assertFalse($this->testUser->hasRole('testRole'));
        $this->assertFalse($this->testUser->hasRole('testRole2'));
    }

    public function testItCanAssignAndRemoveMultipleRolesUsingAnArray(): void
    {
        $this->testUser->assignRole([$this->testUserRole->getKey(), 'testRole2']);

        $this->assertTrue($this->testUser->hasRole('testRole'));
        $this->assertTrue($this->testUser->hasRole('testRole2'));

        $this->testUser->removeRole([$this->testUserRole->getKey(), 'testRole2']);

        $this->assertFalse($this->testUser->hasRole('testRole'));
        $this->assertFalse($this->testUser->hasRole('testRole2'));
    }

    public function testItDoesNotRemoveAlreadyAssociatedRolesWhenAssigningNewRoles(): void
    {
        $this->testUser->assignRole($this->testUserRole->getKey());
        $this->testUser->assignRole('testRole2');

        $this->assertTrue($this->testUser->fresh()->hasRole('testRole'));
    }

    public function testItDoesNotThrowAnExceptionWhenAssigningARoleThatIsAlreadyAssigned(): void
    {
        $this->testUser->assignRole($this->testUserRole->getKey());
        $this->testUser->assignRole($this->testUserRole->getKey());

        $this->assertTrue($this->testUser->fresh()->hasRole('testRole'));
    }

    public function testItThrowsAnExceptionWhenAssigningARoleThatDoesNotExist(): void
    {
        $this->expectException(RoleDoesNotExist::class);

        $this->testUser->assignRole('evil-emperor');
    }

    public function testItCanOnlyAssignRolesFromTheCorrectGuard(): void
    {
        $this->expectException(RoleDoesNotExist::class);

        $this->testUser->assignRole('testAdminRole');
    }

    public function testItThrowsAnExceptionWhenAssigningARoleFromADifferentGuard(): void
    {
        $this->expectException(GuardDoesNotMatch::class);

        $this->testUser->assignRole($this->testAdminRole);
    }

    public function testItIgnoresNullRolesWhenSyncing(): void
    {
        $this->testUser->assignRole('testRole');

        $this->testUser->syncRoles('testRole2', null);

        $this->assertFalse($this->testUser->hasRole('testRole'));
        $this->assertTrue($this->testUser->hasRole('testRole2'));
    }

    public function testItCanSyncRolesFromAString(): void
    {
        $this->testUser->assignRole('testRole');

        $this->testUser->syncRoles('testRole2');

        $this->assertFalse($this->testUser->hasRole('testRole'));
        $this->assertTrue($this->testUser->hasRole('testRole2'));
    }

    public function testItCanSyncRolesFromAStringOnAPermission(): void
    {
        $this->testUserPermission->assignRole('testRole');

        $this->testUserPermission->syncRoles('testRole2');

        $this->assertFalse($this->testUserPermission->hasRole('testRole'));
        $this->assertTrue($this->testUserPermission->hasRole('testRole2'));
    }

    public function testItCanAvoidSyncDuplicatedRoles(): void
    {
        $this->testUser->syncRoles('testRole', 'testRole', 'testRole2');

        $this->assertTrue($this->testUser->hasRole('testRole'));
        $this->assertTrue($this->testUser->hasRole('testRole2'));
    }

    public function testItCanAvoidDetachOnRoleThatDoesNotExistSync(): void
    {
        $this->testUser->syncRoles('testRole');

        try {
            $this->testUser->syncRoles('role-does-not-exist');
            $this->fail('Expected role does not exist exception was not thrown.');
        } catch (RoleDoesNotExist) {
            $this->assertTrue($this->testUser->hasRole('testRole'));
            $this->assertFalse($this->testUser->hasRole('role-does-not-exist'));
        }
    }

    public function testItCanSyncMultipleRoles(): void
    {
        $this->testUser->syncRoles('testRole', 'testRole2');

        $this->assertTrue($this->testUser->hasRole('testRole'));
        $this->assertTrue($this->testUser->hasRole('testRole2'));
    }

    public function testItCanSyncMultipleRolesFromAnArray(): void
    {
        $this->testUser->syncRoles(['testRole', 'testRole2']);

        $this->assertTrue($this->testUser->hasRole('testRole'));
        $this->assertTrue($this->testUser->hasRole('testRole2'));
    }

    public function testItWillRemoveAllRolesWhenAnEmptyArrayIsPassedToSyncRoles(): void
    {
        $this->testUser->assignRole('testRole');
        $this->testUser->assignRole('testRole2');

        $this->testUser->syncRoles([]);

        $this->assertFalse($this->testUser->hasRole('testRole'));
        $this->assertFalse($this->testUser->hasRole('testRole2'));
    }

    public function testItDoesNotDetachRolesWhenSyncRolesErrors(): void
    {
        $this->testUser->assignRole('testRole');

        try {
            $this->testUser->syncRoles('testRole2', 'role-that-does-not-exist');
            $this->fail('Expected role does not exist exception was not thrown.');
        } catch (RoleDoesNotExist) {
            $this->assertTrue($this->testUser->fresh()->hasRole('testRole'));
        }
    }

    public function testItWillSyncRolesToAModelThatIsNotPersisted(): void
    {
        $user = new User(['email' => 'test@user.com']);
        $user->syncRoles([$this->testUserRole]);
        $user->save();
        $user->save();

        $this->assertTrue($user->hasRole($this->testUserRole));

        $user->syncRoles([$this->testUserRole]);
        $this->assertTrue($user->hasRole($this->testUserRole));
        $this->assertTrue($user->fresh()->hasRole($this->testUserRole));
    }

    public function testItDoesNotRunUnnecessarySqlWhenAssigningNewRoles(): void
    {
        $role2 = app(Role::class)->where('name', 'testRole2')->first();

        DB::enableQueryLog();
        $this->testUser->syncRoles($this->testUserRole, $role2);
        DB::disableQueryLog();

        $this->assertCount(2, DB::getQueryLog());
    }

    public function testItDoesNotLetQueuedSyncRolesInterfereWithOtherObjects(): void
    {
        $user = new User(['email' => 'test@user.com']);
        $user->syncRoles('testRole');
        $user->save();

        $user2 = new User(['email' => 'admin@user.com']);
        $user2->syncRoles('testRole2');

        DB::enableQueryLog();
        $user2->save();
        DB::disableQueryLog();

        $this->assertTrue($user->fresh()->hasRole('testRole'));
        $this->assertFalse($user->fresh()->hasRole('testRole2'));

        $this->assertTrue($user2->fresh()->hasRole('testRole2'));
        $this->assertFalse($user2->fresh()->hasRole('testRole'));
        $this->assertCount(2, DB::getQueryLog());
    }

    public function testItDoesNotLetQueuedAssignRoleInterfereWithOtherObjects(): void
    {
        $user = new User(['email' => 'test@user.com']);
        $user->assignRole('testRole');
        $user->save();

        $adminUser = new User(['email' => 'admin@user.com']);
        $adminUser->assignRole('testRole2');

        DB::enableQueryLog();
        $adminUser->save();
        DB::disableQueryLog();

        $this->assertTrue($user->fresh()->hasRole('testRole'));
        $this->assertFalse($user->fresh()->hasRole('testRole2'));

        $this->assertTrue($adminUser->fresh()->hasRole('testRole2'));
        $this->assertFalse($adminUser->fresh()->hasRole('testRole'));
        $this->assertCount(2, DB::getQueryLog());
    }

    public function testItThrowsAnExceptionWhenSyncingARoleFromAnotherGuard(): void
    {
        try {
            $this->testUser->syncRoles('testRole', 'testAdminRole');
            $this->fail('Expected role does not exist exception was not thrown.');
        } catch (RoleDoesNotExist) {
            $this->assertTrue(true);
        }

        $this->expectException(GuardDoesNotMatch::class);

        $this->testUser->syncRoles('testRole', $this->testAdminRole);
    }

    public function testItDeletesPivotTableEntriesWhenDeletingModels(): void
    {
        $user = User::create(['email' => 'user@test.com']);

        $user->assignRole('testRole');
        $user->givePermissionTo('edit-articles');

        $this->assertDatabaseHas('model_has_permissions', [config('permission.column_names.model_morph_key') => $user->id]);
        $this->assertDatabaseHas('model_has_roles', [config('permission.column_names.model_morph_key') => $user->id]);

        $user->delete();

        $this->assertDatabaseMissing('model_has_permissions', [config('permission.column_names.model_morph_key') => $user->id]);
        $this->assertDatabaseMissing('model_has_roles', [config('permission.column_names.model_morph_key') => $user->id]);
    }

    public function testItCanScopeUsersUsingAString(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        User::create(['email' => 'user2@test.com']);
        $user1->assignRole('testRole');

        $this->assertCount(1, User::role('testRole')->get());
    }

    public function testItCanWithoutScopeUsersUsingAString(): void
    {
        User::all()->each(fn ($item) => $item->delete());
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user3 = User::create(['email' => 'user3@test.com']);
        $user1->assignRole('testRole');
        $user2->assignRole('testRole2');
        $user3->assignRole('testRole2');

        $this->assertCount(1, User::withoutRole('testRole2')->get());
    }

    public function testItCanScopeUsersUsingAnArray(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user1->assignRole($this->testUserRole);
        $user2->assignRole('testRole2');

        $this->assertCount(1, User::role([$this->testUserRole])->get());
        $this->assertCount(2, User::role(['testRole', 'testRole2'])->get());
    }

    public function testItCanWithoutScopeUsersUsingAnArray(): void
    {
        User::all()->each(fn ($item) => $item->delete());
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user3 = User::create(['email' => 'user3@test.com']);
        $user1->assignRole($this->testUserRole);
        $user2->assignRole('testRole2');
        $user3->assignRole('testRole2');

        $this->assertCount(2, User::withoutRole([$this->testUserRole])->get());
        $this->assertCount(0, User::withoutRole([$this->testUserRole->name, 'testRole2'])->get());
    }

    public function testItCanScopeUsersUsingAnArrayOfIdsAndNames(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user1->assignRole($this->testUserRole);
        $user2->assignRole('testRole2');

        $firstAssignedRoleName = $this->testUserRole->name;
        $secondAssignedRoleId = app(Role::class)->findByName('testRole2')->getKey();

        $this->assertCount(2, User::role([$firstAssignedRoleName, $secondAssignedRoleId])->get());
    }

    public function testItCanWithoutScopeUsersUsingAnArrayOfIdsAndNames(): void
    {
        app(Role::class)->create(['name' => 'testRole3']);

        User::all()->each(fn ($item) => $item->delete());
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user3 = User::create(['email' => 'user3@test.com']);
        $user1->assignRole($this->testUserRole);
        $user2->assignRole('testRole2');
        $user3->assignRole('testRole2');

        $firstAssignedRoleName = $this->testUserRole->name;
        $unassignedRoleId = app(Role::class)->findByName('testRole3')->getKey();

        $this->assertCount(2, User::withoutRole([$firstAssignedRoleName, $unassignedRoleId])->get());
    }

    public function testItCanScopeUsersUsingACollection(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user1->assignRole($this->testUserRole);
        $user2->assignRole('testRole2');

        $this->assertCount(1, User::role([$this->testUserRole])->get());
        $this->assertCount(2, User::role(collect(['testRole', 'testRole2']))->get());
    }

    public function testItCanWithoutScopeUsersUsingACollection(): void
    {
        app(Role::class)->create(['name' => 'testRole3']);

        User::all()->each(fn ($item) => $item->delete());
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user3 = User::create(['email' => 'user3@test.com']);
        $user1->assignRole($this->testUserRole);
        $user2->assignRole('testRole');
        $user3->assignRole('testRole2');

        $this->assertCount(1, User::withoutRole([$this->testUserRole])->get());
        $this->assertCount(1, User::withoutRole(collect(['testRole', 'testRole3']))->get());
    }

    public function testItCanScopeUsersUsingAnObject(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        User::create(['email' => 'user2@test.com'])->assignRole('testRole2');
        $user1->assignRole($this->testUserRole);

        $this->assertCount(1, User::role($this->testUserRole)->get());
        $this->assertCount(1, User::role([$this->testUserRole])->get());
        $this->assertCount(1, User::role(collect([$this->testUserRole]))->get());
    }

    public function testItCanWithoutScopeUsersUsingAnObject(): void
    {
        User::all()->each(fn ($item) => $item->delete());
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user3 = User::create(['email' => 'user3@test.com']);
        $user1->assignRole($this->testUserRole);
        $user2->assignRole('testRole2');
        $user3->assignRole('testRole2');

        $this->assertCount(2, User::withoutRole($this->testUserRole)->get());
        $this->assertCount(2, User::withoutRole([$this->testUserRole])->get());
        $this->assertCount(2, User::withoutRole(collect([$this->testUserRole]))->get());
    }

    public function testItCanScopeAgainstASpecificGuard(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user1->assignRole('testRole');
        $user2->assignRole('testRole2');

        $this->assertCount(1, User::role('testRole', 'web')->get());

        $user3 = Admin::create(['email' => 'user3@test.com']);
        $user4 = Admin::create(['email' => 'user4@test.com']);
        $user5 = Admin::create(['email' => 'user5@test.com']);
        $testAdminRole2 = app(Role::class)->create(['name' => 'testAdminRole2', 'guard_name' => 'admin']);
        $user3->assignRole($this->testAdminRole);
        $user4->assignRole($this->testAdminRole);
        $user5->assignRole($testAdminRole2);

        $this->assertCount(2, Admin::role('testAdminRole', 'admin')->get());
        $this->assertCount(1, Admin::role('testAdminRole2', 'admin')->get());
    }

    public function testItCanWithoutScopeAgainstASpecificGuard(): void
    {
        User::all()->each(fn ($item) => $item->delete());
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user3 = User::create(['email' => 'user3@test.com']);
        $user1->assignRole('testRole');
        $user2->assignRole('testRole2');
        $user3->assignRole('testRole2');

        $this->assertCount(2, User::withoutRole('testRole', 'web')->get());

        Admin::with(['roles', 'permissions'])->get()->each(fn ($item) => $item->delete());
        $user4 = Admin::create(['email' => 'user4@test.com']);
        $user5 = Admin::create(['email' => 'user5@test.com']);
        $user6 = Admin::create(['email' => 'user6@test.com']);
        $testAdminRole2 = app(Role::class)->create(['name' => 'testAdminRole2', 'guard_name' => 'admin']);
        $user4->assignRole($this->testAdminRole);
        $user5->assignRole($this->testAdminRole);
        $user6->assignRole($testAdminRole2);

        $this->assertCount(1, Admin::withoutRole('testAdminRole', 'admin')->get());
        $this->assertCount(2, Admin::withoutRole('testAdminRole2', 'admin')->get());
    }

    public function testItThrowsAnExceptionWhenTryingToScopeARoleFromAnotherGuard(): void
    {
        $this->expectException(RoleDoesNotExist::class);

        User::role('testAdminRole')->get();
    }

    public function testItThrowsAnExceptionWhenTryingToCallWithoutScopeOnARoleFromAnotherGuard(): void
    {
        $this->expectException(RoleDoesNotExist::class);

        User::withoutRole('testAdminRole')->get();
    }

    public function testItThrowsAnExceptionWhenTryingToScopeANonExistingRole(): void
    {
        $this->expectException(RoleDoesNotExist::class);

        User::role('role not defined')->get();
    }

    public function testItThrowsAnExceptionWhenTryingToUseWithoutScopeOnANonExistingRole(): void
    {
        $this->expectException(RoleDoesNotExist::class);

        User::withoutRole('role not defined')->get();
    }

    public function testItCanDetermineThatAUserHasOneOfTheGivenRoles(): void
    {
        $roleModel = app(Role::class);

        $roleModel->create(['name' => 'second role']);

        $this->assertFalse($this->testUser->hasRole($roleModel->all()));

        $this->testUser->assignRole($this->testUserRole);

        $this->assertTrue($this->testUser->hasRole($roleModel->all()));
        $this->assertTrue($this->testUser->hasAnyRole($roleModel->all()));
        $this->assertTrue($this->testUser->hasAnyRole('testRole'));
        $this->assertFalse($this->testUser->hasAnyRole('role does not exist'));
        $this->assertTrue($this->testUser->hasAnyRole(['testRole']));
        $this->assertTrue($this->testUser->hasAnyRole(['testRole', 'role does not exist']));
        $this->assertFalse($this->testUser->hasAnyRole(['role does not exist']));
        $this->assertTrue($this->testUser->hasAnyRole('testRole', 'role does not exist'));
    }

    public function testItCanDetermineThatAUserHasAllOfTheGivenRoles(): void
    {
        $roleModel = app(Role::class);

        $this->assertFalse($this->testUser->hasAllRoles($roleModel->first()));
        $this->assertFalse($this->testUser->hasAllRoles('testRole'));
        $this->assertFalse($this->testUser->hasAllRoles($roleModel->all()));

        $roleModel->create(['name' => 'second role']);

        $this->testUser->assignRole($this->testUserRole);

        $this->assertTrue($this->testUser->hasAllRoles('testRole'));
        $this->assertTrue($this->testUser->hasAllRoles('testRole', 'web'));
        $this->assertFalse($this->testUser->hasAllRoles('testRole', 'fakeGuard'));

        $this->assertFalse($this->testUser->hasAllRoles(['testRole', 'second role']));
        $this->assertFalse($this->testUser->hasAllRoles(['testRole', 'second role'], 'web'));

        $this->testUser->assignRole('second role');

        $this->assertTrue($this->testUser->hasAllRoles(['testRole', 'second role']));
        $this->assertTrue($this->testUser->hasAllRoles(['testRole', 'second role'], 'web'));
        $this->assertFalse($this->testUser->hasAllRoles(['testRole', 'second role'], 'fakeGuard'));
    }

    public function testItCanDetermineThatAUserHasExactlyAllOfTheGivenRoles(): void
    {
        $roleModel = app(Role::class);

        $this->assertFalse($this->testUser->hasExactRoles($roleModel->first()));
        $this->assertFalse($this->testUser->hasExactRoles('testRole'));
        $this->assertFalse($this->testUser->hasExactRoles($roleModel->all()));

        $roleModel->create(['name' => 'second role']);

        $this->testUser->assignRole($this->testUserRole);

        $this->assertTrue($this->testUser->hasExactRoles('testRole'));
        $this->assertTrue($this->testUser->hasExactRoles('testRole', 'web'));
        $this->assertFalse($this->testUser->hasExactRoles('testRole', 'fakeGuard'));

        $this->assertFalse($this->testUser->hasExactRoles(['testRole', 'second role']));
        $this->assertFalse($this->testUser->hasExactRoles(['testRole', 'second role'], 'web'));

        $this->testUser->assignRole('second role');

        $this->assertTrue($this->testUser->hasExactRoles(['testRole', 'second role']));
        $this->assertTrue($this->testUser->hasExactRoles(['testRole', 'second role'], 'web'));
        $this->assertFalse($this->testUser->hasExactRoles(['testRole', 'second role'], 'fakeGuard'));

        $roleModel->create(['name' => 'third role']);
        $this->testUser->assignRole('third role');

        $this->assertFalse($this->testUser->hasExactRoles(['testRole', 'second role']));
        $this->assertFalse($this->testUser->hasExactRoles(['testRole', 'second role'], 'web'));
        $this->assertFalse($this->testUser->hasExactRoles(['testRole', 'second role'], 'fakeGuard'));
        $this->assertTrue($this->testUser->hasExactRoles(['testRole', 'second role', 'third role']));
        $this->assertTrue($this->testUser->hasExactRoles(['testRole', 'second role', 'third role'], 'web'));
        $this->assertFalse($this->testUser->hasExactRoles(['testRole', 'second role', 'third role'], 'fakeGuard'));
    }

    public function testItCanDetermineThatAUserDoesNotHaveARoleFromAnotherGuard(): void
    {
        $this->assertFalse($this->testUser->hasRole('testAdminRole'));
        $this->assertFalse($this->testUser->hasRole($this->testAdminRole));

        $this->testUser->assignRole('testRole');

        $this->assertTrue($this->testUser->hasAnyRole(['testRole', 'testAdminRole']));
        $this->assertFalse($this->testUser->hasAnyRole('testAdminRole', $this->testAdminRole));
    }

    public function testItCanCheckAgainstAnyMultipleRolesUsingMultipleArguments(): void
    {
        $this->testUser->assignRole('testRole');

        $this->assertTrue($this->testUser->hasAnyRole($this->testAdminRole, ['testRole'], 'This Role Does Not Even Exist'));
    }

    public function testItReturnsFalseInsteadOfAnExceptionWhenCheckingAgainstAnyUndefinedRolesUsingMultipleArguments(): void
    {
        $this->assertFalse($this->testUser->hasAnyRole('This Role Does Not Even Exist', $this->testAdminRole));
    }

    public function testItThrowsAnExceptionIfAnUnsupportedTypeIsPassedToHasRoles(): void
    {
        $this->expectException(TypeError::class);

        $this->testUser->hasRole(new class {});
    }

    public function testItCanRetrieveRoleNames(): void
    {
        $this->testUser->assignRole('testRole', 'testRole2');

        $this->assertSame(['testRole', 'testRole2'], $this->testUser->getRoleNames()->sort()->values()->all());
    }

    public function testItDoesNotDetachRolesWhenUserSoftDeleting(): void
    {
        $user = SoftDeletingUser::create(['email' => 'test@example.com']);
        $user->assignRole('testRole');
        $user->delete();

        $user = SoftDeletingUser::withTrashed()->find($user->id);

        $this->assertTrue($user->hasRole('testRole'));
    }

    public function testItFiresAnEventWhenARoleIsAdded(): void
    {
        Event::fake();
        app('config')->set('permission.events_enabled', true);

        $this->testUser->assignRole(['testRole', 'testRole2']);

        $roleIds = app(Role::class)::whereIn('name', ['testRole', 'testRole2'])
            ->pluck($this->testUserRole->getKeyName())
            ->toArray();

        Event::assertDispatched(RoleAttachedEvent::class, function (RoleAttachedEvent $event) use ($roleIds): bool {
            return $event->model instanceof User
                && $event->model->hasRole('testRole')
                && $event->model->hasRole('testRole2')
                && $event->rolesOrIds === $roleIds;
        });
    }

    public function testItFiresAnEventWhenARoleIsRemoved(): void
    {
        Event::fake();
        app('config')->set('permission.events_enabled', true);

        $this->testUser->assignRole('testRole', 'testRole2');
        $this->testUser->removeRole('testRole', 'testRole2');

        $roleIds = app(Role::class)::whereIn('name', ['testRole', 'testRole2'])
            ->pluck($this->testUserRole->getKeyName())
            ->toArray();

        Event::assertDispatched(RoleDetachedEvent::class, function (RoleDetachedEvent $event) use ($roleIds): bool {
            return $event->model instanceof User
                && ! $event->model->hasRole('testRole')
                && ! $event->model->hasRole('testRole2')
                && $event->rolesOrIds === $roleIds;
        });
    }

    public function testItCanBeGivenARoleOnPermissionWhenLazyLoadingIsRestricted(): void
    {
        $this->assertTrue(Model::preventsLazyLoading());

        $testPermission = app(Permission::class)->with('roles')->get()->first();

        $testPermission->assignRole('testRole');

        $this->assertTrue($testPermission->hasRole('testRole'));
    }

    public function testItCanBeGivenARoleOnUserWhenLazyLoadingIsRestricted(): void
    {
        $this->assertTrue(Model::preventsLazyLoading());

        User::create(['email' => 'other@user.com']);
        $user = User::with('roles')->get()->first();
        $user->assignRole('testRole');

        $this->assertTrue($user->hasRole('testRole'));
    }

    public function testItFiresDetachEventWhenSyncingRoles(): void
    {
        Event::fake([RoleDetachedEvent::class, RoleAttachedEvent::class]);
        app('config')->set('permission.events_enabled', true);

        $this->testUser->assignRole('testRole', 'testRole2');

        app(Role::class)->create(['name' => 'testRole3']);

        $this->testUser->syncRoles('testRole3');

        $this->assertFalse($this->testUser->hasRole('testRole'));
        $this->assertFalse($this->testUser->hasRole('testRole2'));
        $this->assertTrue($this->testUser->hasRole('testRole3'));

        $removedRoleIds = app(Role::class)::whereIn('name', ['testRole', 'testRole2'])
            ->pluck($this->testUserRole->getKeyName())
            ->toArray();

        Event::assertDispatched(RoleDetachedEvent::class, function (RoleDetachedEvent $event) use ($removedRoleIds): bool {
            return $event->model instanceof User
                && ! $event->model->hasRole('testRole')
                && ! $event->model->hasRole('testRole2')
                && $event->rolesOrIds === $removedRoleIds;
        });

        $attachedRoleIds = app(Role::class)::whereIn('name', ['testRole3'])
            ->pluck($this->testUserRole->getKeyName())
            ->toArray();

        Event::assertDispatched(RoleAttachedEvent::class, function (RoleAttachedEvent $event) use ($attachedRoleIds): bool {
            return $event->model instanceof User
                && $event->model->hasRole('testRole3')
                && $event->rolesOrIds === $attachedRoleIds;
        });
    }
}
