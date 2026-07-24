<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Passwords\PasswordBroker;
use Hypervel\Auth\Passwords\TokenRepositoryInterface;
use Hypervel\Auth\SessionGuard;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\CanResetPassword;
use Hypervel\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Session\Session;
use Hypervel\Support\Timebox;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class TimeboxIsolationTest extends TestCase
{
    #[DataProvider('guardOperationProvider')]
    public function testGuardOperationsUseIndependentTimeboxes(string $operation): void
    {
        $recorder = new TimeboxOperationRecorder;
        $timebox = new TrackingTimebox($recorder);
        $provider = m::mock(UserProvider::class);
        $user = m::mock(Authenticatable::class);

        $provider->shouldReceive('retrieveByCredentials')->twice()->andReturn($user, null);
        $provider->shouldReceive('validateCredentials')->once()->with($user, ['email' => 'test@example.com'])->andReturn(true);

        if ($operation !== 'validate') {
            $provider->shouldReceive('rehashPasswordIfRequired')->once()->with($user, ['email' => 'test@example.com']);
        }

        $guard = new TrackingSessionGuard(
            'web',
            $provider,
            m::mock(Session::class),
            m::mock(Container::class),
            $timebox,
            timeboxDuration: 1_000_000,
        );

        $this->assertTrue($guard->{$operation}(['email' => 'test@example.com']));
        $this->assertFalse($guard->{$operation}(['email' => 'test@example.com']));
        $this->assertSame([0, 0], $recorder->startingOperationCounts);
        $this->assertSame(1, $recorder->sleeps);
        $this->assertSame(0, $timebox->operationCount);
        $this->assertFalse($timebox->earlyReturn);
    }

    public static function guardOperationProvider(): array
    {
        return [
            ['validate'],
            ['attempt'],
            ['attemptWhen'],
        ];
    }

    public function testSendingResetLinksUsesIndependentTimeboxes(): void
    {
        $recorder = new TimeboxOperationRecorder;
        $timebox = new TrackingTimebox($recorder);
        $users = m::mock(UserProvider::class);
        $users->shouldReceive('retrieveByCredentials')->twice()->with(['email' => 'missing@example.com'])->andReturnNull();

        $broker = new PasswordBroker(
            m::mock(TokenRepositoryInterface::class),
            $users,
            'users',
            timebox: $timebox,
            timeboxDuration: 1_000_000,
        );

        $this->assertSame(PasswordBrokerContract::INVALID_USER, $broker->sendResetLink(['email' => 'missing@example.com']));
        $this->assertSame(PasswordBrokerContract::INVALID_USER, $broker->sendResetLink(['email' => 'missing@example.com']));
        $this->assertSame([0, 0], $recorder->startingOperationCounts);
        $this->assertSame(2, $recorder->sleeps);
        $this->assertSame(0, $timebox->operationCount);
    }

    public function testPasswordResetsUseIndependentTimeboxes(): void
    {
        $recorder = new TimeboxOperationRecorder;
        $timebox = new TrackingTimebox($recorder);
        $tokens = m::mock(TokenRepositoryInterface::class);
        $users = m::mock(UserProvider::class);
        $user = m::mock(Authenticatable::class . ',' . CanResetPassword::class);
        $credentials = [
            'email' => 'test@example.com',
            'password' => 'secret',
            'token' => 'token',
        ];

        $users->shouldReceive('retrieveByCredentials')
            ->twice()
            ->with(['email' => 'test@example.com', 'password' => 'secret'])
            ->andReturn($user, null);
        $tokens->shouldReceive('exists')->once()->with($user, 'token')->andReturnTrue();
        $tokens->shouldReceive('delete')->once()->with($user);

        $broker = new PasswordBroker(
            $tokens,
            $users,
            'users',
            timebox: $timebox,
            timeboxDuration: 1_000_000,
        );

        $this->assertSame(PasswordBrokerContract::PASSWORD_RESET, $broker->reset($credentials, static function (): void {
        }));
        $this->assertSame(PasswordBrokerContract::INVALID_USER, $broker->reset($credentials, static function (): void {
        }));
        $this->assertSame([0, 0], $recorder->startingOperationCounts);
        $this->assertSame(1, $recorder->sleeps);
        $this->assertSame(0, $timebox->operationCount);
        $this->assertFalse($timebox->earlyReturn);
    }
}

class TrackingSessionGuard extends SessionGuard
{
    public function login(Authenticatable $user, bool $remember = false): void
    {
    }
}

class TrackingTimebox extends Timebox
{
    public int $operationCount = 0;

    public function __construct(private TimeboxOperationRecorder $recorder)
    {
    }

    public function call(callable $callback, int $microseconds): mixed
    {
        $this->recorder->startingOperationCounts[] = $this->operationCount++;

        return parent::call($callback, $microseconds);
    }

    protected function usleep(int $microseconds): void
    {
        ++$this->recorder->sleeps;
    }
}

class TimeboxOperationRecorder
{
    public array $startingOperationCounts = [];

    public int $sleeps = 0;
}
