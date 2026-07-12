<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Passwords\PasswordBroker;
use Hypervel\Auth\Passwords\TokenRepositoryInterface;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\CanResetPassword;
use Hypervel\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Hypervel\Contracts\Auth\UserProvider;
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
        $broker->shouldReceive('getUser')->once()->andReturnNull();

        $this->assertSame(PasswordBrokerContract::INVALID_USER, $broker->sendResetLink(['credentials']));
    }

    public function testIfTokenIsRecentlyCreated(): void
    {
        $mocks = $this->getMocks();
        $broker = m::mock(PasswordBroker::class, array_values($mocks))->makePartial();
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));
        $mocks['tokens']->shouldReceive('recentlyCreatedToken')->once()->with($user)->andReturn(true);
        $user->shouldReceive('sendPasswordResetNotification')->with('token');

        $this->assertSame(PasswordBrokerContract::RESET_THROTTLED, $broker->sendResetLink(['foo']));
    }

    public function testGetUserThrowsExceptionIfUserDoesntImplementCanResetPassword(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('User must implement CanResetPassword interface.');

        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn(m::mock(Authenticatable::class));

        $broker->getUser(['foo']);
    }

    public function testUserIsRetrievedByCredentials(): void
    {
        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));

        $this->assertEquals($user, $broker->getUser(['foo']));
    }

    public function testBrokerCreatesTokenAndRedirectsWithoutError(): void
    {
        $mocks = $this->getMocks();
        $broker = m::mock(PasswordBroker::class, array_values($mocks))->makePartial();
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));
        $mocks['tokens']->shouldReceive('recentlyCreatedToken')->once()->with($user)->andReturn(false);
        $mocks['tokens']->shouldReceive('create')->once()->with($user)->andReturn('token');
        $user->shouldReceive('sendPasswordResetNotification')->with('token');

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
    }

    public function testRedirectIsReturnedByResetWhenUserCredentialsInvalid(): void
    {
        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['creds'])->andReturn(null);

        $this->assertSame(PasswordBrokerContract::INVALID_USER, $broker->reset(['creds'], function () {
        }));
    }

    public function testRedirectReturnedByRemindWhenRecordDoesntExistInTable(): void
    {
        $creds = ['token' => 'token'];
        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(Arr::except($creds, ['token']))->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));
        $mocks['tokens']->shouldReceive('exists')->with($user, 'token')->andReturn(false);

        $this->assertSame(PasswordBrokerContract::INVALID_TOKEN, $broker->reset($creds, function () {
        }));
    }

    public function testResetRemovesRecordOnReminderTableAndCallsCallback(): void
    {
        unset($_SERVER['__password.reset.test']);
        $mocks = $this->getMocks();
        $broker = m::mock(PasswordBroker::class, array_values($mocks))->makePartial()->shouldAllowMockingProtectedMethods();
        $broker->shouldReceive('validateReset')->once()->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));
        $mocks['tokens']->shouldReceive('delete')->once()->with($user);
        $callback = function ($user, $password) {
            $_SERVER['__password.reset.test'] = compact('user', 'password');

            return 'foo';
        };

        $this->assertSame(PasswordBrokerContract::PASSWORD_RESET, $broker->reset(['password' => 'password', 'token' => 'token'], $callback));
        $this->assertEquals(['user' => $user, 'password' => 'password'], $_SERVER['__password.reset.test']);
    }

    public function testExecutesCallbackInsteadOfSendingNotification(): void
    {
        $executed = false;

        $closure = function () use (&$executed) {
            $executed = true;
        };

        $mocks = $this->getMocks();
        $broker = m::mock(PasswordBroker::class, array_values($mocks))->makePartial();
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));
        $mocks['tokens']->shouldReceive('recentlyCreatedToken')->once()->with($user)->andReturn(false);
        $mocks['tokens']->shouldReceive('create')->once()->with($user)->andReturn('token');
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

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo'], function () {
            $this->assertSame('users', CoroutineContext::get(PasswordBroker::SENDING_BROKER_CONTEXT_KEY));
        }));
    }

    protected function getBroker(array $mocks): PasswordBroker
    {
        return new PasswordBroker($mocks['tokens'], $mocks['users'], $mocks['name']);
    }

    protected function getMocks(): array
    {
        return [
            'tokens' => m::mock(TokenRepositoryInterface::class),
            'users' => m::mock(UserProvider::class),
            'name' => 'users',
        ];
    }
}
