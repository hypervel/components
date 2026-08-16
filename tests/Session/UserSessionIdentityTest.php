<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Auth\AuthManager;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Session\UserSessionIdentity;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;

use function Hypervel\Coroutine\parallel;

class UserSessionIdentityTest extends TestCase
{
    public function testItResolvesTheSelectedGuardIdentity(): void
    {
        $guard = m::mock(Guard::class);
        $guard->shouldReceive('id')->once()->andReturn(42);

        $container = $this->containerWithGuard($guard, 'users');

        $identity = UserSessionIdentity::resolve($container, str_repeat('a', 40));

        $this->assertTrue($identity->isResolved());
        $this->assertFalse($identity->isUnowned());
        $this->assertSame('users', $identity->authProvider);
        $this->assertSame('42', $identity->userId);
    }

    public function testItReturnsAnUnresolvedIdentityWithoutASelectedUser(): void
    {
        $guard = m::mock(Guard::class);
        $guard->shouldReceive('id')->once()->andReturnNull();

        $container = $this->containerWithGuard($guard, 'users');

        $withoutContainer = UserSessionIdentity::resolve(null, str_repeat('a', 40));
        $withoutUser = UserSessionIdentity::resolve($container, str_repeat('b', 40));

        $this->assertFalse($withoutContainer->isResolved());
        $this->assertFalse($withoutContainer->isUnowned());
        $this->assertNull($withoutContainer->authProvider);
        $this->assertNull($withoutContainer->userId);
        $this->assertFalse($withoutUser->isResolved());
        $this->assertFalse($withoutUser->isUnowned());
        $this->assertNull($withoutUser->authProvider);
        $this->assertNull($withoutUser->userId);
    }

    public function testAuthenticatedProviderlessGuardIsExplicitlyUnowned(): void
    {
        $guard = m::mock(Guard::class);
        $guard->shouldReceive('id')->once()->andReturn('custom-user');

        $identity = UserSessionIdentity::resolve(
            $this->containerWithGuard($guard),
            str_repeat('a', 40),
        );

        $this->assertFalse($identity->isResolved());
        $this->assertTrue($identity->isUnowned());
        $this->assertNull($identity->authProvider);
        $this->assertNull($identity->userId);
    }

    public function testItNormalizesIntegerAndStringIdentifiers(): void
    {
        $this->assertSame('42', UserSessionIdentity::normalize(42));
        $this->assertSame('42', UserSessionIdentity::normalize('42'));
        $this->assertSame('0', UserSessionIdentity::normalize(0));
        $this->assertSame('0', UserSessionIdentity::normalize('0'));
        $this->assertSame('01HV4ZQ3R4N56M7P8Q9S0T1U2V', UserSessionIdentity::normalize('01HV4ZQ3R4N56M7P8Q9S0T1U2V'));
    }

    public function testItRejectsAnEmptyIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The user identifier may not be empty.');

        UserSessionIdentity::normalize('');
    }

    public function testSuppressionSurvivesRepeatedResolution(): void
    {
        $sessionId = str_repeat('a', 40);

        UserSessionIdentity::suppress($sessionId);

        $this->assertTrue(UserSessionIdentity::resolve(null, $sessionId)->isUnowned());
        $this->assertTrue(UserSessionIdentity::resolve(null, $sessionId)->isUnowned());
    }

    public function testSuppressionShortCircuitsGuardResolution(): void
    {
        $sessionId = str_repeat('a', 40);
        $container = m::mock(Container::class);
        $container->shouldReceive('has')->never();
        $container->shouldReceive('make')->never();

        UserSessionIdentity::suppress($sessionId);

        $identity = UserSessionIdentity::resolve($container, $sessionId);

        $this->assertFalse($identity->isResolved());
        $this->assertTrue($identity->isUnowned());
        $this->assertNull($identity->authProvider);
        $this->assertNull($identity->userId);
    }

    public function testSuppressionIsScopedOnlyToTheSessionIdentifier(): void
    {
        $markedSessionId = str_repeat('a', 40);
        $otherSessionId = str_repeat('b', 40);

        $guard = m::mock(Guard::class);
        $guard->shouldReceive('id')->once()->andReturn('user-1');

        $container = $this->containerWithGuard($guard, 'users');

        UserSessionIdentity::suppress($markedSessionId);

        $identity = UserSessionIdentity::resolve($container, $otherSessionId);

        $this->assertTrue($identity->isResolved());
        $this->assertFalse($identity->isUnowned());
        $this->assertSame('users', $identity->authProvider);
        $this->assertSame('user-1', $identity->userId);
    }

    public function testSuppressionDoesNotLeakBetweenConcurrentCoroutines(): void
    {
        $sessionId = str_repeat('a', 40);

        [$unowned, $unresolved] = parallel([
            function () use ($sessionId): bool {
                UserSessionIdentity::suppress($sessionId);
                usleep(5000);

                return UserSessionIdentity::resolve(null, $sessionId)->isUnowned();
            },
            function () use ($sessionId): bool {
                usleep(1000);

                return UserSessionIdentity::resolve(null, $sessionId)->isUnowned();
            },
        ]);

        $this->assertTrue($unowned);
        $this->assertFalse($unresolved);
    }

    private function containerWithGuard(Guard $guard, ?string $provider = null): Container
    {
        $guardConfig = ['driver' => 'custom'];

        if ($provider !== null) {
            $guardConfig['provider'] = $provider;
        }

        $container = new Container;
        $container->instance('config', new Repository([
            'auth' => [
                'defaults' => ['guard' => 'web'],
                'guards' => ['web' => $guardConfig],
            ],
        ]));
        $auth = new AuthManager($container);
        $auth->extend('custom', fn () => $guard);
        $container->instance('auth', $auth);

        return $container;
    }
}
