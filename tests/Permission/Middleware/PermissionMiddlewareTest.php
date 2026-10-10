<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Middleware;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Permission\Contracts\Permission;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Permission\Middleware\PermissionMiddleware;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Gate;
use Hypervel\Tests\Permission\Fixtures\Models\PlainAuthenticatableUser;
use Hypervel\Tests\Permission\Fixtures\Models\TestRolePermissionsEnum;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\Fixtures\Models\UserWithoutHasRoles;
use Hypervel\Tests\Permission\TestCase;
use InvalidArgumentException;

enum PermissionMiddlewareTestIntEnum: int
{
    case Zero = 0;
    case One = 1;
}

class PermissionMiddlewareTest extends TestCase
{
    protected PermissionMiddleware $permissionMiddleware;

    protected function setUpInCoroutine(): void
    {
        $this->setUpPassport();
        $this->permissionMiddleware = $this->app->make(PermissionMiddleware::class);
    }

    public function testAGuestCannotAccessARouteProtectedByThePermissionMiddleware(): void
    {
        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-articles'));
    }

    public function testAUserCannotAccessARouteProtectedByThePermissionMiddlewareOfADifferentGuard(): void
    {
        // These permissions are created fresh here in reverse order of guard being applied, so they are not "found first" in the db lookup when matching
        $this->app->make(Permission::class)->create(['name' => 'admin-permission2', 'guard_name' => 'web']);
        $p1 = $this->app->make(Permission::class)->create(['name' => 'admin-permission2', 'guard_name' => 'admin']);
        $this->app->make(Permission::class)->create(['name' => 'edit-articles2', 'guard_name' => 'admin']);
        $p2 = $this->app->make(Permission::class)->create(['name' => 'edit-articles2', 'guard_name' => 'web']);

        Auth::guard('admin')->login($this->testAdmin);

        $this->testAdmin->givePermissionTo($p1);

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'admin-permission2', 'admin'));
        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-articles2', 'admin'));

        Auth::login($this->testUser);

        $this->testUser->givePermissionTo($p2);

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'edit-articles2', 'web'));
        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'admin-permission2', 'web'));
    }

    public function testAClientCannotAccessARouteProtectedByThePermissionMiddlewareOfADifferentGuard(): void
    {
        // These permissions are created fresh here in reverse order of guard being applied, so they are not "found first" in the db lookup when matching
        $this->app->make(Permission::class)->create(['name' => 'admin-permission2', 'guard_name' => 'web']);
        $p1 = $this->app->make(Permission::class)->create(['name' => 'admin-permission2', 'guard_name' => 'api']);

        $this->actingAsClient($this->testClient);

        $this->testClient->givePermissionTo($p1);

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'admin-permission2', 'api', true));
        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-articles2', 'web', true));
    }

    public function testASuperAdminUserCanAccessARouteProtectedByPermissionMiddleware(): void
    {
        Auth::login($this->testUser);

        Gate::before(function (User $user, string $ability): ?bool {
            return $user->getKey() === $this->testUser->getKey() ? true : null;
        });

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'edit-articles'));
    }

    public function testAUserCanAccessARouteProtectedByPermissionMiddlewareIfHaveThisPermission(): void
    {
        Auth::login($this->testUser);

        $this->testUser->givePermissionTo('edit-articles');

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'edit-articles'));
    }

    public function testAuthorizedRequestPreservesJsonResponse(): void
    {
        Auth::login($this->testUser);

        $this->testUser->givePermissionTo('edit-articles');

        $response = new JsonResponse(['authorized' => true]);

        $this->assertSame($response, $this->permissionMiddleware->handle(
            new Request,
            fn (): JsonResponse => $response,
            'edit-articles',
        ));
    }

    public function testAClientCanAccessARouteProtectedByPermissionMiddlewareIfHaveThisPermission(): void
    {
        $this->actingAsClient($this->testClient);

        $this->testClient->givePermissionTo('edit-posts');

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'edit-posts', null, true));
    }

    public function testAClientIsNotUsedWhenPassportClientCredentialsAreDisabled(): void
    {
        $this->actingAsClient($this->testClient);
        config()->set('permission.use_passport_client_credentials', false);

        $this->testClient->givePermissionTo('edit-posts');

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-posts', 'api', true));
    }

    public function testAUserCanAccessARouteProtectedByThisPermissionMiddlewareIfHaveOneOfThePermissions(): void
    {
        Auth::login($this->testUser);

        $this->testUser->givePermissionTo('edit-articles');

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'edit-news|edit-articles'));
        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, ['edit-news', 'edit-articles']));
    }

    public function testAClientCanAccessARouteProtectedByThisPermissionMiddlewareIfHaveOneOfThePermissions(): void
    {
        $this->actingAsClient($this->testClient);

        $this->testClient->givePermissionTo('edit-posts');

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'edit-news|edit-posts', null, true));
        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, ['edit-news', 'edit-posts'], null, true));
    }

    public function testAUserCannotAccessARouteProtectedByThePermissionMiddlewareIfHaveNotHasRolesTrait(): void
    {
        $userWithoutHasRoles = UserWithoutHasRoles::create(['email' => 'test_not_has_roles@user.com']);

        Auth::login($userWithoutHasRoles);

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-news'));
    }

    public function testPlainAuthenticatableUserWithoutAuthorizableCannotAccessRoute(): void
    {
        Auth::login(PlainAuthenticatableUser::create(['email' => 'plain_authenticatable@user.com']));

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-news'));
    }

    public function testAUserCannotAccessARouteProtectedByThePermissionMiddlewareIfHaveADifferentPermission(): void
    {
        Auth::login($this->testUser);

        $this->testUser->givePermissionTo('edit-articles');

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-news'));
    }

    public function testAClientCannotAccessARouteProtectedByThePermissionMiddlewareIfHaveADifferentPermission(): void
    {
        $this->actingAsClient($this->testClient);

        $this->testClient->givePermissionTo('edit-posts');

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-news', null, true));
    }

    public function testAUserCannotAccessARouteProtectedByPermissionMiddlewareIfHaveNotPermissions(): void
    {
        Auth::login($this->testUser);

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-articles|edit-news'));
    }

    public function testAClientCannotAccessARouteProtectedByPermissionMiddlewareIfHaveNotPermissions(): void
    {
        $this->actingAsClient($this->testClient);

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-articles|edit-posts', null, true));
    }

    public function testAUserCanAccessARouteProtectedByPermissionMiddlewareIfHasPermissionViaRole(): void
    {
        Auth::login($this->testUser);

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-articles'));

        $this->testUserRole->givePermissionTo('edit-articles');
        $this->testUser->assignRole('testRole');

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'edit-articles'));
    }

    public function testAClientCanAccessARouteProtectedByPermissionMiddlewareIfHasPermissionViaRole(): void
    {
        $this->actingAsClient($this->testClient);

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-articles', null, true));
        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-posts', null, true));

        $this->testClientRole->givePermissionTo('edit-posts');
        $this->testClient->assignRole('clientRole');

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'edit-posts', null, true));
    }

    public function testTheRequiredPermissionsCanBeFetchedFromTheException(): void
    {
        Auth::login($this->testUser);

        $message = null;
        $requiredPermissions = [];

        try {
            $this->permissionMiddleware->handle(new Request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, 'some-permission');
        } catch (UnauthorizedException $e) {
            $message = $e->getMessage();
            $requiredPermissions = $e->getRequiredPermissions();
        }

        $this->assertSame('User does not have the right permissions.', $message);
        $this->assertSame(['some-permission'], $requiredPermissions);
    }

    public function testTheRequiredPermissionsCanBeDisplayedInTheException(): void
    {
        Auth::login($this->testUser);
        config()->set(['permission.display_permission_in_exception' => true]);

        $message = null;

        try {
            $this->permissionMiddleware->handle(new Request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, 'some-permission');
        } catch (UnauthorizedException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringEndsWith('Necessary permissions are some-permission', $message);
    }

    public function testUseNotExistingCustomGuardInPermission(): void
    {
        $class = null;

        try {
            $this->permissionMiddleware->handle(new Request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, 'edit-articles', 'xxx');
        } catch (InvalidArgumentException $e) {
            $class = get_class($e);
        }

        $this->assertSame(InvalidArgumentException::class, $class);
    }

    public function testUserCanNotAccessPermissionWithGuardAdminWhileLoginUsingDefaultGuard(): void
    {
        Auth::login($this->testUser);

        $this->testUser->givePermissionTo('edit-articles');

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-articles', 'admin'));
    }

    public function testClientCanNotAccessPermissionWithGuardAdminWhileLoginUsingDefaultGuard(): void
    {
        $this->actingAsClient($this->testClient);

        $this->testClient->givePermissionTo('edit-posts');

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-posts', 'admin', true));
    }

    public function testUserCanAccessPermissionWithGuardAdminWhileLoginUsingAdminGuard(): void
    {
        Auth::guard('admin')->login($this->testAdmin);

        $this->testAdmin->givePermissionTo('admin-permission');

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'admin-permission', 'admin'));
    }

    public function testEmptyGuardUsesDefaultGuard(): void
    {
        Auth::login($this->testUser);
        $this->testUser->givePermissionTo('edit-articles');

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'edit-articles', ''));
    }

    public function testTheMiddlewareCanBeCreatedWithStaticUsingMethod(): void
    {
        $this->assertSame('Hypervel\Permission\Middleware\PermissionMiddleware:edit-articles', PermissionMiddleware::using('edit-articles'));

        $this->assertSame('Hypervel\Permission\Middleware\PermissionMiddleware:edit-articles,my-guard', PermissionMiddleware::using('edit-articles', 'my-guard'));

        $this->assertSame('Hypervel\Permission\Middleware\PermissionMiddleware:edit-articles|edit-news', PermissionMiddleware::using(['edit-articles', 'edit-news']));
    }

    public function testTheMiddlewareCanHandleEnumBasedPermissionsWithStaticUsingMethod(): void
    {
        $this->assertSame('Hypervel\Permission\Middleware\PermissionMiddleware:view articles', PermissionMiddleware::using(TestRolePermissionsEnum::ViewArticles));

        $this->assertSame('Hypervel\Permission\Middleware\PermissionMiddleware:view articles,my-guard', PermissionMiddleware::using(TestRolePermissionsEnum::ViewArticles, 'my-guard'));

        $this->assertSame('Hypervel\Permission\Middleware\PermissionMiddleware:view articles|edit articles', PermissionMiddleware::using([TestRolePermissionsEnum::ViewArticles, TestRolePermissionsEnum::EditArticles]));
    }

    public function testItCanHandleIntegerEnumPermissionsWithStaticUsingMethod(): void
    {
        $this->assertSame(PermissionMiddleware::class . ':0', PermissionMiddleware::using(PermissionMiddlewareTestIntEnum::Zero));
        $this->assertSame(PermissionMiddleware::class . ':0,my-guard', PermissionMiddleware::using(PermissionMiddlewareTestIntEnum::Zero, 'my-guard'));
        $this->assertSame(PermissionMiddleware::class . ':0|1', PermissionMiddleware::using([
            PermissionMiddlewareTestIntEnum::Zero,
            PermissionMiddlewareTestIntEnum::One,
        ]));
    }

    public function testTheMiddlewareCanHandleEnumBasedPermissionsWithHandleMethod(): void
    {
        $this->app->make(Permission::class)->create(['name' => TestRolePermissionsEnum::ViewArticles->value]);
        $this->app->make(Permission::class)->create(['name' => TestRolePermissionsEnum::EditArticles->value]);

        Auth::login($this->testUser);
        $this->testUser->givePermissionTo(TestRolePermissionsEnum::ViewArticles);

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, TestRolePermissionsEnum::ViewArticles));

        $this->testUser->givePermissionTo(TestRolePermissionsEnum::EditArticles);

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, [TestRolePermissionsEnum::ViewArticles, TestRolePermissionsEnum::EditArticles]));
    }
}
