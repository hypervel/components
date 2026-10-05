<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Integration;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Tests\Permission\TestCase;

class WildcardRouteTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('permission.enable_wildcard_permission', true);
    }

    public function testPermissionFunction(): void
    {
        $router = $this->getRouter();

        $router->get('permission-test', $this->getRouteResponse())
            ->name('permission.test')
            ->permission(['articles.edit', 'articles.save']);

        $this->assertSame(['permission:articles.edit|articles.save'], $this->getLastRouteMiddlewareFromRouter($router));
    }

    public function testRoleAndPermissionFunctionTogether(): void
    {
        $router = $this->getRouter();

        $router->get('role-permission-test', $this->getRouteResponse())
            ->name('role-permission.test')
            ->role('superadmin|admin')
            ->permission('user.create|user.edit');

        $this->assertSame([
            'role:superadmin|admin',
            'permission:user.create|user.edit',
        ], $this->getLastRouteMiddlewareFromRouter($router));
    }
}
