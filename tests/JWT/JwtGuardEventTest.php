<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT;

use Hypervel\Auth\Events\Attempting;
use Hypervel\Auth\Events\Authenticated;
use Hypervel\Auth\Events\Failed;
use Hypervel\Auth\Events\Login;
use Hypervel\Auth\Events\Logout;
use Hypervel\Auth\Events\Validated;
use Hypervel\Config\Repository;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Http\Request;
use Hypervel\JWT\ClaimFactory;
use Hypervel\JWT\Contracts\ManagerContract;
use Hypervel\JWT\Http\Parser\AuthHeaders;
use Hypervel\JWT\Http\Parser\InputSource;
use Hypervel\JWT\Http\Parser\Parser;
use Hypervel\JWT\JwtGuard;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class JwtGuardEventTest extends TestCase
{
    public function testDoesNotDispatchEventsWhenNoListenersExist(): void
    {
        $user = $this->user(1);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->once()->andReturn('token');

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturnFalse();
        $events->shouldNotReceive('dispatch');

        $guard = $this->createGuard(jwtManager: $jwtManager);
        $guard->setDispatcher($events);
        $guard->login($user);
    }

    public function testAttemptingEventIsDispatchedWhenListening(): void
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->once()->andReturn(null);

        $events = $this->dispatcherListeningFor(Attempting::class, function (Attempting $event): bool {
            return $event->guard === 'jwt'
                && $event->credentials === ['email' => 'foo@example.test']
                && $event->remember === false;
        });

        $guard = $this->createGuard(provider: $provider);
        $guard->setDispatcher($events);

        $this->assertFalse($guard->attempt(['email' => 'foo@example.test']));
    }

    public function testValidatedEventIsDispatchedWhenListening(): void
    {
        $user = $this->user(1);

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->once()->andReturn($user);
        $provider->shouldReceive('validateCredentials')->once()->andReturnTrue();

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->once()->andReturn('token');

        $events = $this->dispatcherListeningFor(Validated::class, function (Validated $event) use ($user): bool {
            return $event->guard === 'jwt' && $event->user === $user;
        });

        $guard = $this->createGuard(provider: $provider, jwtManager: $jwtManager);
        $guard->setDispatcher($events);

        $this->assertSame('token', $guard->attempt(['email' => 'foo@example.test']));
    }

    public function testFailedEventIsDispatchedWhenListening(): void
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->once()->andReturn(null);

        $events = $this->dispatcherListeningFor(Failed::class, function (Failed $event): bool {
            return $event->guard === 'jwt'
                && $event->user === null
                && $event->credentials === ['email' => 'foo@example.test'];
        });

        $guard = $this->createGuard(provider: $provider);
        $guard->setDispatcher($events);

        $this->assertFalse($guard->attempt(['email' => 'foo@example.test']));
    }

    public function testLoginAndAuthenticatedEventsAreDispatchedWhenListening(): void
    {
        $user = $this->user(1);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->once()->andReturn('token');

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->with(Authenticated::class)->once()->andReturnTrue();
        $events->shouldReceive('hasListeners')->with(Login::class)->once()->andReturnTrue();
        $events->shouldReceive('dispatch')->once()->with(m::on(
            fn (object $event): bool => $event instanceof Authenticated
                && $event->guard === 'jwt'
                && $event->user === $user
        ));
        $events->shouldReceive('dispatch')->once()->with(m::on(
            fn (object $event): bool => $event instanceof Login
                && $event->guard === 'jwt'
                && $event->user === $user
                && $event->remember === false
        ));

        $guard = $this->createGuard(jwtManager: $jwtManager);
        $guard->setDispatcher($events);

        $this->assertSame('token', $guard->login($user));
    }

    public function testLogoutEventIsDispatchedWhenListening(): void
    {
        $user = $this->user(1);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('hasBlacklistEnabled')->once()->andReturnFalse();

        $events = $this->dispatcherListeningFor(Logout::class, function (Logout $event) use ($user): bool {
            return $event->guard === 'jwt' && $event->user === $user;
        });

        $guard = $this->createGuard(jwtManager: $jwtManager, request: $this->requestWithToken('token'));
        $guard->setUser($user);
        $guard->setDispatcher($events);
        $guard->logout();
    }

    public function testAttemptingRegistersListener(): void
    {
        $listener = static fn (): null => null;

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('listen')->once()->with(Attempting::class, $listener);

        $guard = $this->createGuard();
        $guard->setDispatcher($events);
        $guard->attempting($listener);
    }

    /**
     * Create a dispatcher that listens for one event.
     */
    private function dispatcherListeningFor(string $eventClass, callable $assertion): Dispatcher
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturnUsing(
            fn (string $class): bool => $class === $eventClass
        );
        $events->shouldReceive('dispatch')->once()->with(m::on(
            fn (object $event): bool => $event instanceof $eventClass && $assertion($event)
        ));

        return $events;
    }

    /**
     * Create a JwtGuard instance.
     */
    private function createGuard(
        ?UserProvider $provider = null,
        ?ManagerContract $jwtManager = null,
        ?Request $request = null,
    ): JwtGuard {
        if ($request !== null) {
            RequestContext::set($request);
        }

        return new JwtGuard(
            'jwt',
            $provider ?? m::mock(UserProvider::class),
            $jwtManager ?? m::mock(ManagerContract::class),
            new ClaimFactory(new Repository([
                'jwt' => [
                    'issuer' => null,
                    'lock_subject' => false,
                ],
            ])),
            new Parser([new AuthHeaders, new InputSource]),
            $this->app,
        );
    }

    /**
     * Create an authenticatable user mock.
     */
    private function user(int|string $id): Authenticatable
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn($id);

        return $user;
    }

    /**
     * Create a request with a bearer token.
     */
    private function requestWithToken(string $token): Request
    {
        return Request::create('/', 'GET', server: [
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
        ]);
    }
}
