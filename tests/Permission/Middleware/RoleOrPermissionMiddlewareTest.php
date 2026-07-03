<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Middleware;

use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Permission\Middleware\RoleOrPermissionMiddleware;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Gate;
use Hypervel\Tests\Permission\Fixtures\Models\PlainAuthenticatableUser;
use Hypervel\Tests\Permission\Fixtures\Models\UserWithoutHasRoles;
use Hypervel\Tests\Permission\TestCase;
use InvalidArgumentException;

class RoleOrPermissionMiddlewareTest extends TestCase
{
    protected RoleOrPermissionMiddleware $roleOrPermissionMiddleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleOrPermissionMiddleware = $this->app->make(RoleOrPermissionMiddleware::class);
    }

    public function testGuestCannotAccessProtectedRoute(): void
    {
        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole'));
    }

    public function testUserCanAccessRouteWithEitherPermissionOrRole(): void
    {
        Auth::login($this->testUser);

        $this->testUser->assignRole('testRole');
        $this->testUser->givePermissionTo('edit-articles');

        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole|edit-news|edit-articles'));

        $this->testUser->removeRole('testRole');
        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole|edit-articles'));

        $this->testUser->revokePermissionTo('edit-articles');
        $this->testUser->assignRole('testRole');

        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole|edit-articles'));
        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, ['testRole', 'edit-articles']));
    }

    public function testSuperAdminGateBeforeCanAccessProtectedRoute(): void
    {
        Auth::login($this->testUser);

        Gate::before(fn ($user): ?bool => $user->getKey() === $this->testUser->getKey() ? true : null);

        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole|edit-articles'));
    }

    public function testUserWithoutHasRolesTraitCannotAccessRoute(): void
    {
        Auth::login(UserWithoutHasRoles::create(['email' => 'test_not_has_roles@user.com']));

        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole|edit-articles'));
    }

    public function testPlainAuthenticatableUserWithoutAuthorizableCannotAccessRoute(): void
    {
        Auth::login(PlainAuthenticatableUser::create(['email' => 'plain_authenticatable@user.com']));

        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole|edit-articles'));
    }

    public function testUserCannotAccessRouteWithoutMatchingPermissionOrRole(): void
    {
        Auth::login($this->testUser);

        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole|edit-articles'));
        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'missingRole|missingPermission'));
    }

    public function testUserCanAccessPermissionOrRoleWithMatchingGuard(): void
    {
        Auth::guard('admin')->login($this->testAdmin);

        $this->testAdmin->assignRole('testAdminRole');
        $this->testAdmin->givePermissionTo('admin-permission');

        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, 'admin-permission|testAdminRole', 'admin'));
        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'edit-articles|testRole', 'admin'));
    }

    public function testUserCannotAccessPermissionOrRoleWithAdminGuardWhileLoggedInUsingDefaultGuard(): void
    {
        Auth::login($this->testUser);

        $this->testUser->assignRole('testRole');
        $this->testUser->givePermissionTo('edit-articles');

        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'edit-articles|testRole', 'admin'));
    }

    public function testItCanBeCreatedWithStaticUsingMethod(): void
    {
        $this->assertSame(RoleOrPermissionMiddleware::class . ':edit-articles', RoleOrPermissionMiddleware::using('edit-articles'));
        $this->assertSame(RoleOrPermissionMiddleware::class . ':edit-articles,my-guard', RoleOrPermissionMiddleware::using('edit-articles', 'my-guard'));
        $this->assertSame(RoleOrPermissionMiddleware::class . ':edit-articles|testAdminRole', RoleOrPermissionMiddleware::using(['edit-articles', 'testAdminRole']));
    }

    public function testItExposesRequiredRolesOrPermissionsOnTheUnauthorizedException(): void
    {
        Auth::login($this->testUser);

        try {
            $this->roleOrPermissionMiddleware->handle(new Request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, 'some-permission|some-role');
        } catch (UnauthorizedException $exception) {
            $this->assertSame('User does not have any of the necessary access rights.', $exception->getMessage());
            $this->assertSame(['some-permission', 'some-role'], $exception->getRequiredPermissions());

            return;
        }

        $this->fail('Expected unauthorized role or permission exception was not thrown.');
    }

    public function testItCanDisplayRequiredRolesOrPermissionsOnTheUnauthorizedException(): void
    {
        Auth::login($this->testUser);
        $this->app->make('config')->set([
            'permission.display_permission_in_exception' => true,
            'permission.display_role_in_exception' => true,
        ]);

        try {
            $this->roleOrPermissionMiddleware->handle(new Request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, 'some-permission|some-role');
        } catch (UnauthorizedException $exception) {
            $this->assertStringEndsWith('Necessary roles or permissions are some-permission, some-role', $exception->getMessage());

            return;
        }

        $this->fail('Expected unauthorized role or permission exception was not thrown.');
    }

    public function testItThrowsForMissingCustomGuard(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->roleOrPermissionMiddleware->handle(new Request, function (): Response {
            return (new Response)->setContent('<html></html>');
        }, 'testRole', 'xxx');
    }
}
