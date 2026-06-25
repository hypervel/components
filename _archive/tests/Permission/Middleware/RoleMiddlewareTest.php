<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Middleware;

use Closure;
use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Container\Container;
use Hypervel\Http\Request;
use Hypervel\Permission\Exceptions\RoleException;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Permission\Middleware\RoleMiddleware;
use Hypervel\Permission\Models\Role;
use Hypervel\Tests\Permission\Enums\Role as RoleEnum;
use Hypervel\Tests\Permission\Models\User;
use Hypervel\Tests\Permission\PermissionTestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddlewareTest extends PermissionTestCase
{
    protected RoleMiddleware $middleware;

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

        $this->middleware = new RoleMiddleware($this->container);
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

        $this->middleware->handle($this->request, $this->next, 'admin');
    }

    public function testProcessThrowsUnauthorizedExceptionWhenUserMissingHasAnyRolesMethod(): void
    {
        $user = m::mock();
        $user->shouldReceive('getAuthIdentifier')->andReturn('');

        $this->authManager->shouldReceive('user')->once()->andReturn($user);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage(
            'User "" does not have the "hasAnyRoles" method. Cannot check roles: admin'
        );

        $this->middleware->handle($this->request, $this->next, 'admin');
    }

    public function testProcessThrowsRoleExceptionWhenUserLacksRole(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->authManager->shouldReceive('user')->once()->andReturn($user);

        $this->expectException(RoleException::class);
        $this->expectExceptionMessage(
            'User "' . $user->getAuthIdentifier() . '" does not have any of the required roles: admin'
        );

        $this->middleware->handle($this->request, $this->next, 'admin');
    }

    public function testProcessSucceedsWhenUserHasRole(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Role::create([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $user->assignRole('admin');

        $this->authManager->shouldReceive('user')->once()->andReturn($user);

        $result = $this->middleware->handle($this->request, $this->next, 'admin');

        $this->assertSame($this->response, $result);
    }

    public function testProcessWithMultipleRolesSucceedsWhenUserHasAny(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Role::create([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $user->assignRole('admin');

        $this->authManager->shouldReceive('user')->once()->andReturn($user);

        $result = $this->middleware->handle($this->request, $this->next, 'admin', 'viewer');

        $this->assertSame($this->response, $result);
    }

    public function testParseRolesToStringWithMixedArray(): void
    {
        $result = RoleMiddleware::parseRolesToString([
            'admin',
            RoleEnum::Viewer,
            'manager',
        ]);

        $this->assertEquals('admin|viewer|manager', $result);
    }
}
