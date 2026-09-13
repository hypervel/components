<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Events\PasswordResetLinkSent;
use Hypervel\Auth\Passwords\PasswordBroker;
use Hypervel\Auth\Passwords\TokenRepositoryInterface;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\CanResetPassword;
use Hypervel\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Arr;
use Hypervel\Tests\TestCase;
use Mockery as m;
use UnexpectedValueException;

class AuthPasswordBrokerTest extends TestCase
{
    public function testIfUserIsNotFoundErrorRedirectIsReturned(): void
    {
        $mocks = $this->getMocks();
        $broker = m::mock(PasswordBroker::class, array_values($mocks))->makePartial();
        $broker->expects('getUser')->andReturnNull();

        $this->assertSame(PasswordBrokerContract::INVALID_USER, $broker->sendResetLink(['credentials']));
    }

    public function testIfTokenIsRecentlyCreated(): void
    {
        $mocks = $this->getMocks();
        $broker = m::mock(PasswordBroker::class, array_values($mocks))->makePartial();
        $user = m::mock(Authenticatable::class, CanResetPassword::class);
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->andReturn($user);
        $mocks['tokens']->expects('recentlyCreatedToken')->with($user)->andReturn(true);

        $this->assertSame(PasswordBrokerContract::RESET_THROTTLED, $broker->sendResetLink(['foo']));
    }

    public function testGetUserThrowsExceptionIfUserDoesntImplementCanResetPassword(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('User must implement CanResetPassword interface.');

        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->andReturn(m::mock(Authenticatable::class));

        $broker->getUser(['foo']);
    }

    public function testUserIsRetrievedByCredentials(): void
    {
        $broker = $this->getBroker($mocks = $this->getMocks());
        $user = m::mock(Authenticatable::class, CanResetPassword::class);
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->andReturn($user);

        $this->assertEquals($user, $broker->getUser(['foo']));
    }

    public function testBrokerCreatesTokenAndRedirectsWithoutError(): void
    {
        $mocks = $this->getMocks();
        $broker = m::mock(PasswordBroker::class, array_values($mocks))->makePartial();
        $user = m::mock(Authenticatable::class, CanResetPassword::class);
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->andReturn($user);
        $mocks['tokens']->expects('recentlyCreatedToken')->with($user)->andReturn(false);
        $mocks['tokens']->expects('create')->with($user)->andReturn('token');
        $user->expects('sendPasswordResetNotification')->with('token');

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
    }

