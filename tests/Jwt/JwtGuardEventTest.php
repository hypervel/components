<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt;

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
use Hypervel\Jwt\ClaimFactory;
use Hypervel\Jwt\Contracts\ManagerContract;
use Hypervel\Jwt\Exceptions\JwtException;
use Hypervel\Jwt\Exceptions\TokenExpiredException;
use Hypervel\Jwt\Http\Parser\AuthHeaders;
use Hypervel\Jwt\Http\Parser\InputSource;
use Hypervel\Jwt\Http\Parser\Parser;
use Hypervel\Jwt\JwtGuard;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;

class JwtGuardEventTest extends TestCase
{
    use VerifiesDoubles;

    public function testDoesNotDispatchEventsWhenNoListenersExist(): void
    {
        $user = $this->user(1);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->returns('token');

        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(false);
        $events->expects('dispatch')->never();

        $guard = $this->createGuard(jwtManager: $jwtManager);
        $guard->setDispatcher($events);
        $guard->login($user);
    }

    public function testAttemptingEventIsDispatchedWhenListening(): void
    {
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveByCredentials')->returns(null);

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

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveByCredentials')->returns($user);
        $provider->expects('validateCredentials')->returns(true);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->returns('token');

        $events = $this->dispatcherListeningFor(Validated::class, function (Validated $event) use ($user): bool {
            return $event->guard === 'jwt' && $event->user === $user;
        });

        $guard = $this->createGuard(provider: $provider, jwtManager: $jwtManager);
        $guard->setDispatcher($events);

        $this->assertSame('token', $guard->attempt(['email' => 'foo@example.test']));
    }

    public function testFailedEventIsDispatchedWhenListening(): void
    {
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveByCredentials')->returns(null);

        $events = $this->dispatcherListeningFor(Failed::class, function (Failed $event): bool {
            return $event->guard === 'jwt'
                && $event->user === null
                && $event->credentials === ['email' => 'foo@example.test'];
        });

        $guard = $this->createGuard(provider: $provider);
        $guard->setDispatcher($events);

        $this->assertFalse($guard->attempt(['email' => 'foo@example.test']));
    }

    #[DataProvider('credentialCheckEventProvider')]
    public function testCredentialChecksWithoutLoginDoNotDispatchTheLoginEvent(string $method, array $expectedEvents): void
    {
        $user = $this->user(1);

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveByCredentials')->returns($user);
        $provider->expects('validateCredentials')->returns(true);

        $dispatched = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->allows('dispatch')->resolves(function (object $event) use (&$dispatched): void {
            $dispatched[] = $event::class;
        });

        $guard = $this->createGuard(provider: $provider);
        $guard->setDispatcher($events);

        $this->assertTrue($guard->{$method}(['email' => 'foo@example.test']));
        $this->assertSame($expectedEvents, $dispatched);
    }

    /**
     * Provide credential checks that do not log in and the events each dispatches.
     *
     * @return array<string, array{string, array<int, class-string>}>
     */
    public static function credentialCheckEventProvider(): array
    {
        return [
            'validate' => ['validate', [Attempting::class, Validated::class]],
            'once' => ['once', [Attempting::class, Validated::class, Authenticated::class]],
        ];
    }

    public function testLoginAndAuthenticatedEventsAreDispatchedWhenListening(): void
    {
        $user = $this->user(1);
        $dispatched = [];

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->returns('token');

        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(Login::class)->returns(true);
        $events->expects('hasListeners')->with(Authenticated::class)->returns(true);
        $events->expects('dispatch')->times(2)->resolves(
            function (object $event) use (&$dispatched): void {
                $dispatched[] = $event;
            },
        );

        $guard = $this->createGuard(jwtManager: $jwtManager);
        $guard->setDispatcher($events);

        $this->assertSame('token', $guard->login($user));
        $this->assertCount(2, $dispatched);
        $this->assertInstanceOf(Login::class, $dispatched[0]);
        $this->assertSame('jwt', $dispatched[0]->guard);
        $this->assertSame($user, $dispatched[0]->user);
        $this->assertFalse($dispatched[0]->remember);
        $this->assertInstanceOf(Authenticated::class, $dispatched[1]);
        $this->assertSame('jwt', $dispatched[1]->guard);
        $this->assertSame($user, $dispatched[1]->user);
    }

    public function testAuthenticatedEventIsDispatchedOnceWhenTheTokenUserResolves(): void
    {
        $user = $this->user(1);

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('token')->returns(['sub' => 1]);

        $events = $this->dispatcherListeningFor(Authenticated::class, function (Authenticated $event) use ($user): bool {
            return $event->guard === 'jwt' && $event->user === $user;
        });

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->requestWithToken('token'),
        );
        $guard->setDispatcher($events);

