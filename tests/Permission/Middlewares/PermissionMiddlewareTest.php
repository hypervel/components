<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Middlewares;

use Hyperf\Contract\ContainerInterface;
use Hypervel\Auth\AuthManager;
use Hypervel\Permission\Exceptions\PermissionException;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Permission\Middlewares\PermissionMiddleware;
use Hypervel\Permission\Models\Permission;
use Hypervel\Tests\Permission\Enums\Permission as PermissionEnum;
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
class PermissionMiddlewareTest extends PermissionTestCase
{
    protected PermissionMiddleware $middleware;

    protected ServerRequestInterface $request;

    protected RequestHandlerInterface $handler;

    protected ResponseInterface $response;

    protected ContainerInterface $container;

    protected AuthManager $authManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = m::mock(ContainerInterface::class);
        $this->authManager = m::mock(AuthManager::class);
        $this->container->shouldReceive('get')
            ->with(AuthManager::class)
            ->andReturn($this->authManager);

        $this->middleware = new PermissionMiddleware($this->container);
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
        $this->authManager->shouldReceive('user')->once()->andReturn(null);

        $this->expectException(UnauthorizedException::class);

        $this->middleware->process($this->request, $this->handler, 'view');
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

        $this->middleware->process($this->request, $this->handler, 'view');
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

        $this->middleware->process($this->request, $this->handler, 'view');
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
            'is_forbidden' => false,
        ]);

        $user->givePermissionTo('view');

        $this->authManager->shouldReceive('user')->once()->andReturn($user);
        $this->handler->shouldReceive('handle')->once()->with($this->request)->andReturn($this->response);

        $result = $this->middleware->process($this->request, $this->handler, 'view');

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
            'is_forbidden' => false,
        ]);

        $user->givePermissionTo('view');

        $this->authManager->shouldReceive('user')->once()->andReturn($user);
        $this->handler->shouldReceive('handle')->once()->with($this->request)->andReturn($this->response);

        $result = $this->middleware->process($this->request, $this->handler, 'view', 'edit');

        $this->assertSame($this->response, $result);
    }

    public function testParsePermissionsToStringWithMixedArray(): void
    {
        $result = PermissionMiddleware::parsePermissionsToString([
            'view',
            PermissionEnum::EDIT,
            'manage',
        ]);

        $this->assertEquals('view|edit|manage', $result);
    }
}
