<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Passwords\PasswordBrokerManager;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class AuthPasswordBrokerManagerTest extends TestCase
{
    public function testBrokerFailsFastWhenAppKeyIsNotConfigured(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration value for key [app.key] must be a string, NULL given.');

        $container = new Container;
        $container->instance('config', new Repository([
            'app' => [
                'key' => null,
            ],
            'auth' => [
                'passwords' => [
                    'users' => [
                        'provider' => 'users',
                        'table' => 'password_reset_tokens',
                    ],
                ],
            ],
        ]));

        (new PasswordBrokerManager($container))->broker('users');
    }
}
