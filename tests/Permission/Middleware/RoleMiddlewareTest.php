<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Middleware;

use Hypervel\Http\JsonResponse;
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

    protected function setUpInCoroutine(): void
    {
        $this->setUpPassport();
        $this->roleMiddleware = $this->app->make(RoleMiddleware::class);
    }

    public function testAGuestCannotAccessARouteProtectedByRolemiddleware(): void
    {
        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testRole'));
    }

    public function testAUserCannotAccessARouteProtectedByRoleMiddlewareOfAnotherGuard(): void
    {
        Auth::login($this->testUser);

        $this->testUser->assignRole('testRole');

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testAdminRole'));
    }

    public function testAClientCannotAccessARouteProtectedByRoleMiddlewareOfAnotherGuard(): void
    {
        $this->actingAsClient($this->testClient);

        $this->testClient->assignRole('clientRole');

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testAdminRole', null, true));
    }

    public function testAUserCanAccessARouteProtectedByRoleMiddlewareIfHaveThisRole(): void
    {
        Auth::login($this->testUser);

        $this->testUser->assignRole('testRole');

        $this->assertSame(200, $this->runMiddleware($this->roleMiddleware, 'testRole'));
    }

    public function testAuthorizedRequestPreservesJsonResponse(): void
    {
        Auth::login($this->testUser);

        $this->testUser->assignRole('testRole');

        $response = new JsonResponse(['authorized' => true]);

        $this->assertSame($response, $this->roleMiddleware->handle(
            new Request,
            fn (): JsonResponse => $response,
            'testRole',
        ));
    }

    public function testAClientCanAccessARouteProtectedByRoleMiddlewareIfHaveThisRole(): void
    {
        $this->actingAsClient($this->testClient);

        $this->testClient->assignRole('clientRole');

        $this->assertSame(200, $this->runMiddleware($this->roleMiddleware, 'clientRole', null, true));
    }

    public function testAUserCanAccessARouteProtectedByThisRoleMiddlewareIfHaveOneOfTheRoles(): void
    {
        Auth::login($this->testUser);

        $this->testUser->assignRole('testRole');

        $this->assertSame(200, $this->runMiddleware($this->roleMiddleware, 'testRole|testRole2'));
        $this->assertSame(200, $this->runMiddleware($this->roleMiddleware, ['testRole2', 'testRole']));
    }

    public function testAClientCanAccessARouteProtectedByThisRoleMiddlewareIfHaveOneOfTheRoles(): void
    {
        $this->actingAsClient($this->testClient);

        $this->testClient->assignRole('clientRole');

        $this->assertSame(200, $this->runMiddleware($this->roleMiddleware, 'clientRole|testRole2', null, true));
        $this->assertSame(200, $this->runMiddleware($this->roleMiddleware, ['testRole2', 'clientRole'], null, true));
    }

    public function testAUserCannotAccessARouteProtectedByTheRoleMiddlewareIfHaveNotHasRolesTrait(): void
    {
        $userWithoutHasRoles = UserWithoutHasRoles::create(['email' => 'test_not_has_roles@user.com']);

        Auth::login($userWithoutHasRoles);

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testRole'));
    }

    public function testPlainAuthenticatableUserWithoutAuthorizableCannotAccessRoute(): void
    {
        Auth::login(PlainAuthenticatableUser::create(['email' => 'plain_authenticatable@user.com']));

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testRole'));
    }

    public function testAUserCannotAccessARouteProtectedByTheRoleMiddlewareIfHaveADifferentRole(): void
    {
        Auth::login($this->testUser);

        $this->testUser->assignRole(['testRole']);

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testRole2'));
    }

    public function testAClientCannotAccessARouteProtectedByTheRoleMiddlewareIfHaveADifferentRole(): void
    {
        $this->actingAsClient($this->testClient);

        $this->testClient->assignRole(['clientRole']);

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'clientRole2', null, true));
    }

    public function testAUserCannotAccessARouteProtectedByRoleMiddlewareIfHaveNotRoles(): void
    {
        Auth::login($this->testUser);

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testRole|testRole2'));
    }

    public function testAClientCannotAccessARouteProtectedByRoleMiddlewareIfHaveNotRoles(): void
    {
        $this->actingAsClient($this->testClient);

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testRole|testRole2', null, true));
    }

    public function testAUserCannotAccessARouteProtectedByRoleMiddlewareIfRoleIsUndefined(): void
    {
        Auth::login($this->testUser);

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, ''));
    }

    public function testAClientCannotAccessARouteProtectedByRoleMiddlewareIfRoleIsUndefined(): void
    {
        $this->actingAsClient($this->testClient);

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, '', null, true));
    }

    public function testTheRequiredRolesCanBeFetchedFromTheException(): void
    {
        Auth::login($this->testUser);

        $message = null;
        $requiredRoles = [];

        try {
            $this->roleMiddleware->handle(new Request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, 'some-role');
        } catch (UnauthorizedException $e) {
            $message = $e->getMessage();
            $requiredRoles = $e->getRequiredRoles();
        }

        $this->assertSame('User does not have the right roles.', $message);
        $this->assertSame(['some-role'], $requiredRoles);
    }

    public function testTheRequiredRolesCanBeDisplayedInTheException(): void
    {
        Auth::login($this->testUser);
        config()->set(['permission.display_role_in_exception' => true]);

        $message = null;

        try {
            $this->roleMiddleware->handle(new Request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, 'some-role');
        } catch (UnauthorizedException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringEndsWith('Necessary roles are some-role', $message);
    }

    public function testUseNotExistingCustomGuardInRole(): void
    {
        $class = null;

        try {
            $this->roleMiddleware->handle(new Request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, 'testRole', 'xxx');
        } catch (InvalidArgumentException $e) {
            $class = get_class($e);
        }

        $this->assertSame(InvalidArgumentException::class, $class);
    }

    public function testUserCanNotAccessRoleWithGuardAdminWhileLoginUsingDefaultGuard(): void
    {
        Auth::login($this->testUser);

        $this->testUser->assignRole('testRole');

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'testRole', 'admin'));
    }

    public function testClientCanNotAccessRoleWithGuardAdminWhileLoginUsingDefaultGuard(): void
    {
        $this->actingAsClient($this->testClient);

        $this->testClient->assignRole('clientRole');

        $this->assertSame(403, $this->runMiddleware($this->roleMiddleware, 'clientRole', 'admin', true));
    }

    public function testUserCanAccessRoleWithGuardAdminWhileLoginUsingAdminGuard(): void
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

    public function testTheMiddlewareCanBeCreatedWithStaticUsingMethod(): void
    {
        $this->assertSame('Hypervel\Permission\Middleware\RoleMiddleware:testAdminRole', RoleMiddleware::using('testAdminRole'));

        $this->assertSame('Hypervel\Permission\Middleware\RoleMiddleware:testAdminRole,my-guard', RoleMiddleware::using('testAdminRole', 'my-guard'));

        $this->assertSame('Hypervel\Permission\Middleware\RoleMiddleware:testAdminRole|anotherRole', RoleMiddleware::using(['testAdminRole', 'anotherRole']));
    }

    public function testTheMiddlewareCanHandleEnumBasedRolesWithStaticUsingMethod(): void
    {
        $this->assertSame('Hypervel\Permission\Middleware\RoleMiddleware:writer', RoleMiddleware::using(TestRolePermissionsEnum::Writer));

        $this->assertSame('Hypervel\Permission\Middleware\RoleMiddleware:writer,my-guard', RoleMiddleware::using(TestRolePermissionsEnum::Writer, 'my-guard'));

        $this->assertSame('Hypervel\Permission\Middleware\RoleMiddleware:writer|editor', RoleMiddleware::using([TestRolePermissionsEnum::Writer, TestRolePermissionsEnum::Editor]));
    }

    public function testTheMiddlewareCanHandleEnumBasedRolesWithHandleMethod(): void
    {
        $this->app->make(Role::class)->create(['name' => TestRolePermissionsEnum::Writer->value]);
        $this->app->make(Role::class)->create(['name' => TestRolePermissionsEnum::Editor->value]);

        Auth::login($this->testUser);
        $this->testUser->assignRole(TestRolePermissionsEnum::Writer);

        $this->assertSame(200, $this->runMiddleware($this->roleMiddleware, TestRolePermissionsEnum::Writer));

        $this->testUser->assignRole(TestRolePermissionsEnum::Editor);

        $this->assertSame(200, $this->runMiddleware($this->roleMiddleware, [TestRolePermissionsEnum::Writer, TestRolePermissionsEnum::Editor]));
    }
}