        $this->assertSame($user, $guard->user());
        $this->assertSame($user, $guard->user());
    }

    public function testNoEventIsDispatchedWhenTheTokenUserIsMissing(): void
    {
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->returns(null);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('token')->returns(['sub' => 1]);

        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->never();
        $events->expects('dispatch')->never();

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->requestWithToken('token'),
        );
        $guard->setDispatcher($events);

        $this->assertNull($guard->user());
    }

    public function testOnceUsingIdDispatchesTheAuthenticatedEvent(): void
    {
        $user = $this->user(1);

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->returns($user);

        $events = $this->dispatcherListeningFor(Authenticated::class, function (Authenticated $event) use ($user): bool {
            return $event->guard === 'jwt' && $event->user === $user;
        });

        $guard = $this->createGuard(provider: $provider);
        $guard->setDispatcher($events);

        $this->assertSame($user, $guard->onceUsingId(1));
    }

    public function testIssuingTokensWithoutLoginDispatchesNoEvents(): void
    {
        $user = $this->user(1);

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->times(2)->returns('token');

        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->never();
        $events->expects('dispatch')->never();

        $guard = $this->createGuard(provider: $provider, jwtManager: $jwtManager);
        $guard->setDispatcher($events);

        $this->assertSame('token', $guard->fromUser($user));
        $this->assertSame('token', $guard->tokenById(1));
    }

    public function testLogoutEventIncludesTheTokenUserWhenListening(): void
    {
        $user = $this->user(1);

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('token')->returns(['sub' => 1]);
        $jwtManager->expects('hasBlacklistEnabled')->returns(true);
        $jwtManager->expects('invalidate')->with('token', false)->returns(true);

        $events = $this->dispatcherListeningFor(Logout::class, function (Logout $event) use ($user): bool {
            return $event->guard === 'jwt' && $event->user === $user;
        });

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->requestWithToken('token'),
        );
        $guard->setDispatcher($events);
        $guard->logout();
    }

    public function testLogoutWithAnExpiredTokenInvalidatesItAndDispatchesLogoutWithoutAUser(): void
    {
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->never();

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')
            ->with('token')
            ->throws(new TokenExpiredException('Token has expired'));
        $jwtManager->expects('hasBlacklistEnabled')->returns(true);
        $jwtManager->expects('invalidate')->with('token', false)->returns(true);

        $events = $this->dispatcherListeningFor(Logout::class, function (Logout $event): bool {
            return $event->guard === 'jwt' && $event->user === null;
        });

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->requestWithToken('token'),
        );
        $guard->setDispatcher($events);
        $guard->logout();

        $this->assertNull($guard->getToken());
        $this->assertNull($guard->user());
    }

    public function testLogoutEventIsNotDispatchedWhenInvalidationFails(): void
    {
        $user = $this->user(1);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('hasBlacklistEnabled')->returns(true);
        $jwtManager->expects('invalidate')
            ->with('token', false)
            ->throws(new JwtException('blacklist write failed'));

        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->never();
        $events->expects('dispatch')->never();

        $guard = $this->createGuard(jwtManager: $jwtManager, request: $this->requestWithToken('token'));
        $guard->setUser($user);
        $guard->setDispatcher($events);

        try {
            $guard->logout();

            $this->fail('Expected logout to fail when token invalidation fails.');
        } catch (JwtException $exception) {
            $this->assertSame('blacklist write failed', $exception->getMessage());
        }
    }

    public function testAttemptingRegistersListener(): void
    {
        $listener = static fn (): null => null;

        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('listen')->with(Attempting::class, $listener);

        $guard = $this->createGuard();
        $guard->setDispatcher($events);
        $guard->attempting($listener);
    }

    /**
     * Create a dispatcher that listens for one event.
     */
    private function dispatcherListeningFor(string $eventClass, callable $assertion): Dispatcher
    {
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->resolves(
            fn (string $class): bool => $class === $eventClass
        );
        $events->expects('dispatch')->with(Argument::satisfies(
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
            name: 'jwt',
            provider: $provider ?? Double::for(UserProvider::class)->strict(),
            jwtManager: $jwtManager ?? Double::for(ManagerContract::class)->strict(),
            claimFactory: new ClaimFactory(new Repository([
                'jwt' => [
                    'issuer' => null,
                    'lock_subject' => false,
                ],
            ])),
            parser: new Parser([new AuthHeaders, new InputSource]),
            app: $this->app,
            rehashOnLogin: false,
            timeboxDuration: 0,
        );
    }

    /**
     * Create an authenticatable user mock.
     */
    private function user(int|string $id): Authenticatable
    {
        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns($id);

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
