<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Integration;

use Hypervel\Tests\Permission\Fixtures\Models\TestRolePermissionsEnum;
use Hypervel\Tests\Permission\TestCase;

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
}
