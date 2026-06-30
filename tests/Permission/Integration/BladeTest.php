<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Integration;

use Hypervel\Permission\Contracts\Role;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\Auth;
use Hypervel\Tests\Permission\Fixtures\Models\Admin;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\TestCase;

class BladeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $roleModel = app(Role::class);
        $roleModel->create(['name' => 'member']);
        $roleModel->create(['name' => 'writer']);
        $roleModel->create(['name' => 'intern']);
        $roleModel->create(['name' => 'super-admin', 'guard_name' => 'admin']);
        $roleModel->create(['name' => 'moderator', 'guard_name' => 'admin']);
    }

    public function testItEvaluatesAllBladeDirectivesAsFalseWhenNobodyIsLoggedIn(): void
    {
        $permission = 'edit-articles';
        $role = 'writer';
        $roles = [$role];
        $elserole = 'na';
        $elsepermission = 'na';

        $this->assertSame('does not have permission', $this->renderView('can', compact('permission')));
        $this->assertSame('does not have permission', $this->renderView('haspermission', compact('permission', 'elsepermission')));
        $this->assertSame('does not have role', $this->renderView('role', compact('role', 'elserole')));
        $this->assertSame('does not have role', $this->renderView('hasRole', compact('role', 'elserole')));
        $this->assertSame('does not have all of the given roles', $this->renderView('hasAllRoles', compact('roles')));
        $this->assertSame('does not have all of the given roles', $this->renderView('hasAllRoles', ['roles' => implode('|', $roles)]));
        $this->assertSame('does not have any of the given roles', $this->renderView('hasAnyRole', compact('roles')));
        $this->assertSame('does not have any of the given roles', $this->renderView('hasAnyRole', ['roles' => implode('|', $roles)]));
    }

    public function testItEvaluatesAllBladeDirectivesAsFalseWhenUserHasNoRolesOrPermissions(): void
    {
        Auth::setUser($this->testUser);

        $permission = 'edit-articles';
        $role = 'writer';
        $roles = 'writer';
        $elserole = 'na';
        $elsepermission = 'na';

        $this->assertSame('does not have permission', $this->renderView('can', compact('permission')));
        $this->assertSame('does not have permission', $this->renderView('haspermission', compact('permission', 'elsepermission')));
        $this->assertSame('does not have role', $this->renderView('role', compact('role', 'elserole')));
        $this->assertSame('does not have role', $this->renderView('hasRole', compact('role', 'elserole')));
        $this->assertSame('does not have all of the given roles', $this->renderView('hasAllRoles', compact('roles')));
        $this->assertSame('does not have any of the given roles', $this->renderView('hasAnyRole', compact('roles')));
    }

    public function testItEvaluatesAllBladeDirectivesAsFalseWhenSomebodyWithAnotherGuardIsLoggedIn(): void
    {
        Auth::guard('admin')->setUser($this->testAdmin);

        $permission = 'edit-articles';
        $role = 'writer';
        $roles = 'writer';
        $elserole = 'na';
        $elsepermission = 'na';

        $this->assertSame('does not have permission', $this->renderView('can', compact('permission')));
        $this->assertSame('does not have permission', $this->renderView('haspermission', compact('permission', 'elsepermission')));
        $this->assertSame('does not have role', $this->renderView('role', compact('role', 'elserole')));
        $this->assertSame('does not have role', $this->renderView('hasRole', compact('role', 'elserole')));
        $this->assertSame('does not have all of the given roles', $this->renderView('hasAllRoles', compact('roles')));
        $this->assertSame('does not have any of the given roles', $this->renderView('hasAnyRole', compact('roles')));
    }

    public function testItAcceptsAGuardNameInTheCanDirective(): void
    {
        $user = $this->writer();
        $user->givePermissionTo('edit-articles');
        Auth::setUser($user);

        $permission = 'edit-articles';
        $guard = 'web';
        $this->assertSame('has permission', $this->renderView('can', compact('permission', 'guard')));

        $guard = 'admin';
        $this->assertSame('does not have permission', $this->renderView('can', compact('permission', 'guard')));

        Auth::logout();

        $this->testAdmin->givePermissionTo($this->testAdminPermission);
        Auth::setUser($this->testAdmin);

        $permission = 'edit-articles';
        $guard = 'web';
        $this->assertSame('does not have permission', $this->renderView('can', compact('permission', 'guard')));

        $permission = 'admin-permission';
        $guard = 'admin';
        $this->assertTrue($this->testAdmin->checkPermissionTo($permission, $guard));
        $this->assertSame('has permission', $this->renderView('can', compact('permission', 'guard')));
    }

    public function testCanDirectiveIsTrueWhenUserHasPermission(): void
    {
        $user = $this->writer();
        $user->givePermissionTo('edit-articles');
        Auth::setUser($user);

        $this->assertSame('has permission', $this->renderView('can', ['permission' => 'edit-articles']));
    }

    public function testHaspermissionDirectiveIsTrueWhenUserHasPermission(): void
    {
        $user = $this->writer();
        $permission = 'edit-articles';
        $user->givePermissionTo('edit-articles');
        Auth::setUser($user);

        $this->assertSame('has permission', $this->renderView('haspermission', compact('permission')));

        $guard = 'admin';
        $elsepermission = 'na';
        $this->assertSame('does not have permission', $this->renderView('haspermission', compact('permission', 'elsepermission', 'guard')));

        $this->testAdminRole->givePermissionTo($this->testAdminPermission);
        $this->testAdmin->assignRole($this->testAdminRole);
        Auth::guard('admin')->setUser($this->testAdmin);
        $permission = 'admin-permission';

        $this->assertSame('has permission', $this->renderView('haspermission', compact('permission', 'guard', 'elsepermission')));
    }

    public function testRoleDirectiveIsTrueWhenUserHasRole(): void
    {
        Auth::setUser($this->writer());

        $this->assertSame('has role', $this->renderView('role', ['role' => 'writer', 'elserole' => 'na']));
    }

    public function testElseroleDirectiveIsTrueWhenUserHasElseRole(): void
    {
        Auth::setUser($this->member());

        $this->assertSame('has else role', $this->renderView('role', ['role' => 'writer', 'elserole' => 'member']));
    }

    public function testRoleDirectiveIsTrueForGivenGuard(): void
    {
        Auth::guard('admin')->setUser($this->superAdmin());

        $this->assertSame('has role for guard', $this->renderView('guardRole', ['role' => 'super-admin', 'guard' => 'admin']));
    }

    public function testHasroleDirectiveIsTrueWhenUserHasRole(): void
    {
        Auth::setUser($this->writer());

        $this->assertSame('has role', $this->renderView('hasRole', ['role' => 'writer']));
    }

    public function testHasroleDirectiveIsTrueForGivenGuard(): void
    {
        Auth::guard('admin')->setUser($this->superAdmin());

        $this->assertSame('has role', $this->renderView('guardHasRole', ['role' => 'super-admin', 'guard' => 'admin']));
    }

    public function testUnlessroleDirectiveIsTrueWhenUserDoesNotHaveRole(): void
    {
        Auth::setUser($this->writer());

        $this->assertSame('does not have role', $this->renderView('unlessrole', ['role' => 'another']));
    }

    public function testUnlessroleDirectiveIsTrueForGivenGuard(): void
    {
        Auth::guard('admin')->setUser($this->superAdmin());

        $this->assertSame('does not have role', $this->renderView('guardunlessrole', ['role' => 'another', 'guard' => 'admin']));
        $this->assertSame('does not have role', $this->renderView('guardunlessrole', ['role' => 'super-admin', 'guard' => 'web']));
    }

    public function testHasanyroleDirectiveIsFalseWhenUserDoesNotHaveAnyRequiredRole(): void
    {
        $roles = ['writer', 'intern'];
        Auth::setUser($this->member());

        $this->assertSame('does not have any of the given roles', $this->renderView('hasAnyRole', compact('roles')));
        $this->assertSame('does not have any of the given roles', $this->renderView('hasAnyRole', ['roles' => implode('|', $roles)]));
    }

    public function testHasanyroleDirectiveIsTrueWhenUserHasSomeRequiredRoles(): void
    {
        $roles = ['member', 'writer', 'intern'];
        Auth::setUser($this->member());

        $this->assertSame('does have some of the roles', $this->renderView('hasAnyRole', compact('roles')));
        $this->assertSame('does have some of the roles', $this->renderView('hasAnyRole', ['roles' => implode('|', $roles)]));
    }

    public function testHasanyroleDirectiveIsTrueForGivenGuard(): void
    {
        $roles = ['super-admin', 'moderator'];
        $guard = 'admin';
        Auth::guard('admin')->setUser($this->superAdmin());

        $this->assertSame('does have some of the roles', $this->renderView('guardHasAnyRole', compact('roles', 'guard')));
    }

    public function testHasanyroleDirectiveIsTrueForPipeInput(): void
    {
        $guard = 'admin';
        Auth::guard('admin')->setUser($this->superAdmin());

        $this->assertSame('does have some of the roles', $this->renderView('guardHasAnyRolePipe', compact('guard')));
    }

    public function testHasanyroleDirectiveIsFalseForPipeInput(): void
    {
        $guard = '';
        Auth::guard('admin')->setUser($this->member());

        $this->assertSame('does not have any of the given roles', $this->renderView('guardHasAnyRolePipe', compact('guard')));
    }

    public function testHasallrolesDirectiveIsFalseWhenUserDoesNotHaveAllRequiredRoles(): void
    {
        $roles = ['member', 'writer'];
        Auth::setUser($this->member());

        $this->assertSame('does not have all of the given roles', $this->renderView('hasAllRoles', compact('roles')));
        $this->assertSame('does not have all of the given roles', $this->renderView('hasAllRoles', ['roles' => implode('|', $roles)]));
    }

    public function testHasallrolesDirectiveIsTrueWhenUserHasAllRequiredRoles(): void
    {
        $roles = ['member', 'writer'];
        $user = $this->member();
        $user->assignRole('writer');
        Auth::setUser($user);

        $this->assertSame('does have all of the given roles', $this->renderView('hasAllRoles', compact('roles')));
        $this->assertSame('does have all of the given roles', $this->renderView('hasAllRoles', ['roles' => implode('|', $roles)]));
    }

    public function testHasallrolesDirectiveIsTrueForGivenGuard(): void
    {
        $roles = ['super-admin', 'moderator'];
        $guard = 'admin';
        $admin = $this->superAdmin();
        $admin->assignRole('moderator');
        Auth::guard('admin')->setUser($admin);

        $this->assertSame('does have all of the given roles', $this->renderView('guardHasAllRoles', compact('roles', 'guard')));
    }

    public function testHasallrolesDirectiveIsTrueForPipeInput(): void
    {
        $guard = 'admin';
        $admin = $this->superAdmin();
        $admin->assignRole('moderator');
        Auth::guard('admin')->setUser($admin);

        $this->assertSame('does have all of the given roles', $this->renderView('guardHasAllRolesPipe', compact('guard')));
    }

    public function testHasallrolesDirectiveIsFalseForPipeInput(): void
    {
        $guard = '';
        $user = $this->member();
        $user->assignRole('writer');
        Auth::setUser($user);

        $this->assertSame('does not have all of the given roles', $this->renderView('guardHasAllRolesPipe', compact('guard')));
    }

    public function testHasallrolesDirectiveIsTrueForArrayInput(): void
    {
        $guard = 'admin';
        $admin = $this->superAdmin();
        $admin->assignRole('moderator');
        Auth::guard('admin')->setUser($admin);

        $this->assertSame('does have all of the given roles', $this->renderView('guardHasAllRolesArray', compact('guard')));
    }

    public function testHasallrolesDirectiveIsFalseForArrayInput(): void
    {
        $guard = '';
        $user = $this->member();
        $user->assignRole('writer');
        Auth::setUser($user);

        $this->assertSame('does not have all of the given roles', $this->renderView('guardHasAllRolesArray', compact('guard')));
    }

    protected function renderView(string $view, array $parameters): string
    {
        Artisan::call('view:clear');

        return trim((string) view($view)->with($parameters));
    }

    protected function writer(): User
    {
        $this->testUser->assignRole('writer');

        return $this->testUser;
    }

    protected function member(): User
    {
        $this->testUser->assignRole('member');

        return $this->testUser;
    }

    protected function superAdmin(): Admin
    {
        $this->testAdmin->assignRole('super-admin');

        return $this->testAdmin;
    }
}
