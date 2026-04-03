<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Passwords\PasswordBroker;
use Hypervel\Auth\Passwords\TokenRepositoryInterface;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\CanResetPassword;
use Hypervel\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Support\Arr;
use Hypervel\Tests\TestCase;
use Mockery as m;
use UnexpectedValueException;

/**
 * @internal
 * @coversNothing
 */
class AuthPasswordBrokerTest extends TestCase
{
    public function testIfUserIsNotFoundErrorRedirectIsReturned()
    {
        $mocks = $this->getMocks();
        $broker = m::mock(PasswordBroker::class, array_values($mocks))->makePartial();
        $broker->shouldReceive('getUser')->once()->andReturnNull();

        $this->assertSame(PasswordBrokerContract::INVALID_USER, $broker->sendResetLink(['credentials']));
    }

    public function testIfTokenIsRecentlyCreated()
    {
        $mocks = $this->getMocks();
        $broker = m::mock(PasswordBroker::class, array_values($mocks))->makePartial();
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));
        $mocks['tokens']->shouldReceive('recentlyCreatedToken')->once()->with($user)->andReturn(true);
        $user->shouldReceive('sendPasswordResetNotification')->with('token');

        $this->assertSame(PasswordBrokerContract::RESET_THROTTLED, $broker->sendResetLink(['foo']));
    }

    public function testGetUserThrowsExceptionIfUserDoesntImplementCanResetPassword()
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('User must implement CanResetPassword interface.');

        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn(m::mock(Authenticatable::class));

        $broker->getUser(['foo']);
    }

    public function testUserIsRetrievedByCredentials()
    {
        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));

        $this->assertEquals($user, $broker->getUser(['foo']));
    }

    public function testBrokerCreatesTokenAndRedirectsWithoutError()
    {
        $mocks = $this->getMocks();
        $broker = m::mock(PasswordBroker::class, array_values($mocks))->makePartial();
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));
        $mocks['tokens']->shouldReceive('recentlyCreatedToken')->once()->with($user)->andReturn(false);
        $mocks['tokens']->shouldReceive('create')->once()->with($user)->andReturn('token');
        $user->shouldReceive('sendPasswordResetNotification')->with('token');

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
    }

    public function testRedirectIsReturnedByResetWhenUserCredentialsInvalid()
    {
        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['creds'])->andReturn(null);

        $this->assertSame(PasswordBrokerContract::INVALID_USER, $broker->reset(['creds'], function () {
        }));
    }

    public function testRedirectReturnedByRemindWhenRecordDoesntExistInTable()
    {
        $creds = ['token' => 'token'];
        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(Arr::except($creds, ['token']))->andReturn($user = m::mock(Authenticatable::class . ',' . CanResetPassword::class));
        $mocks['tokens']->shouldReceive('exists')->with($user, 'token')->andReturn(false);

        $this->assertSame(PasswordBrokerContract::INVALID_TOKEN, $broker->reset($creds, function () {
        }));
    }

    public function testResetRemovesRecordOnReminderTableAndCallsCallback()
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

    public function testExecutesCallbackInsteadOfSendingNotification()
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
        $user->shouldReceive('sendPasswordResetNotification')->with('token');

        $this->assertEquals(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo'], $closure));

        $this->assertTrue($executed);
    }

    protected function getBroker($mocks)
    {
        return new PasswordBroker($mocks['tokens'], $mocks['users']);
    }

    protected function getMocks()
    {
        return [
            'tokens' => m::mock(TokenRepositoryInterface::class),
            'users' => m::mock(UserProvider::class),
        ];
    }
}
