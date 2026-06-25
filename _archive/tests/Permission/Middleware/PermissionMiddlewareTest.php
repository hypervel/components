<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Middleware;

use Closure;
use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Container\Container;
use Hypervel\Http\Request;
use Hypervel\Permission\Exceptions\PermissionException;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Permission\Middleware\PermissionMiddleware;
use Hypervel\Permission\Models\Permission;
use Hypervel\Tests\Permission\Enums\Permission as PermissionEnum;
use Hypervel\Tests\Permission\Models\User;
use Hypervel\Tests\Permission\PermissionTestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddlewareTest extends PermissionTestCase
{
    protected PermissionMiddleware $middleware;

    protected Request $request;

    protected Closure $next;

    protected Response $response;

    protected Container $container;

    protected AuthManager $authManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = m::mock(Container::class);
        $this->authManager = m::mock(AuthManager::class);
        $this->container->shouldReceive('make')
            ->with('auth')
            ->andReturn($this->authManager);

        $this->middleware = new PermissionMiddleware($this->container);
        $this->request = Request::create('http://example.com');
        $this->response = new Response;
        $this->next = fn () => $this->response;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testProcessThrowsUnauthorizedExceptionWhenUserNotLoggedIn(): void
    {
        $this->authManager->shouldReceive('user')->once()->andReturn(null);

        $this->expectException(UnauthorizedException::class);

        $this->middleware->handle($this->request, $this->next, 'view');
    }

    public function testProcessThrowsUnauthorizedExceptionWhenUserMissingHasAnyPermissionMethod(): void
    {
        $user = m::mock();
        $user->shouldReceive('getAuthIdentifier')->andReturn('');

        $this->authManager->shouldReceive('user')->once()->andReturn($user);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage(
            'User "" does not have the "hasAnyPermissions" method. Cannot check permissions: view'
        );

        $this->middleware->handle($this->request, $this->next, 'view');
    }

    public function testProcessThrowsPermissionExceptionWhenUserLacksPermission(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->authManager->shouldReceive('user')->once()->andReturn($user);

        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage(
            'User "' . $user->getAuthIdentifier() . '" does not have any of the required permissions: view'
        );

        $this->middleware->handle($this->request, $this->next, 'view');
    }

    public function testProcessSucceedsWhenUserHasPermission(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Permission::create([
            'name' => 'view',
            'guard_name' => 'web',
        ]);

        $user->givePermissionTo('view');

        $this->authManager->shouldReceive('user')->once()->andReturn($user);

        $result = $this->middleware->handle($this->request, $this->next, 'view');

        $this->assertSame($this->response, $result);
    }

    public function testProcessWithMultiplePermissionsSucceedsWhenUserHasAny(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Permission::create([
            'name' => 'view',
            'guard_name' => 'web',
        ]);

        $user->givePermissionTo('view');

        $this->authManager->shouldReceive('user')->once()->andReturn($user);

        $result = $this->middleware->handle($this->request, $this->next, 'view', 'edit');

        $this->assertSame($this->response, $result);
    }

    public function testParsePermissionsToStringWithMixedArray(): void
    {
        $result = PermissionMiddleware::parsePermissionsToString([
            'view',
            PermissionEnum::Edit,
            'manage',
        ]);

        $this->assertEquals('view|edit|manage', $result);
    }
}
