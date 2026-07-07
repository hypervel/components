<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Middleware;

use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Permission\Contracts\Permission;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Permission\Middleware\PermissionMiddleware;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Gate;
use Hypervel\Tests\Permission\Fixtures\Models\PlainAuthenticatableUser;
use Hypervel\Tests\Permission\Fixtures\Models\TestRolePermissionsEnum;
use Hypervel\Tests\Permission\Fixtures\Models\UserWithoutHasRoles;
use Hypervel\Tests\Permission\TestCase;
use InvalidArgumentException;

class PermissionMiddlewareTest extends TestCase
{
    protected PermissionMiddleware $permissionMiddleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->permissionMiddleware = $this->app->make(PermissionMiddleware::class);
    }

    public function testGuestCannotAccessPermissionProtectedRoute(): void
    {
        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-articles'));
    }

    public function testUserCanAccessRouteWithDirectPermission(): void
    {
        Auth::login($this->testUser);

        $this->testUser->givePermissionTo('edit-articles');

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'edit-articles'));
    }

    public function testSuperAdminGateBeforeCanAccessPermissionProtectedRoute(): void
    {
        Auth::login($this->testUser);

        Gate::before(fn ($user): ?bool => $user->getKey() === $this->testUser->getKey() ? true : null);

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'edit-articles'));
    }

    public function testUserCanAccessRouteWithOneOfSeveralPermissions(): void
    {
        Auth::login($this->testUser);

        $this->testUser->givePermissionTo('edit-articles');

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'edit-news|edit-articles'));
        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, ['edit-news', 'edit-articles']));
    }

    public function testUserCanAccessRouteWithWildcardPermission(): void
    {
        $this->app->make('config')->set('permission.enable_wildcard_permission', true);
        $this->flushPermissionState();

        Auth::login($this->testUser);

        $this->app->make(Permission::class)::create(['name' => 'articles.*.test']);
        $this->testUser->givePermissionTo('articles.*.test');

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'news.edit|articles.create.test'));
        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, ['news.edit', 'articles.create.test']));
        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'articles.create.other'));
    }

    public function testUserCannotAccessRouteWithoutMatchingPermission(): void
    {
        Auth::login($this->testUser);

        $this->testUser->givePermissionTo('edit-articles');

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-news'));
    }

    public function testUserCannotAccessRouteWithoutPermissions(): void
    {
        Auth::login($this->testUser);

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-articles|edit-news'));
    }

    public function testUserCanAccessRouteWithPermissionViaRole(): void
    {
        Auth::login($this->testUser);

        $this->testUserRole->givePermissionTo('edit-articles');
        $this->testUser->assignRole('testRole');

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'edit-articles'));
    }

    public function testUserWithoutHasRolesTraitCannotAccessRoute(): void
    {
        Auth::login(UserWithoutHasRoles::create(['email' => 'test_not_has_roles@user.com']));

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-news'));
    }

    public function testPlainAuthenticatableUserWithoutAuthorizableCannotAccessRoute(): void
    {
        Auth::login(PlainAuthenticatableUser::create(['email' => 'plain_authenticatable@user.com']));

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-news'));
    }

    public function testGuardSpecificPermissionIsUsed(): void
    {
        $this->app->make(Permission::class)->create(['name' => 'admin-permission2', 'guard_name' => 'web']);
        $adminPermission = $this->app->make(Permission::class)->create(['name' => 'admin-permission2', 'guard_name' => 'admin']);

        Auth::guard('admin')->login($this->testAdmin);

        $this->testAdmin->givePermissionTo($adminPermission);

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'admin-permission2', 'admin'));
        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'admin-permission2', 'web'));
    }

    public function testUserCannotAccessPermissionWithAdminGuardWhileLoggedInUsingDefaultGuard(): void
    {
        Auth::login($this->testUser);

        $this->testUser->givePermissionTo('edit-articles');

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'edit-articles', 'admin'));
    }

    public function testUserCanAccessPermissionWithAdminGuardWhileLoggedInUsingAdminGuard(): void
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

    public function testItCanBeCreatedWithStaticUsingMethod(): void
    {
        $this->assertSame(PermissionMiddleware::class . ':edit-articles', PermissionMiddleware::using('edit-articles'));
        $this->assertSame(PermissionMiddleware::class . ':edit-articles,my-guard', PermissionMiddleware::using('edit-articles', 'my-guard'));
        $this->assertSame(PermissionMiddleware::class . ':edit-articles|edit-news', PermissionMiddleware::using(['edit-articles', 'edit-news']));
    }

    public function testItCanHandleEnumPermissionsWithStaticUsingMethod(): void
    {
        $this->assertSame(PermissionMiddleware::class . ':view articles', PermissionMiddleware::using(TestRolePermissionsEnum::ViewArticles));
        $this->assertSame(PermissionMiddleware::class . ':view articles,my-guard', PermissionMiddleware::using(TestRolePermissionsEnum::ViewArticles, 'my-guard'));
        $this->assertSame(PermissionMiddleware::class . ':view articles|edit articles', PermissionMiddleware::using([
            TestRolePermissionsEnum::ViewArticles,
            TestRolePermissionsEnum::EditArticles,
        ]));
    }

    public function testItCanHandleEnumPermissionsWithHandleMethod(): void
    {
        $this->app->make(Permission::class)->create(['name' => TestRolePermissionsEnum::ViewArticles->value]);
        $this->app->make(Permission::class)->create(['name' => TestRolePermissionsEnum::EditArticles->value]);

        Auth::login($this->testUser);
        $this->testUser->givePermissionTo(TestRolePermissionsEnum::ViewArticles);

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, TestRolePermissionsEnum::ViewArticles));

        $this->testUser->givePermissionTo(TestRolePermissionsEnum::EditArticles);

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, [
            TestRolePermissionsEnum::ViewArticles,
            TestRolePermissionsEnum::EditArticles,
        ]));
    }

    public function testItExposesRequiredPermissionsOnTheUnauthorizedException(): void
    {
        Auth::login($this->testUser);

        try {
            $this->permissionMiddleware->handle(new Request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, 'permission.some');
        } catch (UnauthorizedException $exception) {
            $this->assertSame(['permission.some'], $exception->getRequiredPermissions());

            return;
        }

        $this->fail('Expected unauthorized permission exception was not thrown.');
    }

    public function testItCanDisplayRequiredPermissionsOnTheUnauthorizedException(): void
    {
        Auth::login($this->testUser);
        $this->app->make('config')->set('permission.display_permission_in_exception', true);

        try {
            $this->permissionMiddleware->handle(new Request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, 'some-permission');
        } catch (UnauthorizedException $exception) {
            $this->assertStringEndsWith('Necessary permissions are some-permission', $exception->getMessage());

            return;
        }

        $this->fail('Expected unauthorized permission exception was not thrown.');
    }

    public function testItThrowsForMissingCustomGuard(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->permissionMiddleware->handle(new Request, function (): Response {
            return (new Response)->setContent('<html></html>');
        }, 'edit-articles', 'xxx');
    }
}
