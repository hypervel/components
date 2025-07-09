<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Middlewares;

use Hypervel\Permission\Exceptions\RoleException;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Permission\Middlewares\RoleMiddleware;
use Hypervel\Permission\Models\Role;
use Hypervel\Support\Facades\Auth;
use Hypervel\Tests\Permission\Enums\Role as RoleEnum;
use Hypervel\Tests\Permission\Models\User;
use Hypervel\Tests\Permission\PermissionTestCase;
use Mockery as m;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal
 * @coversNothing
 */
class RoleMiddlewareTest extends PermissionTestCase
{
    protected RoleMiddleware $middleware;

    protected ServerRequestInterface $request;

    protected RequestHandlerInterface $handler;

    protected ResponseInterface $response;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new RoleMiddleware();
        $this->request = m::mock(ServerRequestInterface::class);
        $this->handler = m::mock(RequestHandlerInterface::class);
        $this->response = m::mock(ResponseInterface::class);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function testProcessThrowsUnauthorizedExceptionWhenUserNotLoggedIn(): void
    {
        Auth::shouldReceive('user')->once()->andReturn(null);

        $this->expectException(UnauthorizedException::class);

        $this->middleware->process($this->request, $this->handler, 'admin');
    }

    public function testProcessThrowsUnauthorizedExceptionWhenUserMissingHasAnyRolesMethod(): void
    {
        $user = m::mock();
        $user->shouldReceive('getAuthIdentifier')->andReturn('');

        Auth::shouldReceive('user')->once()->andReturn($user);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage(
            'User "" does not have the "hasAnyRoles" method. Cannot check roles: admin'
        );

        $this->middleware->process($this->request, $this->handler, 'admin');
    }

    public function testProcessThrowsRoleExceptionWhenUserLacksRole(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Auth::shouldReceive('user')->once()->andReturn($user);

        $this->expectException(RoleException::class);
        $this->expectExceptionMessage(
            'User "' . $user->getAuthIdentifier() . '" does not have any of the required roles: admin'
        );

        $this->middleware->process($this->request, $this->handler, 'admin');
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

        Auth::shouldReceive('user')->once()->andReturn($user);
        $this->handler->shouldReceive('handle')->once()->with($this->request)->andReturn($this->response);

        $result = $this->middleware->process($this->request, $this->handler, 'admin');

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

        Auth::shouldReceive('user')->once()->andReturn($user);
        $this->handler->shouldReceive('handle')->once()->with($this->request)->andReturn($this->response);

        $result = $this->middleware->process($this->request, $this->handler, 'admin', 'viewer');

        $this->assertSame($this->response, $result);
    }

    public function testParseRolesToStringWithMixedArray(): void
    {
        $result = RoleMiddleware::parseRolesToString([
            'admin',
            RoleEnum::VIEWER,
            'manager',
        ]);

        $this->assertEquals('admin,viewer,manager', $result);
    }
}

