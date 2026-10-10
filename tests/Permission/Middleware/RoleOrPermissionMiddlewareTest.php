<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Middleware;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Permission\Middleware\RoleOrPermissionMiddleware;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Gate;
use Hypervel\Tests\Permission\Fixtures\Models\PlainAuthenticatableUser;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\Fixtures\Models\UserWithoutHasRoles;
use Hypervel\Tests\Permission\TestCase;
use InvalidArgumentException;

class RoleOrPermissionMiddlewareTest extends TestCase
{
    protected RoleOrPermissionMiddleware $roleOrPermissionMiddleware;

    protected function setUpInCoroutine(): void
    {
        $this->setUpPassport();
        $this->roleOrPermissionMiddleware = $this->app->make(RoleOrPermissionMiddleware::class);
    }

    public function testAGuestCannotAccessARouteProtectedByTheRoleOrPermissionMiddleware(): void
    {
        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole'));
    }

    public function testAUserCanAccessARouteProtectedByPermissionOrRoleMiddlewareIfHasThisPermissionOrRole(): void
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

    public function testAuthorizedRequestPreservesJsonResponse(): void
    {
        Auth::login($this->testUser);

        $this->testUser->givePermissionTo('edit-articles');

        $response = new JsonResponse(['authorized' => true]);

        $this->assertSame($response, $this->roleOrPermissionMiddleware->handle(
            new Request,
            fn (): JsonResponse => $response,
            'edit-articles',
        ));
    }

    public function testAClientCanAccessARouteProtectedByPermissionOrRoleMiddlewareIfHasThisPermissionOrRole(): void
    {
        $this->actingAsClient($this->testClient);

        $this->testClient->assignRole('clientRole');
        $this->testClient->givePermissionTo('edit-posts');

        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, 'clientRole|edit-news|edit-posts', null, true));

        $this->testClient->removeRole('clientRole');

        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, 'clientRole|edit-posts', null, true));

        $this->testClient->revokePermissionTo('edit-posts');
        $this->testClient->assignRole('clientRole');

        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, 'clientRole|edit-posts', null, true));
        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, ['clientRole', 'edit-posts'], null, true));
    }

    public function testASuperAdminUserCanAccessARouteProtectedByPermissionOrRoleMiddleware(): void
    {
        Auth::login($this->testUser);

        Gate::before(function (User $user, string $ability): ?bool {
            return $user->getKey() === $this->testUser->getKey() ? true : null;
        });

        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole|edit-articles'));
    }

    public function testAUserCanNotAccessARouteProtectedByPermissionOrRoleMiddlewareIfHaveNotHasRolesTrait(): void
    {
        $userWithoutHasRoles = UserWithoutHasRoles::create(['email' => 'test_not_has_roles@user.com']);

        Auth::login($userWithoutHasRoles);

        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole|edit-articles'));
    }

    public function testPlainAuthenticatableUserWithoutAuthorizableCannotAccessRoute(): void
    {
        Auth::login(PlainAuthenticatableUser::create(['email' => 'plain_authenticatable@user.com']));

        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole|edit-articles'));
    }

    public function testAUserCanNotAccessARouteProtectedByPermissionOrRoleMiddlewareIfHaveNotThisPermissionAndRole(): void
    {
        Auth::login($this->testUser);

        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole|edit-articles'));
        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'missingRole|missingPermission'));
    }

    public function testAClientCanNotAccessARouteProtectedByPermissionOrRoleMiddlewareIfHaveNotThisPermissionAndRole(): void
    {
        $this->actingAsClient($this->testClient);

        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'clientRole|edit-posts', null, true));
        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'missingRole|missingPermission', null, true));
    }

    public function testUseNotExistingCustomGuardInRoleOrPermission(): void
    {
        $class = null;

        try {
            $this->roleOrPermissionMiddleware->handle(new Request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, 'testRole', 'xxx');
        } catch (InvalidArgumentException $e) {
            $class = get_class($e);
        }

        $this->assertSame(InvalidArgumentException::class, $class);
    }

    public function testUserCanNotAccessPermissionOrRoleWithGuardAdminWhileLoginUsingDefaultGuard(): void
    {
        Auth::login($this->testUser);

        $this->testUser->assignRole('testRole');
        $this->testUser->givePermissionTo('edit-articles');

        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'edit-articles|testRole', 'admin'));
    }

    public function testClientCanNotAccessPermissionOrRoleWithGuardAdminWhileLoginUsingDefaultGuard(): void
    {
        $this->actingAsClient($this->testClient);

        $this->testClient->assignRole('clientRole');
        $this->testClient->givePermissionTo('edit-posts');

        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'edit-posts|clientRole', 'admin', true));
    }

    public function testUserCanAccessPermissionOrRoleWithGuardAdminWhileLoginUsingAdminGuard(): void
    {
        Auth::guard('admin')->login($this->testAdmin);

        $this->testAdmin->assignRole('testAdminRole');
        $this->testAdmin->givePermissionTo('admin-permission');

        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, 'admin-permission|testAdminRole', 'admin'));
        $this->assertSame(403, $this->runMiddleware($this->roleOrPermissionMiddleware, 'edit-articles|testRole', 'admin'));
    }

    public function testEmptyGuardUsesDefaultGuard(): void
    {
        Auth::login($this->testUser);
        $this->testUser->assignRole('testRole');

        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, 'edit-articles|testRole', ''));
    }

    public function testTheRequiredPermissionsOrRolesCanBeFetchedFromTheException(): void
    {
        Auth::login($this->testUser);

        $message = null;
        $requiredRolesOrPermissions = [];

        try {
            $this->roleOrPermissionMiddleware->handle(new Request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, 'some-permission|some-role');
        } catch (UnauthorizedException $e) {
            $message = $e->getMessage();
            $requiredRolesOrPermissions = $e->getRequiredPermissions();
        }

        $this->assertSame('User does not have any of the necessary access rights.', $message);
        $this->assertSame(['some-permission', 'some-role'], $requiredRolesOrPermissions);
    }

    public function testTheRequiredPermissionsOrRolesCanBeDisplayedInTheException(): void
    {
        Auth::login($this->testUser);
        config()->set(['permission.display_permission_in_exception' => true]);
        config()->set(['permission.display_role_in_exception' => true]);

        $message = null;

        try {
            $this->roleOrPermissionMiddleware->handle(new Request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, 'some-permission|some-role');
        } catch (UnauthorizedException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringEndsWith('Necessary roles or permissions are some-permission, some-role', $message);
    }

    public function testTheMiddlewareCanBeCreatedWithStaticUsingMethod(): void
    {
        $this->assertSame('Hypervel\Permission\Middleware\RoleOrPermissionMiddleware:edit-articles', RoleOrPermissionMiddleware::using('edit-articles'));

        $this->assertSame('Hypervel\Permission\Middleware\RoleOrPermissionMiddleware:edit-articles,my-guard', RoleOrPermissionMiddleware::using('edit-articles', 'my-guard'));

        $this->assertSame('Hypervel\Permission\Middleware\RoleOrPermissionMiddleware:edit-articles|testAdminRole', RoleOrPermissionMiddleware::using(['edit-articles', 'testAdminRole']));
    }
}
