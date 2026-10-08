<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Permission\Middleware\PermissionMiddleware;
use Hypervel\Permission\Middleware\RoleMiddleware;
use Hypervel\Permission\Middleware\RoleOrPermissionMiddleware;
use Hypervel\Routing\Router;
use ReflectionMethod;

class PublicApiTest extends TestCase
{
    public function testMiddlewareAliasesAreRegistered(): void
    {
        $router = $this->app->make(Router::class);

        $this->assertSame(RoleMiddleware::class, $router->getMiddleware()['role']);
        $this->assertSame(PermissionMiddleware::class, $router->getMiddleware()['permission']);
        $this->assertSame(RoleOrPermissionMiddleware::class, $router->getMiddleware()['role_or_permission']);
    }

    public function testPermissionRelationsKeepLaravelBaseReturnTypes(): void
    {
        $this->assertInstanceOf(MorphToMany::class, $this->testUser->roles());
        $this->assertInstanceOf(MorphToMany::class, $this->testUser->permissions());
        $this->assertInstanceOf(BelongsToMany::class, $this->testUserRole->permissions());
        $this->assertInstanceOf(BelongsToMany::class, $this->testUserPermission->roles());
        $this->assertInstanceOf(BelongsToMany::class, $this->testUserRole->users());
        $this->assertInstanceOf(BelongsToMany::class, $this->testUserPermission->users());
        $this->assertInstanceOf(BelongsToMany::class, $this->testUser->teams());

        foreach (['roles', 'permissions', 'teams'] as $relation) {
            $method = new ReflectionMethod($this->testUser, $relation);

            $this->assertSame(BelongsToMany::class, (string) $method->getReturnType());
            $this->assertSame([], $method->getParameters());
        }
    }
}
