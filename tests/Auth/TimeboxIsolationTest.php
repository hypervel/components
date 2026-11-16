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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;

class TimeboxIsolationTest extends TestCase
{
    use VerifiesDoubles;

    #[DataProvider('guardOperationProvider')]
    public function testGuardOperationsUseIndependentTimeboxes(string $operation): void
    {
        $recorder = new TimeboxOperationRecorder;
        $timebox = new TrackingTimebox($recorder);
        $provider = Double::for(UserProvider::class);
        $user = Double::for(Authenticatable::class);

        $provider->expects('retrieveByCredentials')->times(2)->returns($user, null);
        $provider->expects('validateCredentials')->with(Argument::same($user), ['email' => 'test@example.com'])->returns(true);

        if ($operation !== 'validate') {
            $provider->expects('rehashPasswordIfRequired')->with(Argument::same($user), ['email' => 'test@example.com']);
        }

        $guard = new TrackingSessionGuard(
            'web',
            $provider,
            Double::for(Session::class),
            Double::for(Container::class, override: true)->instance(),
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
        $users = Double::for(UserProvider::class);
        $users->expects('retrieveByCredentials')->times(2)->with(['email' => 'missing@example.com'])->returns(null);

        $broker = new PasswordBroker(
            Double::for(TokenRepositoryInterface::class),
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
        $tokens = Double::for(TokenRepositoryInterface::class);
        $users = Double::for(UserProvider::class);
        $user = Double::for(Authenticatable::class, CanResetPassword::class);
        $credentials = [
            'email' => 'test@example.com',
            'password' => 'secret',
            'token' => 'token',
        ];

        $users->expects('retrieveByCredentials')
            ->times(2)
            ->with(['email' => 'test@example.com', 'password' => 'secret'])
            ->returns($user, null);
        $tokens->expects('exists')->with(Argument::same($user), 'token')->returns(true);
        $tokens->expects('delete')->with(Argument::same($user));

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
