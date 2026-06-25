<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Middleware;

use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Permission\Middleware\PermissionMiddleware;
use Hypervel\Permission\Middleware\RoleMiddleware;
use Hypervel\Permission\Middleware\RoleOrPermissionMiddleware;
use Hypervel\Permission\Models\Permission;
use Hypervel\Support\Facades\Auth;
use Hypervel\Tests\Permission\TestCase;

class WildcardMiddlewareTest extends TestCase
{
    protected RoleMiddleware $roleMiddleware;

    protected PermissionMiddleware $permissionMiddleware;

    protected RoleOrPermissionMiddleware $roleOrPermissionMiddleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleMiddleware = new RoleMiddleware;
        $this->permissionMiddleware = new PermissionMiddleware;
        $this->roleOrPermissionMiddleware = new RoleOrPermissionMiddleware;

        $this->app->make('config')->set('permission.enable_wildcard_permission', true);
        $this->flushPermissionState();
    }

    public function testGuestCannotAccessPermissionProtectedRoute(): void
    {
        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'articles.edit'));
    }

    public function testUserCanAccessRouteWithWildcardPermission(): void
    {
        Auth::login($this->testUser);

        Permission::create(['name' => 'articles']);
        $this->testUser->givePermissionTo('articles');

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'articles.edit'));
    }

    public function testUserCanAccessRouteWithOneOfTheWildcardPermissions(): void
    {
        Auth::login($this->testUser);

        Permission::create(['name' => 'articles.*.test']);
        $this->testUser->givePermissionTo('articles.*.test');

        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, 'news.edit|articles.create.test'));
        $this->assertSame(200, $this->runMiddleware($this->permissionMiddleware, ['news.edit', 'articles.create.test']));
    }

    public function testUserCannotAccessRouteWithDifferentWildcardPermission(): void
    {
        Auth::login($this->testUser);

        Permission::create(['name' => 'articles.*']);
        $this->testUser->givePermissionTo('articles.*');

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'news.edit'));
    }

    public function testUserCannotAccessRouteWithNoMatchingPermission(): void
    {
        Auth::login($this->testUser);

        $this->assertSame(403, $this->runMiddleware($this->permissionMiddleware, 'articles.edit|news.edit'));
    }

    public function testUserCanAccessPermissionOrRoleProtectedRouteWithWildcardPermissionOrRole(): void
    {
        Auth::login($this->testUser);

        Permission::create(['name' => 'articles.*']);
        $this->testUser->assignRole('testRole');
        $this->testUser->givePermissionTo('articles.*');

        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole|news.edit|articles.create'));

        $this->testUser->removeRole('testRole');

        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole|articles.edit'));

        $this->testUser->revokePermissionTo('articles.*');
        $this->testUser->assignRole('testRole');

        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, 'testRole|articles.edit'));
        $this->assertSame(200, $this->runMiddleware($this->roleOrPermissionMiddleware, ['testRole', 'articles.edit']));
    }

    public function testItCanFetchRequiredPermissionsFromException(): void
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
}
