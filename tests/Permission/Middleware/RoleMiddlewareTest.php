<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Middleware;

use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Permission\Contracts\Role;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Permission\Middleware\RoleMiddleware;
use Hypervel\Support\Facades\Auth;
use Hypervel\Tests\Permission\Fixtures\Models\PlainAuthenticatableUser;
use Hypervel\Tests\Permission\Fixtures\Models\TestRolePermissionsEnum;
use Hypervel\Tests\Permission\Fixtures\Models\UserWithoutHasRoles;
use Hypervel\Tests\Permission\TestCase;
use InvalidArgumentException;

class RoleMiddlewareTest extends TestCase
{
    protected RoleMiddleware $roleMiddleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleMiddleware = $this->app->make(RoleMiddleware::class);
    }

    public function testGuestCannotAccessRoleProtectedRoute(): void
    {
        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testRole'));
    }

    public function testUserCanAccessRouteWithRole(): void
    {
        Auth::login($this->testUser);

        $this->testUser->assignRole('testRole');

        $this->assertSame(200, $this->runMiddleware($this->roleMiddleware, 'testRole'));
    }

    public function testUserCannotAccessRouteWithRoleFromAnotherGuard(): void
    {
        Auth::login($this->testUser);

        $this->testUser->assignRole('testRole');

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testAdminRole'));
    }

    public function testUserCanAccessRouteWithOneOfSeveralRoles(): void
    {
        Auth::login($this->testUser);

        $this->testUser->assignRole('testRole');

        $this->assertSame(200, $this->runMiddleware($this->roleMiddleware, 'testRole|testRole2'));
        $this->assertSame(200, $this->runMiddleware($this->roleMiddleware, ['testRole2', 'testRole']));
    }

    public function testUserCannotAccessRouteWithDifferentRole(): void
    {
        Auth::login($this->testUser);

        $this->testUser->assignRole('testRole');

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testRole2'));
    }

    public function testUserCannotAccessRouteWithoutRoles(): void
    {
        Auth::login($this->testUser);

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testRole|testRole2'));
    }

    public function testUserCannotAccessRouteWithUndefinedRole(): void
    {
        Auth::login($this->testUser);

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, ''));
    }

    public function testUserWithoutHasRolesTraitCannotAccessRoute(): void
    {
        Auth::login(UserWithoutHasRoles::create(['email' => 'test_not_has_roles@user.com']));

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testRole'));
    }

    public function testPlainAuthenticatableUserWithoutAuthorizableCannotAccessRoute(): void
    {
        Auth::login(PlainAuthenticatableUser::create(['email' => 'plain_authenticatable@user.com']));

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testRole'));
    }

    public function testUserCanAccessRoleWithMatchingGuard(): void
    {
        Auth::guard('admin')->login($this->testAdmin);

        $this->testAdmin->assignRole('testAdminRole');

        $this->assertSame(200, $this->runMiddleware($this->roleMiddleware, 'testAdminRole', 'admin'));
        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testRole', 'admin'));
    }

    public function testEmptyGuardUsesDefaultGuard(): void
    {
        Auth::login($this->testUser);
        $this->testUser->assignRole('testRole');

        $this->assertSame(200, $this->runMiddleware($this->roleMiddleware, 'testRole', ''));
    }

    public function testUserCannotAccessRoleWithAdminGuardWhileLoggedInUsingDefaultGuard(): void
    {
        Auth::login($this->testUser);

        $this->testUser->assignRole('testRole');

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testRole', 'admin'));
    }

    public function testItCanBeCreatedWithStaticUsingMethod(): void
    {
        $this->assertSame(RoleMiddleware::class . ':testAdminRole', RoleMiddleware::using('testAdminRole'));
        $this->assertSame(RoleMiddleware::class . ':testAdminRole,my-guard', RoleMiddleware::using('testAdminRole', 'my-guard'));
        $this->assertSame(RoleMiddleware::class . ':testAdminRole|anotherRole', RoleMiddleware::using(['testAdminRole', 'anotherRole']));
    }

    public function testItCanHandleEnumRolesWithStaticUsingMethod(): void
    {
        $this->assertSame(RoleMiddleware::class . ':writer', RoleMiddleware::using(TestRolePermissionsEnum::Writer));
        $this->assertSame(RoleMiddleware::class . ':writer,my-guard', RoleMiddleware::using(TestRolePermissionsEnum::Writer, 'my-guard'));
        $this->assertSame(RoleMiddleware::class . ':writer|editor', RoleMiddleware::using([
            TestRolePermissionsEnum::Writer,
            TestRolePermissionsEnum::Editor,
        ]));
    }

    public function testItCanHandleEnumRolesWithHandleMethod(): void
    {
        $this->app->make(Role::class)->create(['name' => TestRolePermissionsEnum::Writer->value]);
        $this->app->make(Role::class)->create(['name' => TestRolePermissionsEnum::Editor->value]);

        Auth::login($this->testUser);
        $this->testUser->assignRole(TestRolePermissionsEnum::Writer);

        $this->assertSame(200, $this->runMiddleware($this->roleMiddleware, TestRolePermissionsEnum::Writer));

        $this->testUser->assignRole(TestRolePermissionsEnum::Editor);

        $this->assertSame(200, $this->runMiddleware($this->roleMiddleware, [
            TestRolePermissionsEnum::Writer,
            TestRolePermissionsEnum::Editor,
        ]));
    }

    public function testItExposesRequiredRolesOnTheUnauthorizedException(): void
    {
        Auth::login($this->testUser);

        try {
            $this->roleMiddleware->handle(new Request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, 'role.some');
        } catch (UnauthorizedException $exception) {
            $this->assertSame(['role.some'], $exception->getRequiredRoles());

            return;
        }

        $this->fail('Expected unauthorized role exception was not thrown.');
    }

    public function testItCanDisplayRequiredRolesOnTheUnauthorizedException(): void
    {
        Auth::login($this->testUser);
        $this->app->make('config')->set('permission.display_role_in_exception', true);

        try {
            $this->roleMiddleware->handle(new Request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, 'some-role');
        } catch (UnauthorizedException $exception) {
            $this->assertStringEndsWith('Necessary roles are some-role', $exception->getMessage());

            return;
        }

        $this->fail('Expected unauthorized role exception was not thrown.');
    }

    public function testItThrowsForMissingCustomGuard(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->roleMiddleware->handle(new Request, function (): Response {
            return (new Response)->setContent('<html></html>');
        }, 'testRole', 'xxx');
    }
}
