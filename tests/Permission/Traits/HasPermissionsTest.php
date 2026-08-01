<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Traits;

use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Contracts\Permission;
use Hypervel\Permission\Contracts\Role;
use Hypervel\Permission\Events\PermissionAttachedEvent;
use Hypervel\Permission\Events\PermissionDetachedEvent;
use Hypervel\Permission\Exceptions\GuardDoesNotMatch;
use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Permission\Fixtures\Models\SoftDeletingUser;
use Hypervel\Tests\Permission\Fixtures\Models\TestRolePermissionsEnum;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class HasPermissionsTest extends TestCase
{
    public function testItCanAssignAPermissionToAUser(): void
    {
        $this->testUser->givePermissionTo($this->testUserPermission);

        $this->assertTrue($this->testUser->hasPermissionTo($this->testUserPermission));
    }

    public function testItCanAssignAPermissionToAUserWithANonDefaultGuard(): void
    {
        $testUserPermission = app(Permission::class)->create([
            'name' => 'edit-articles',
            'guard_name' => 'api',
        ]);

        $this->testUser->givePermissionTo($testUserPermission);

        $this->assertTrue($this->testUser->hasPermissionTo($testUserPermission));
    }

    public function testItThrowsAnExceptionWhenAssigningAPermissionThatDoesNotExist(): void
    {
        $this->expectException(PermissionDoesNotExist::class);

        $this->testUser->givePermissionTo('permission-does-not-exist');
    }

    public function testItThrowsAnExceptionWhenAssigningAPermissionToAUserFromADifferentGuard(): void
    {
        try {
            $this->testUser->givePermissionTo($this->testAdminPermission);
            $this->fail('Expected guard mismatch exception was not thrown.');
        } catch (GuardDoesNotMatch) {
            $this->assertTrue(true);
        }

        $this->expectException(PermissionDoesNotExist::class);

        $this->testUser->givePermissionTo('admin-permission');
    }

    public function testItCanRevokeAPermissionFromAUser(): void
    {
        $this->testUser->givePermissionTo($this->testUserPermission);

        $this->assertTrue($this->testUser->hasPermissionTo($this->testUserPermission));

        $this->testUser->revokePermissionTo($this->testUserPermission);

        $this->assertFalse($this->testUser->hasPermissionTo($this->testUserPermission));
    }

    public function testItCanAssignAndRemoveAPermissionUsingEnums(): void
    {
        $enum = TestRolePermissionsEnum::ViewArticles;

        app(Permission::class)->findOrCreate($enum->value, 'web');

        $this->testUser->givePermissionTo($enum);

        $this->assertTrue($this->testUser->hasPermissionTo($enum));
        $this->assertTrue($this->testUser->hasAnyPermission($enum));
        $this->assertTrue($this->testUser->hasDirectPermission($enum));

        $this->testUser->revokePermissionTo($enum);

        $this->assertFalse($this->testUser->hasPermissionTo($enum));
        $this->assertFalse($this->testUser->hasAnyPermission($enum));
        $this->assertFalse($this->testUser->hasDirectPermission($enum));
    }

    public function testItCanScopeUsersUsingEnums(): void
    {
        $enum1 = TestRolePermissionsEnum::ViewArticles;
        $enum2 = TestRolePermissionsEnum::EditArticles;
        app(Permission::class)->findOrCreate($enum1->value, 'web');
        app(Permission::class)->findOrCreate($enum2->value, 'web');

        User::all()->each(fn ($item) => $item->delete());
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        User::create(['email' => 'user3@test.com']);
        $user1->givePermissionTo([$enum1, $enum2]);
        $this->testUserRole->givePermissionTo($enum2);
        $user2->assignRole('testRole');

        $this->assertCount(2, User::permission($enum2)->get());
        $this->assertCount(1, User::permission([$enum1])->get());
        $this->assertCount(2, User::withoutPermission([$enum1])->get());
        $this->assertCount(1, User::withoutPermission([$enum2])->get());
    }

    public function testItCanScopeUsersUsingAString(): void
    {
        User::all()->each(fn ($item) => $item->delete());
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        User::create(['email' => 'user3@test.com']);
        $user1->givePermissionTo(['edit-articles', 'edit-news']);
        $this->testUserRole->givePermissionTo('edit-articles');
        $user2->assignRole('testRole');

        $this->assertCount(2, User::permission('edit-articles')->get());
        $this->assertCount(1, User::permission(['edit-news'])->get());
        $this->assertCount(2, User::withoutPermission('edit-news')->get());
    }

    public function testItCanScopeUsersUsingAnInt(): void
    {
        User::all()->each(fn ($item) => $item->delete());
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        User::create(['email' => 'user3@test.com']);
        $user1->givePermissionTo([1, 2]);
        $this->testUserRole->givePermissionTo(1);
        $user2->assignRole('testRole');

        $this->assertCount(2, User::permission(1)->get());
        $this->assertCount(1, User::permission([2])->get());
        $this->assertCount(2, User::withoutPermission([2])->get());
    }

    public function testItCanScopeUsersUsingAnArray(): void
    {
        User::all()->each(fn ($item) => $item->delete());
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        User::create(['email' => 'user3@test.com']);
        $user1->givePermissionTo(['edit-articles', 'edit-news']);
        $this->testUserRole->givePermissionTo('edit-articles');
        $user2->assignRole('testRole');

        $this->assertCount(2, User::permission(['edit-articles', 'edit-news'])->get());
        $this->assertCount(1, User::permission(['edit-news'])->get());
        $this->assertCount(2, User::withoutPermission(['edit-news'])->get());
    }

    public function testItCanScopeUsersWhenMultiplePermissionsShareTheSameRole(): void
    {
        User::all()->each(fn ($item) => $item->delete());

        $user = User::create(['email' => 'user@test.com']);
        $this->testUserRole->givePermissionTo(['edit-articles', 'edit-news']);
        $user->assignRole('testRole');

        $this->assertCount(1, User::permission(['edit-articles', 'edit-news'])->get());
        $this->assertTrue(User::permission(['edit-articles', 'edit-news'])->first()->is($user));
    }

    public function testItCanScopeUsersUsingACollection(): void
    {
        User::all()->each(fn ($item) => $item->delete());
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        User::create(['email' => 'user3@test.com']);
        $user1->givePermissionTo(['edit-articles', 'edit-news']);
        $this->testUserRole->givePermissionTo('edit-articles');
        $user2->assignRole('testRole');

        $this->assertCount(2, User::permission(collect(['edit-articles', 'edit-news']))->get());
        $this->assertCount(1, User::permission(collect(['edit-news']))->get());
        $this->assertCount(2, User::withoutPermission(collect(['edit-news']))->get());
    }

    public function testItCanScopeUsersUsingAnObject(): void
    {
        User::all()->each(fn ($item) => $item->delete());
        $user1 = User::create(['email' => 'user1@test.com']);
        $user1->givePermissionTo($this->testUserPermission->name);

        $this->assertCount(1, User::permission($this->testUserPermission)->get());
        $this->assertCount(1, User::permission([$this->testUserPermission])->get());
        $this->assertCount(1, User::permission(collect([$this->testUserPermission]))->get());
        $this->assertCount(0, User::withoutPermission(collect([$this->testUserPermission]))->get());
    }

    #[DataProvider('permissionScopeProvider')]
    public function testPermissionScopesRejectKeylessModelInputs(string $scope, bool $mixed): void
    {
        Model::preventAccessingMissingAttributes(false);

        $keylessPermission = app(Permission::class)->select(['name', 'guard_name'])->where('name', 'edit-blog')->firstOrFail();
        $permissions = $mixed ? [$this->testUserPermission, $keylessPermission] : $keylessPermission;

        $this->expectException(MissingAttributeException::class);
        $this->expectExceptionMessage($keylessPermission->getKeyName());

        User::query()->{$scope}($permissions)->get();
    }

    public static function permissionScopeProvider(): array
    {
        return [
            'permission with keyless model' => ['permission', false],
            'permission with mixed models' => ['permission', true],
            'without permission with keyless model' => ['withoutPermission', false],
            'without permission with mixed models' => ['withoutPermission', true],
        ];
    }

    public function testItCanScopeUsersWithoutDirectPermissionsOnlyRole(): void
    {
        User::all()->each(fn ($item) => $item->delete());
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user3 = User::create(['email' => 'user3@test.com']);
        $this->testUserRole->givePermissionTo('edit-articles');
        $user1->assignRole('testRole');
        $user2->assignRole('testRole');
        $user3->assignRole('testRole2');

        $this->assertCount(2, User::permission('edit-articles')->get());
        $this->assertCount(1, User::withoutPermission('edit-articles')->get());
    }

    public function testItCanScopeUsersWithOnlyDirectPermission(): void
    {
        User::all()->each(fn ($item) => $item->delete());
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        User::create(['email' => 'user3@test.com']);
        $user1->givePermissionTo(['edit-news']);
        $user2->givePermissionTo(['edit-articles', 'edit-news']);

        $this->assertCount(2, User::permission('edit-news')->get());
        $this->assertCount(1, User::withoutPermission('edit-news')->get());
    }

    public function testItThrowsAnExceptionWhenCallingHasPermissionToWithAnInvalidType(): void
    {
        $user = User::create(['email' => 'user1@test.com']);

        $this->expectException(PermissionDoesNotExist::class);

        $user->hasPermissionTo(new stdClass);
    }

    public function testItThrowsAnExceptionWhenCallingHasPermissionToWithNull(): void
    {
        $user = User::create(['email' => 'user1@test.com']);

        $this->expectException(PermissionDoesNotExist::class);

        $user->hasPermissionTo(null);
    }

    public function testItThrowsAnExceptionWhenCallingHasDirectPermissionWithAnInvalidType(): void
    {
        $user = User::create(['email' => 'user1@test.com']);

        $this->expectException(PermissionDoesNotExist::class);

        $user->hasDirectPermission(new stdClass);
    }

    public function testItThrowsAnExceptionWhenCallingHasDirectPermissionWithNull(): void
    {
        $user = User::create(['email' => 'user1@test.com']);

        $this->expectException(PermissionDoesNotExist::class);

        $user->hasDirectPermission(null);
    }

    public function testItThrowsAnExceptionWhenTryingToScopeANonExistingPermission(): void
    {
        try {
            User::permission('not defined permission')->get();
            $this->fail('Expected permission does not exist exception was not thrown.');
        } catch (PermissionDoesNotExist) {
            $this->assertTrue(true);
        }

        $this->expectException(PermissionDoesNotExist::class);

        User::withoutPermission('not defined permission')->get();
    }

    public function testItThrowsAnExceptionWhenTryingToScopeAPermissionFromAnotherGuard(): void
    {
        try {
            User::permission('testAdminPermission')->get();
            $this->fail('Expected permission does not exist exception was not thrown.');
        } catch (PermissionDoesNotExist) {
            $this->assertTrue(true);
        }

        $this->expectException(PermissionDoesNotExist::class);

        User::withoutPermission('testAdminPermission')->get();
    }

    public function testItDoesNotDetachPermissionsWhenUserSoftDeleting(): void
    {
        $user = SoftDeletingUser::create(['email' => 'test@example.com']);
        $user->givePermissionTo(['edit-news']);
        $user->delete();

        $user = SoftDeletingUser::withTrashed()->find($user->id);

        $this->assertTrue($user->hasPermissionTo('edit-news'));
    }

    public function testItCanGiveAndRevokeMultiplePermissions(): void
    {
        $this->testUserRole->givePermissionTo(['edit-articles', 'edit-news']);

        $this->assertSame(2, $this->testUserRole->permissions()->count());

        $this->testUserRole->revokePermissionTo(['edit-articles', 'edit-news']);

        $this->assertSame(0, $this->testUserRole->permissions()->count());
    }

    public function testItCanGiveAndRevokePermissionsModelsArray(): void
    {
        $models = [
            app(Permission::class)::where('name', 'edit-articles')->first(),
            app(Permission::class)::where('name', 'edit-news')->first(),
        ];

        $this->testUserRole->givePermissionTo($models);

        $this->assertSame(2, $this->testUserRole->permissions()->count());

        $this->testUserRole->revokePermissionTo($models);

        $this->assertSame(0, $this->testUserRole->permissions()->count());
    }

    public function testItCanGiveAndRevokePermissionsModelsCollection(): void
    {
        $models = app(Permission::class)::whereIn('name', ['edit-articles', 'edit-news'])->get();

        $this->testUserRole->givePermissionTo($models);

        $this->assertSame(2, $this->testUserRole->permissions()->count());

        $this->testUserRole->revokePermissionTo($models);

        $this->assertSame(0, $this->testUserRole->permissions()->count());
    }

    public function testItCanDetermineThatTheUserDoesNotHaveAPermission(): void
    {
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
    }

    public function testItThrowsAnExceptionWhenThePermissionDoesNotExist(): void
    {
        $this->expectException(PermissionDoesNotExist::class);

        $this->testUser->hasPermissionTo('does-not-exist');
    }

    public function testItThrowsAnExceptionWhenThePermissionDoesNotExistForThisGuard(): void
    {
        $this->expectException(PermissionDoesNotExist::class);

        $this->testUser->hasPermissionTo('does-not-exist', 'web');
    }

    public function testItCanRejectAUserThatDoesNotHaveAnyPermissionsAtAll(): void
    {
        $user = new User;

        $this->assertFalse($user->hasPermissionTo('edit-articles'));
    }

    public function testItCanDetermineThatTheUserHasAnyOfThePermissionsDirectly(): void
    {
        $this->assertFalse($this->testUser->hasAnyPermission('edit-articles'));

        $this->testUser->givePermissionTo('edit-articles');

        $this->assertTrue($this->testUser->hasAnyPermission('edit-news', 'edit-articles'));

        $this->testUser->givePermissionTo('edit-news');
        $this->testUser->revokePermissionTo($this->testUserPermission);

        $this->assertTrue($this->testUser->hasAnyPermission('edit-articles', 'edit-news'));
        $this->assertFalse($this->testUser->hasAnyPermission('edit-blog', 'Edit News', ['Edit News']));
    }

    public function testItCanDetermineThatTheUserHasAnyOfThePermissionsDirectlyUsingAnArray(): void
    {
        $this->assertFalse($this->testUser->hasAnyPermission(['edit-articles']));

        $this->testUser->givePermissionTo('edit-articles');

        $this->assertTrue($this->testUser->hasAnyPermission(['edit-news', 'edit-articles']));

        $this->testUser->givePermissionTo('edit-news');
        $this->testUser->revokePermissionTo($this->testUserPermission);

        $this->assertTrue($this->testUser->hasAnyPermission(['edit-articles', 'edit-news']));
    }

    public function testItCanDetermineThatTheUserHasAnyOfThePermissionsViaRole(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->testUser->assignRole('testRole');

        $this->assertTrue($this->testUser->hasAnyPermission('edit-news', 'edit-articles'));
        $this->assertFalse($this->testUser->hasAnyPermission('edit-blog', 'Edit News', ['Edit News']));
    }

    public function testItCanDetermineThatTheUserHasAllOfThePermissionsDirectly(): void
    {
        $this->testUser->givePermissionTo('edit-articles', 'edit-news');

        $this->assertTrue($this->testUser->hasAllPermissions('edit-articles', 'edit-news'));

        $this->testUser->revokePermissionTo('edit-articles');

        $this->assertFalse($this->testUser->hasAllPermissions('edit-articles', 'edit-news'));
        $this->assertFalse($this->testUser->hasAllPermissions(['edit-articles', 'edit-news'], 'edit-blog'));
    }

    public function testItCanDetermineThatTheUserHasAllOfThePermissionsDirectlyUsingAnArray(): void
    {
        $this->assertFalse($this->testUser->hasAllPermissions(['edit-articles', 'edit-news']));

        $this->testUser->revokePermissionTo('edit-articles');

        $this->assertFalse($this->testUser->hasAllPermissions(['edit-news', 'edit-articles']));

        $this->testUser->givePermissionTo('edit-news');
        $this->testUser->revokePermissionTo($this->testUserPermission);

        $this->assertFalse($this->testUser->hasAllPermissions(['edit-articles', 'edit-news']));
    }

    public function testItCanDetermineThatTheUserHasAllOfThePermissionsViaRole(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles', 'edit-news');

        $this->testUser->assignRole('testRole');

        $this->assertTrue($this->testUser->hasAllPermissions('edit-articles', 'edit-news'));
    }

    public function testItCanDetermineThatUserHasDirectPermission(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $this->assertTrue($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertSame(['edit-articles'], $this->testUser->getDirectPermissions()->pluck('name')->all());

        $this->testUser->revokePermissionTo('edit-articles');
        $this->assertFalse($this->testUser->hasDirectPermission('edit-articles'));

        $this->testUser->assignRole('testRole');
        $this->testUserRole->givePermissionTo('edit-articles');
        $this->assertFalse($this->testUser->hasDirectPermission('edit-articles'));
    }

    public function testItCanListAllThePermissionsViaRolesOfUser(): void
    {
        app(Role::class)->findByName('testRole2')->givePermissionTo('edit-news');

        $this->testUserRole->givePermissionTo('edit-articles');
        $this->testUser->assignRole('testRole', 'testRole2');

        $this->assertSame(
            ['edit-articles', 'edit-news'],
            $this->testUser->getPermissionsViaRoles()->pluck('name')->sort()->values()->all(),
        );
    }

    public function testItCanListAllTheCoupledPermissionsBothDirectlyAndViaRoles(): void
    {
        $this->testUser->givePermissionTo('edit-news');

        $this->testUserRole->givePermissionTo('edit-articles');
        $this->testUser->assignRole('testRole');

        $this->assertSame(
            ['edit-articles', 'edit-news'],
            $this->testUser->getAllPermissions()->pluck('name')->sort()->values()->all(),
        );
    }

    public function testItCanSyncMultiplePermissions(): void
    {
        $this->testUser->givePermissionTo('edit-news');

        $this->testUser->syncPermissions('edit-articles', 'edit-blog');

        $this->assertTrue($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasDirectPermission('edit-blog'));
        $this->assertFalse($this->testUser->hasDirectPermission('edit-news'));
    }

    public function testItCanAvoidSyncDuplicatedPermissions(): void
    {
        $this->testUser->syncPermissions('edit-articles', 'edit-blog', 'edit-blog');

        $this->assertTrue($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasDirectPermission('edit-blog'));
    }

    public function testItCanAvoidDetachOnPermissionThatDoesNotExistSync(): void
    {
        $this->testUser->syncPermissions('edit-articles');

        try {
            $this->testUser->syncPermissions('permission-does-not-exist');
            $this->fail('Expected permission does not exist exception was not thrown.');
        } catch (PermissionDoesNotExist) {
            $this->assertTrue($this->testUser->hasDirectPermission('edit-articles'));
            $this->assertFalse($this->testUser->checkPermissionTo('permission-does-not-exist'));
        }
    }

    public function testItCanSyncMultiplePermissionsById(): void
    {
        $this->testUser->givePermissionTo('edit-news');

        $ids = app(Permission::class)::whereIn('name', ['edit-articles', 'edit-blog'])->pluck($this->testUserPermission->getKeyName());

        $this->testUser->syncPermissions($ids);

        $this->assertTrue($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasDirectPermission('edit-blog'));
        $this->assertFalse($this->testUser->hasDirectPermission('edit-news'));
    }

    public function testItSyncPermissionIgnoresNullInputs(): void
    {
        $this->testUser->givePermissionTo('edit-news');

        $ids = app(Permission::class)::whereIn('name', ['edit-articles', 'edit-blog'])->pluck($this->testUserPermission->getKeyName());

        $ids->push(null);

        $this->testUser->syncPermissions($ids);

        $this->assertTrue($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasDirectPermission('edit-blog'));
        $this->assertFalse($this->testUser->hasDirectPermission('edit-news'));
    }

    public function testItDoesNotDetachPermissionsWhenSyncPermissionErrors(): void
    {
        $this->testUser->givePermissionTo('edit-news');

        try {
            $this->testUser->syncPermissions('edit-articles', 'permission-that-does-not-exist');
            $this->fail('Expected permission does not exist exception was not thrown.');
        } catch (PermissionDoesNotExist) {
            $this->assertTrue($this->testUser->fresh()->hasDirectPermission('edit-news'));
        }
    }

    #[DataProvider('permissionMutationProvider')]
    public function testPermissionMutationsRejectKeylessModelInputs(string $method, bool $mixed): void
    {
        Model::preventAccessingMissingAttributes(false);

        $this->testUser->givePermissionTo('edit-news');
        $keylessPermission = app(Permission::class)->select(['name', 'guard_name'])->where('name', 'edit-blog')->firstOrFail();
        $permissions = $mixed ? [$this->testUserPermission, $keylessPermission] : [$keylessPermission];
        $argument = $mixed ? $permissions : $keylessPermission;

        try {
            $this->testUser->{$method}($argument);
            $this->fail('Expected a missing permission key exception was not thrown.');
        } catch (MissingAttributeException $exception) {
            $this->assertStringContainsString($keylessPermission->getKeyName(), $exception->getMessage());
        }

        $this->assertTrue($this->testUser->fresh()->hasDirectPermission('edit-news'));
    }

    public static function permissionMutationProvider(): array
    {
        return [
            'grant keyless permission' => ['givePermissionTo', false],
            'grant mixed permissions' => ['givePermissionTo', true],
            'deny keyless permission' => ['denyPermissionTo', false],
            'revoke keyless permission' => ['revokePermissionTo', false],
            'sync keyless permission' => ['syncPermissions', false],
            'sync mixed permissions' => ['syncPermissions', true],
            'sync mixed permission effects' => ['syncPermissionEffects', true],
        ];
    }

    #[DataProvider('permissionOwnerMutationProvider')]
    public function testPermissionMutationsRejectAKeylessPersistedSubjectBeforeMutation(
        string $method,
        array $arguments,
    ): void {
        Model::preventAccessingMissingAttributes(false);

        $this->testUser->givePermissionTo('edit-news');
        $keylessUser = User::query()
            ->select('email')
            ->where('email', $this->testUser->email)
            ->firstOrFail();

        try {
            $keylessUser->{$method}(...$arguments);
            $this->fail('Expected a missing subject key exception was not thrown.');
        } catch (MissingAttributeException $exception) {
            $this->assertStringContainsString($keylessUser->getKeyName(), $exception->getMessage());
        }

        $user = $this->testUser->fresh();

        $this->assertTrue($user->hasDirectPermission('edit-news'));
        $this->assertFalse($user->hasDirectPermission('edit-articles'));
        $this->assertFalse($user->hasDeniedPermission('edit-blog'));
    }

    public static function permissionOwnerMutationProvider(): array
    {
        return [
            'give permission' => ['givePermissionTo', ['edit-articles']],
            'deny permission' => ['denyPermissionTo', ['edit-blog']],
            'sync permissions' => ['syncPermissions', ['edit-articles']],
            'sync permission effects' => ['syncPermissionEffects', [['edit-articles'], ['edit-blog']]],
            'revoke permission' => ['revokePermissionTo', ['edit-news']],
        ];
    }

    public function testRevokingAPermissionArrayPreservesNameBasedResolution(): void
    {
        Model::preventAccessingMissingAttributes(false);

        $this->testUser->givePermissionTo('edit-blog');
        $keylessPermission = app(Permission::class)->select(['name', 'guard_name'])->where('name', 'edit-blog')->firstOrFail();

        $this->testUser->revokePermissionTo([$keylessPermission]);

        $this->assertFalse($this->testUser->fresh()->hasDirectPermission('edit-blog'));
    }

    public function testItDoesNotRemoveAlreadyAssociatedPermissionsWhenAssigningNewPermissions(): void
    {
        $this->testUser->givePermissionTo('edit-news');
        $this->testUser->givePermissionTo('edit-articles');

        $this->assertTrue($this->testUser->fresh()->hasDirectPermission('edit-news'));
    }

    public function testItDoesNotThrowAnExceptionWhenAssigningAPermissionThatIsAlreadyAssigned(): void
    {
        $this->testUser->givePermissionTo('edit-news');
        $this->testUser->givePermissionTo('edit-news');

        $this->assertTrue($this->testUser->fresh()->hasDirectPermission('edit-news'));
    }

    public function testItCanSyncPermissionsToAModelThatIsNotPersisted(): void
    {
        $user = new User(['email' => 'test@user.com']);
        $user->syncPermissions('edit-articles');
        $user->save();
        $user->save();

        $this->assertTrue($user->hasPermissionTo('edit-articles'));

        $user->syncPermissions('edit-articles');
        $this->assertTrue($user->hasPermissionTo('edit-articles'));
        $this->assertTrue($user->fresh()->hasPermissionTo('edit-articles'));
    }

    public function testQueuedSyncPermissionsReplacesEarlierQueuedPermissionAssignments(): void
    {
        $user = new User(['email' => 'queued-sync@example.com']);

        $user->givePermissionTo('edit-news');
        $user->syncPermissions('edit-articles');
        $user->save();

        $user->refresh();

        $this->assertSame(1, $user->permissions()->count());
        $this->assertTrue($user->hasPermissionTo('edit-articles'));
        $this->assertFalse($user->hasPermissionTo('edit-news'));
    }

    public function testQueuedSyncPermissionsReplacesEarlierQueuedDeniedPermissionAssignment(): void
    {
        $user = new User(['email' => 'queued-sync-denied@example.com']);

        $user->denyPermissionTo('edit-articles');
        $user->syncPermissions('edit-articles');
        $user->save();

        $user->refresh();

        $this->assertSame(1, $user->permissions()->count());
        $this->assertTrue($user->hasPermissionTo('edit-articles'));
        $this->assertFalse($user->hasDeniedPermission('edit-articles'));
    }

    public function testItDoesNotRunUnnecessarySqlWhenAssigningNewPermissions(): void
    {
        $permission2 = app(Permission::class)->where('name', 'edit-news')->first();

        DB::enableQueryLog();
        $this->testUser->syncPermissions($this->testUserPermission, $permission2);
        DB::disableQueryLog();

        $this->assertCount(2, DB::getQueryLog());
    }

    public function testItDoesNotLetQueuedGivePermissionToInterfereWithOtherObjects(): void
    {
        $user = new User(['email' => 'test@user.com']);
        $user->givePermissionTo('edit-news');
        $user->save();

        $user2 = new User(['email' => 'test2@user.com']);
        $user2->givePermissionTo('edit-articles');

        DB::enableQueryLog();
        $user2->save();
        DB::disableQueryLog();

        $this->assertTrue($user->fresh()->hasPermissionTo('edit-news'));
        $this->assertFalse($user->fresh()->hasPermissionTo('edit-articles'));

        $this->assertTrue($user2->fresh()->hasPermissionTo('edit-articles'));
        $this->assertFalse($user2->fresh()->hasPermissionTo('edit-news'));
        $this->assertCount(2, DB::getQueryLog());
    }

    public function testItDoesNotLetQueuedSyncPermissionsInterfereWithOtherObjects(): void
    {
        $user = new User(['email' => 'test@user.com']);
        $user->syncPermissions('edit-news');
        $user->save();

        $user2 = new User(['email' => 'test2@user.com']);
        $user2->syncPermissions('edit-articles');

        DB::enableQueryLog();
        $user2->save();
        DB::disableQueryLog();

        $this->assertTrue($user->fresh()->hasPermissionTo('edit-news'));
        $this->assertFalse($user->fresh()->hasPermissionTo('edit-articles'));

        $this->assertTrue($user2->fresh()->hasPermissionTo('edit-articles'));
        $this->assertFalse($user2->fresh()->hasPermissionTo('edit-news'));
        $this->assertCount(2, DB::getQueryLog());
    }

    public function testItCanRetrievePermissionNames(): void
    {
        $this->testUser->givePermissionTo('edit-news', 'edit-articles');

        $this->assertSame(['edit-articles', 'edit-news'], $this->testUser->getPermissionNames()->sort()->values()->all());
    }

    public function testItCanCheckManyDirectPermissions(): void
    {
        $this->testUser->givePermissionTo(['edit-articles', 'edit-news']);

        $this->assertTrue($this->testUser->hasAllDirectPermissions(['edit-news', 'edit-articles']));
        $this->assertTrue($this->testUser->hasAllDirectPermissions('edit-news', 'edit-articles'));
        $this->assertFalse($this->testUser->hasAllDirectPermissions(['edit-articles', 'edit-news', 'edit-blog']));
        $this->assertFalse($this->testUser->hasAllDirectPermissions(['edit-articles', 'edit-news'], 'edit-blog'));
    }

    public function testItCanCheckIfThereIsAnyOfTheDirectPermissionsGiven(): void
    {
        $this->testUser->givePermissionTo(['edit-articles', 'edit-news']);

        $this->assertTrue($this->testUser->hasAnyDirectPermission(['edit-news', 'edit-blog']));
        $this->assertTrue($this->testUser->hasAnyDirectPermission('edit-news', 'edit-blog'));
        $this->assertFalse($this->testUser->hasAnyDirectPermission('edit-blog', 'Edit News', ['Edit News']));
    }

    public function testItCanCheckPermissionBasedOnLoggedInUserGuard(): void
    {
        $this->testUser->givePermissionTo(app(Permission::class)::create([
            'name' => 'do_that',
            'guard_name' => 'api',
        ]));

        $response = $this->actingAs($this->testUser, 'api')
            ->json('GET', '/check-api-guard-permission');

        $response->assertJson(['status' => true]);
    }

    public function testItCanRejectPermissionBasedOnLoggedInUserGuard(): void
    {
        app(Permission::class)::create([
            'name' => 'do_that',
            'guard_name' => 'api',
        ]);

        $assignedPermission = app(Permission::class)::create([
            'name' => 'do_that',
            'guard_name' => 'web',
        ]);

        $this->testUser->givePermissionTo($assignedPermission);

        $response = $this->withExceptionHandling()
            ->actingAs($this->testUser, 'api')
            ->json('GET', '/check-api-guard-permission');

        $response->assertJson(['status' => false]);
    }

    public function testItFiresAnEventWhenAPermissionIsAdded(): void
    {
        Event::fake();
        app('config')->set('permission.events_enabled', true);

        $this->testUser->givePermissionTo(['edit-articles', 'edit-news']);

        $ids = app(Permission::class)::whereIn('name', ['edit-articles', 'edit-news'])
            ->pluck($this->testUserPermission->getKeyName())
            ->toArray();

        Event::assertDispatched(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event) use ($ids): bool {
            return $event->model instanceof User
                && $event->model->hasPermissionTo('edit-news')
                && $event->model->hasPermissionTo('edit-articles')
                && $ids === $event->permissionsOrIds;
        });
    }

    public function testItDoesNotFireAnEventWhenEventsAreNotEnabled(): void
    {
        Event::fake();
        app('config')->set('permission.events_enabled', false);

        $this->testUser->givePermissionTo(['edit-articles', 'edit-news']);

        Event::assertNotDispatched(PermissionAttachedEvent::class);
    }

    public function testItFiresAnEventWhenAPermissionIsRemoved(): void
    {
        Event::fake();
        app('config')->set('permission.events_enabled', true);

        $permissions = app(Permission::class)::whereIn('name', ['edit-articles', 'edit-news'])->get();

        $this->testUser->givePermissionTo($permissions);
        $this->testUser->revokePermissionTo($permissions);

        Event::assertDispatched(PermissionDetachedEvent::class, function (PermissionDetachedEvent $event) use ($permissions): bool {
            return $event->model instanceof User
                && ! $event->model->hasPermissionTo('edit-news')
                && ! $event->model->hasPermissionTo('edit-articles')
                && $event->permissionsOrIds === $permissions;
        });
    }

    public function testItCanBeGivenAPermissionOnRoleWhenLazyLoadingIsRestricted(): void
    {
        $this->assertTrue(Model::preventsLazyLoading());

        $testRole = app(Role::class)->with('permissions')->get()->first();

        $testRole->givePermissionTo('edit-articles');

        $this->assertTrue($testRole->hasPermissionTo('edit-articles'));
    }

    public function testItCanBeGivenAPermissionOnUserWhenLazyLoadingIsRestricted(): void
    {
        $this->assertTrue(Model::preventsLazyLoading());

        User::create(['email' => 'other@user.com']);
        $testUser = User::with('permissions')->get()->first();

        $testUser->givePermissionTo('edit-articles');

        $this->assertTrue($testUser->hasPermissionTo('edit-articles'));
    }
}