    public function testResetLinkEventIsSkippedWhenThereAreNoListeners(): void
    {
        $mocks = $this->getMocks();
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(PasswordResetLinkSent::class)->andReturnFalse();
        $events->shouldNotReceive('dispatch');
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));
        $mocks['tokens']->shouldReceive('recentlyCreatedToken')->once()->with($user)->andReturnFalse();
        $mocks['tokens']->shouldReceive('create')->once()->with($user)->andReturn('token');
        $user->shouldReceive('sendPasswordResetNotification')->once()->with('token');
        $broker = new PasswordBroker($mocks['tokens'], $mocks['users'], $mocks['name'], $events);

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
    }

    public function testResetLinkEventIsDispatchedWhenThereIsAListener(): void
    {
        $mocks = $this->getMocks();
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(PasswordResetLinkSent::class)->andReturnTrue();
        $events->shouldReceive('dispatch')
            ->once()
            ->with(m::type(PasswordResetLinkSent::class));
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));
        $mocks['tokens']->shouldReceive('recentlyCreatedToken')->once()->with($user)->andReturnFalse();
        $mocks['tokens']->shouldReceive('create')->once()->with($user)->andReturn('token');
        $user->shouldReceive('sendPasswordResetNotification')->once()->with('token');
        $broker = new PasswordBroker($mocks['tokens'], $mocks['users'], $mocks['name'], $events);

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
    }

    public function testEventDispatcherCanBeReplacedOnAnExistingBroker(): void
    {
        $mocks = $this->getMocks();
        $originalEvents = m::mock(Dispatcher::class);
        $originalEvents->shouldReceive('hasListeners', 'dispatch')->never();
        $replacementEvents = m::mock(Dispatcher::class);
        $replacementEvents->shouldReceive('hasListeners')->once()->with(PasswordResetLinkSent::class)->andReturnTrue();
        $replacementEvents->shouldReceive('dispatch')
            ->once()
            ->with(m::type(PasswordResetLinkSent::class));
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));
        $mocks['tokens']->shouldReceive('recentlyCreatedToken')->once()->with($user)->andReturnFalse();
        $mocks['tokens']->shouldReceive('create')->once()->with($user)->andReturn('token');
        $user->shouldReceive('sendPasswordResetNotification')->once()->with('token');
        $broker = new PasswordBroker($mocks['tokens'], $mocks['users'], $mocks['name'], $originalEvents);

        $broker->setDispatcher($replacementEvents);

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
    }

    public function testRedirectIsReturnedByResetWhenUserCredentialsInvalid(): void
    {
        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->expects('retrieveByCredentials')->with(['creds'])->andReturn(null);

        $this->assertSame(PasswordBrokerContract::INVALID_USER, $broker->reset(['creds'], function (): void {
        }));
    }

    public function testRedirectReturnedByRemindWhenRecordDoesntExistInTable(): void
    {
        $credentials = ['token' => 'token'];
        $broker = $this->getBroker($mocks = $this->getMocks());
        $user = m::mock(Authenticatable::class, CanResetPassword::class);
        $mocks['users']->expects('retrieveByCredentials')->with(Arr::except($credentials, ['token']))->andReturn($user);
        $mocks['tokens']->expects('exists')->with($user, 'token')->andReturn(false);

        $this->assertSame(PasswordBrokerContract::INVALID_TOKEN, $broker->reset($credentials, function (): void {
        }));
    }

    public function testResetRemovesRecordOnReminderTableAndCallsCallback(): void
    {
        $resetArguments = null;
        $mocks = $this->getMocks();
        $broker = m::mock(PasswordBroker::class, array_values($mocks))->makePartial()->shouldAllowMockingProtectedMethods();
        $user = m::mock(Authenticatable::class, CanResetPassword::class);
        $broker->expects('validateReset')->andReturn($user);
        $mocks['tokens']->expects('delete')->with($user);
        $callback = function (CanResetPassword $user, string $password) use (&$resetArguments): string {
            $resetArguments = ['user' => $user, 'password' => $password];

            return 'foo';
        };

        $this->assertSame(PasswordBrokerContract::PASSWORD_RESET, $broker->reset(['password' => 'password', 'token' => 'token'], $callback));
        $this->assertEquals(['user' => $user, 'password' => 'password'], $resetArguments);
    }

    public function testExecutesCallbackInsteadOfSendingNotification(): void
    {
        $executed = false;

        $closure = function () use (&$executed): void {
            $executed = true;
        };

        $mocks = $this->getMocks();
        $broker = m::mock(PasswordBroker::class, array_values($mocks))->makePartial();
        $user = m::mock(Authenticatable::class, CanResetPassword::class);
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->andReturn($user);
        $mocks['tokens']->expects('recentlyCreatedToken')->with($user)->andReturn(false);
        $mocks['tokens']->expects('create')->with($user)->andReturn('token');
        $user->shouldNotReceive('sendPasswordResetNotification');

        $this->assertEquals(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo'], $closure));

        $this->assertTrue($executed);
    }

    public function testSendResetLinkStampsSendingBrokerContext(): void
    {
        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));
        $mocks['tokens']->shouldReceive('recentlyCreatedToken')->once()->with($user)->andReturn(false);
        $mocks['tokens']->shouldReceive('create')->once()->with($user)->andReturn('token');
        $user->shouldReceive('sendPasswordResetNotification')
            ->once()
            ->with('token')
            ->andReturnUsing(function (): void {
                $this->assertSame('users', CoroutineContext::get(PasswordBroker::SENDING_BROKER_CONTEXT_KEY));
            });

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
    }

    public function testSendResetLinkRestoresPreviousSendingBrokerContext(): void
    {
        CoroutineContext::set(PasswordBroker::SENDING_BROKER_CONTEXT_KEY, 'outer');

        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));
        $mocks['tokens']->shouldReceive('recentlyCreatedToken')->once()->with($user)->andReturn(false);
        $mocks['tokens']->shouldReceive('create')->once()->with($user)->andReturn('token');
        $user->shouldReceive('sendPasswordResetNotification')->once()->with('token');

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
        $this->assertSame('outer', CoroutineContext::get(PasswordBroker::SENDING_BROKER_CONTEXT_KEY));
    }

    public function testSendResetLinkForgetsSendingBrokerContextWhenNoneExisted(): void
    {
        CoroutineContext::forget(PasswordBroker::SENDING_BROKER_CONTEXT_KEY);

        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));
        $mocks['tokens']->shouldReceive('recentlyCreatedToken')->once()->with($user)->andReturn(false);
        $mocks['tokens']->shouldReceive('create')->once()->with($user)->andReturn('token');
        $user->shouldReceive('sendPasswordResetNotification')->once()->with('token');

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
        $this->assertFalse(CoroutineContext::has(PasswordBroker::SENDING_BROKER_CONTEXT_KEY));
    }

    public function testSendResetLinkStampsContextForCallback(): void
    {
        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));
        $mocks['tokens']->shouldReceive('recentlyCreatedToken')->once()->with($user)->andReturn(false);
        $mocks['tokens']->shouldReceive('create')->once()->with($user)->andReturn('token');
        $user->shouldNotReceive('sendPasswordResetNotification');

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo'], function (): void {
            $this->assertSame('users', CoroutineContext::get(PasswordBroker::SENDING_BROKER_CONTEXT_KEY));
        }));
    }

    /**
     * Create a broker with the given dependencies.
     */
    protected function getBroker(array $mocks): PasswordBroker
    {
        return new PasswordBroker($mocks['tokens'], $mocks['users'], $mocks['name']);
    }

    /**
     * Create the broker's dependencies.
     */
    protected function getMocks(): array
    {
        return [
            'tokens' => m::mock(TokenRepositoryInterface::class),
            'users' => m::mock(UserProvider::class),
            'name' => 'users',
        ];
    }
}
