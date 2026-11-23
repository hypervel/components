<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Integration;

use Hypervel\Permission\Contracts\Permission;
use Hypervel\Tests\Permission\Fixtures\Models\TestRolePermissionsEnum;
use Hypervel\Tests\Permission\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RouteTest extends TestCase
{
    public function testRoleFunction(): void
    {
        $router = $this->getRouter();

        $router->get('role-test', $this->getRouteResponse())
            ->name('role.test')
            ->role('superadmin');

        $this->assertSame(['role:superadmin'], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testPermissionFunction(): void
    {
        $router = $this->getRouter();

        $router->get('permission-test', $this->getRouteResponse())
            ->name('permission.test')
            ->permission(['edit articles', 'save articles']);

        $this->assertSame(['permission:edit articles|save articles'], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testRoleAndPermissionFunctionTogether(): void
    {
        $router = $this->getRouter();

        $router->get('role-permission-test', $this->getRouteResponse())
            ->name('role-permission.test')
            ->role('superadmin|admin')
            ->permission('create user|edit user');

        $this->assertSame([
            'role:superadmin|admin',
            'permission:create user|edit user',
        ], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testRoleFunctionWithBackedEnum(): void
    {
        $router = $this->getRouter();

        $router->get('role-test.enum', $this->getRouteResponse())
            ->name('role.test.enum')
            ->role(TestRolePermissionsEnum::UserManager);

        $this->assertSame(['role:' . TestRolePermissionsEnum::UserManager->value], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testPermissionFunctionWithBackedEnum(): void
    {
        $router = $this->getRouter();

        $router->get('permission-test.enum', $this->getRouteResponse())
            ->name('permission.test.enum')
            ->permission(TestRolePermissionsEnum::Writer);

        $this->assertSame(['permission:' . TestRolePermissionsEnum::Writer->value], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testRoleAndPermissionFunctionTogetherWithBackedEnum(): void
    {
        $router = $this->getRouter();

        $router->get('roles-permissions-test.enum', $this->getRouteResponse())
            ->name('roles-permissions.test.enum')
            ->role([TestRolePermissionsEnum::UserManager, TestRolePermissionsEnum::Admin])
            ->permission([TestRolePermissionsEnum::Writer, TestRolePermissionsEnum::Editor]);

        $this->assertSame([
            'role:' . TestRolePermissionsEnum::UserManager->value . '|' . TestRolePermissionsEnum::Admin->value,
            'permission:' . TestRolePermissionsEnum::Writer->value . '|' . TestRolePermissionsEnum::Editor->value,
        ], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testRoleOrPermissionFunction(): void
    {
        $router = $this->getRouter();

        $router->get('role-or-permission-test', $this->getRouteResponse())
            ->name('role-or-permission.test')
            ->roleOrPermission('admin|edit articles');

        $this->assertSame(['role_or_permission:admin|edit articles'], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testRoleOrPermissionFunctionWithArray(): void
    {
        $router = $this->getRouter();

        $router->get('role-or-permission-array-test', $this->getRouteResponse())
            ->name('role-or-permission-array.test')
            ->roleOrPermission(['admin', 'edit articles']);

        $this->assertSame(['role_or_permission:admin|edit articles'], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testRoleOrPermissionFunctionWithBackedEnum(): void
    {
        $router = $this->getRouter();

        $router->get('role-or-permission-test.enum', $this->getRouteResponse())
            ->name('role-or-permission.test.enum')
            ->roleOrPermission(TestRolePermissionsEnum::UserManager);

        $this->assertSame(['role_or_permission:' . TestRolePermissionsEnum::UserManager->value], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testRoleOrPermissionFunctionWithBackedEnumArray(): void
    {
        $router = $this->getRouter();

        $router->get('role-or-permission-array-test.enum', $this->getRouteResponse())
            ->name('role-or-permission-array.test.enum')
            ->roleOrPermission([TestRolePermissionsEnum::UserManager, TestRolePermissionsEnum::EditArticles]);

        $this->assertSame([
            'role_or_permission:' . TestRolePermissionsEnum::UserManager->value . '|' . TestRolePermissionsEnum::EditArticles->value,
        ], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testRoleFunctionOnRouteGroup(): void
    {
        $router = $this->getRouter();

        $router->prefix('admin')->role('superadmin')->group(function () use ($router): void {
            $router->get('role-group-test', $this->getRouteResponse())->name('role-group.test');
        });

        $this->assertSame(['role:superadmin'], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testPermissionFunctionOnRouteGroup(): void
    {
        $router = $this->getRouter();

        $router->prefix('articles')->permission(['edit articles', 'save articles'])->group(function () use ($router): void {
            $router->get('permission-group-test', $this->getRouteResponse())->name('permission-group.test');
        });

        $this->assertSame(['permission:edit articles|save articles'], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testRoleOrPermissionFunctionOnRouteGroup(): void
    {
        $router = $this->getRouter();

        $router->prefix('articles')->roleOrPermission(['admin', 'edit articles'])->group(function () use ($router): void {
            $router->get('role-or-permission-group-test', $this->getRouteResponse())->name('role-or-permission-group.test');
        });

        $this->assertSame(['role_or_permission:admin|edit articles'], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testPermissionFunctionOnRouteGroupWithBackedEnum(): void
    {
        $router = $this->getRouter();

        $router->prefix('articles')->permission([TestRolePermissionsEnum::Writer, TestRolePermissionsEnum::Editor])->group(function () use ($router): void {
            $router->get('permission-group-test.enum', $this->getRouteResponse())->name('permission-group.test.enum');
        });

        $this->assertSame([
            'permission:' . TestRolePermissionsEnum::Writer->value . '|' . TestRolePermissionsEnum::Editor->value,
        ], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testRoleAndPermissionFunctionsOnRouter(): void
    {
        $router = $this->getRouter();

        $router->role('superadmin')->permission('edit articles')->group(function () use ($router): void {
            $router->get('router-group-test', $this->getRouteResponse())->name('router-group.test');
        });

        $this->assertSame([
            'role:superadmin',
            'permission:edit articles',
        ], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testRoleAndPermissionFunctionsOnRouteGroupKeepExistingMiddleware(): void
    {
        $router = $this->getRouter();

        $router->middleware('auth')->role('superadmin')->permission('edit articles')->group(function () use ($router): void {
            $router->get('route-group-middleware-test', $this->getRouteResponse())->name('route-group-middleware.test');
        });

        $this->assertSame([
            'auth',
            'role:superadmin',
            'permission:edit articles',
        ], $this->getLastRouteMiddlewareFromRouter($router));
    }

    #[DataProvider('groupMacroProvider')]
    public function testEnforcesGroupMacrosAfterAuthAndRetainsThemInNestedMiddlewareGroups(
        string $macro,
        string $requirement,
        string $grantMethod,
        string $grant,
    ): void {
        $router = $this->getRouter();

        $router->middleware('auth')->{$macro}($requirement)->group(function () use ($router): void {
            $router->get('direct', fn () => 'allowed');

            $router->middleware('permission:edit-news')->group(function () use ($router): void {
                $router->get('nested', fn () => 'allowed');
            });
        });
        $router->get('outside', fn () => 'public');

        $this->get('/outside')->assertOk();
        $this->getJson('/direct')->assertUnauthorized();
        $this->getJson('/nested')->assertUnauthorized();

        $this->actingAs($this->testUser);
        $this->get('/direct')->assertForbidden();
        $this->get('/nested')->assertForbidden();

        $this->testUser->{$grantMethod}($grant);
        $this->get('/direct')->assertOk()->assertSee('allowed');
        $this->get('/nested')->assertForbidden();

        $this->testUser->givePermissionTo('edit-news');
        $this->get('/nested')->assertOk()->assertSee('allowed');

        $this->testUser->{$grantMethod === 'assignRole' ? 'removeRole' : 'revokePermissionTo'}($grant);
        $this->get('/nested')->assertForbidden();
    }

    /**
     * Provide each group macro with a requirement and the grant that satisfies it.
     */
    public static function groupMacroProvider(): array
    {
        return [
            ['role', 'testRole|testRole2', 'assignRole', 'testRole2'],
            ['permission', 'edit-blog|edit-articles', 'givePermissionTo', 'edit-articles'],
            ['roleOrPermission', 'testRole|edit-articles', 'givePermissionTo', 'edit-articles'],
            ['canAny', 'edit-blog|edit-articles', 'givePermissionTo', 'edit-articles'],
            ['canAll', 'edit-articles', 'givePermissionTo', 'edit-articles'],
        ];
    }

    #[DataProvider('macroProvider')]
    public function testUsesHypervelMiddlewareReplacementOnRegistrarsButAppendsOnIndividualRoutes(string $macro): void
    {
        $router = $this->getRouter();

        $router->{$macro}('unassigned')->middleware('auth')->group(function () use ($router): void {
            $router->get('group', fn () => 'allowed');
        });
        $router->get('individual', fn () => 'allowed')->{$macro}('unassigned')->middleware('auth');

        $this->getJson('/group')->assertUnauthorized();

        $this->actingAs($this->testUser);
        $this->get('/group')->assertOk()->assertSee('allowed');
        $this->get('/individual')->assertForbidden();
    }

    /**
     * Provide every route macro.
     */
    public static function macroProvider(): array
    {
        return [['role'], ['permission'], ['roleOrPermission'], ['canAny'], ['canAll']];
    }

    #[DataProvider('listedPermissionProvider')]
    public function testRequiresAnyListedPermissionForCanAnyAndEveryListedPermissionForCanAll(
        string $macro,
        bool $allowedWithOnePermission,
        array $permissions,
        string $firstGrant,
    ): void {
        $router = $this->getRouter();
        $this->app->make(Permission::class)->create(['name' => 'writer']);

        $router->middleware('auth')->{$macro}($permissions)->group(function () use ($router): void {
            $router->get('group', fn () => 'allowed');
        });
        $router->get('individual', fn () => 'allowed')->middleware('auth')->{$macro}($permissions);
        $router->{$macro}($permissions)->group(function () use ($router): void {
            $router->get('router', fn () => 'allowed');
        });
        $router->canAll('edit-blog')->group(function () use ($router): void {
            $router->get('sibling', fn () => 'allowed');
        });
        $router->get('public', fn () => 'public');

        $this->get('/public')->assertOk();
        $this->getJson('/group')->assertUnauthorized();
        $this->getJson('/individual')->assertUnauthorized();
        $this->get('/router')->assertForbidden();

        $this->actingAs($this->testUser);
        $this->get('/group')->assertForbidden();
        $this->get('/individual')->assertForbidden();
        $this->get('/router')->assertForbidden();

        $this->testUser->givePermissionTo($firstGrant);
        $this->get('/group')->assertStatus($allowedWithOnePermission ? 200 : 403);
        $this->get('/individual')->assertStatus($allowedWithOnePermission ? 200 : 403);
        $this->get('/router')->assertStatus($allowedWithOnePermission ? 200 : 403);

        $this->testUser->givePermissionTo('edit-news');
        $this->get('/group')->assertOk()->assertSee('allowed');
        $this->get('/individual')->assertOk()->assertSee('allowed');
        $this->get('/router')->assertOk()->assertSee('allowed');
        $this->get('/sibling')->assertForbidden();

        $this->testUser->revokePermissionTo($firstGrant);
        $this->get('/group')->assertStatus($allowedWithOnePermission ? 200 : 403);
        $this->get('/individual')->assertStatus($allowedWithOnePermission ? 200 : 403);
        $this->get('/router')->assertStatus($allowedWithOnePermission ? 200 : 403);

        $this->testUser->revokePermissionTo('edit-news');
        $this->get('/group')->assertForbidden();
        $this->get('/individual')->assertForbidden();
        $this->get('/router')->assertForbidden();
    }

    /**
     * Provide canAny and canAll permission lists and whether one listed permission is enough.
     */
    public static function listedPermissionProvider(): array
    {
        return [
            ['canAny', true, ['edit-articles', 'edit-news'], 'edit-articles'],
            ['canAll', false, ['edit-articles', 'edit-news'], 'edit-articles'],
            ['canAny', true, [TestRolePermissionsEnum::Writer, 'edit-news'], 'writer'],
            ['canAll', false, [TestRolePermissionsEnum::Writer, 'edit-news'], 'writer'],
        ];
    }

    #[DataProvider('scalarPermissionProvider')]
    public function testAcceptsScalarPermissionsWithoutReplacingNativeCanAuthorization(
        string $macro,
        string|TestRolePermissionsEnum $permission,
    ): void {
        $this->app->make(Permission::class)->create(['name' => 'writer']);
        $router = $this->getRouter();

        $router->get('allowed', fn () => 'allowed')->{$macro}($permission)->can('edit-news');

        $this->actingAs($this->testUser);
        $this->get('/allowed')->assertForbidden();

        $this->testUser->givePermissionTo('writer');
        $this->get('/allowed')->assertForbidden();

        $this->testUser->givePermissionTo('edit-news');
        $this->get('/allowed')->assertOk();

        $this->testUser->revokePermissionTo('writer');
        $this->get('/allowed')->assertForbidden();
    }

    /**
     * Provide canAny and canAll with a string and an enum permission.
     */
    public static function scalarPermissionProvider(): array
    {
        return [
            ['canAny', 'writer'],
            ['canAny', TestRolePermissionsEnum::Writer],
            ['canAll', 'writer'],
            ['canAll', TestRolePermissionsEnum::Writer],
        ];
    }

    #[DataProvider('permissionAliasMacroProvider')]
    public function testUsesTheGuardSelectedByAuthenticationMiddlewareForPermissionAliases(string $macro): void
    {
        $router = $this->getRouter();

        $router->middleware('auth:admin')->{$macro}('admin-permission')->group(function () use ($router, $macro): void {
            $router->get('admin', fn () => 'allowed')->{$macro}('admin-permission');
        });

        $this->actingAs($this->testUser, 'web');
        $this->getJson('/admin')->assertUnauthorized();

        $this->actingAs($this->testAdmin, 'admin');
        $this->get('/admin')->assertForbidden();

        $this->testAdmin->givePermissionTo('admin-permission');
        $this->get('/admin')->assertOk();

        $this->testAdmin->revokePermissionTo('admin-permission');
        $this->get('/admin')->assertForbidden();
    }

    /**
     * Provide the canAny and canAll permission aliases.
     */
    public static function permissionAliasMacroProvider(): array
    {
        return [['canAny'], ['canAll']];
    }

    public function testRequiresEveryCanAllEntryWhilePreservingPipeAlternativesWithinAnEntry(): void
    {
        $router = $this->getRouter();

        $router->canAll(['edit-articles|edit-blog', 'edit-news'])->group(function () use ($router): void {
            $router->get('articles', fn () => 'allowed');
        });

        $this->actingAs($this->testUser);
        $this->testUser->givePermissionTo('edit-news');
        $this->get('/articles')->assertForbidden();

        $this->testUser->givePermissionTo('edit-blog');
        $this->get('/articles')->assertOk();

        $this->testUser->revokePermissionTo('edit-news');
        $this->get('/articles')->assertForbidden();
    }

    public function testAddsNoAuthorizationChecksForAnEmptyCanAllList(): void
    {
        $router = $this->getRouter();

        $router->canAll([])->group(function () use ($router): void {
            $router->get('group', fn () => 'public')->canAll([]);
        });
        $router->middleware('auth')->canAll([])->group(function () use ($router): void {
            $router->get('authenticated', fn () => 'allowed');
        });

        $this->get('/group')->assertOk();
        $this->getJson('/authenticated')->assertUnauthorized();

        $this->actingAs($this->testUser);
        $this->get('/authenticated')->assertOk();
    }
}
