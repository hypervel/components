<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Events\Registered;
use Hypervel\Auth\Listeners\SendEmailVerificationNotification;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Foundation\Auth\User;
use Hypervel\Tests\TestCase;
use Mockery as m;

class AuthListenersSendEmailVerificationNotificationHandleFunctionTest extends TestCase
{
    public function testWillExecuted()
    {
        $user = m::mock(Authenticatable::class, MustVerifyEmail::class);
        $user->shouldReceive('hasVerifiedEmail')->andReturn(false);
        $user->shouldReceive('sendEmailVerificationNotification')->once();

        $listener = new SendEmailVerificationNotification;

        $listener->handle(new Registered($user));
    }

    public function testUserIsNotInstanceOfMustVerifyEmail()
    {
        $user = m::mock(User::class);
        $user->shouldNotReceive('sendEmailVerificationNotification');

        $listener = new SendEmailVerificationNotification;

        $listener->handle(new Registered($user));
    }

    public function testHasVerifiedEmailAsTrue()
    {
        $user = m::mock(Authenticatable::class, MustVerifyEmail::class);
        $user->shouldReceive('hasVerifiedEmail')->andReturn(true);
        $user->shouldNotReceive('sendEmailVerificationNotification');

        $listener = new SendEmailVerificationNotification;

        $listener->handle(new Registered($user));
    }
}
