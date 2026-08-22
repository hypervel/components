<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use ErrorException;
use Hypervel\Testbench\TestCase;

class AuthPasswordBrokerConfigurationTest extends TestCase
{
    public function testBrokerRecordRequiresDriver(): void
    {
        $this->app->make('config')->set('auth.passwords.users', [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ]);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Undefined array key "driver"');

        $this->app->make('auth.password')->broker('users');
    }
}
