<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use ErrorException;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AuthPasswordBrokerConfigurationTest extends TestCase
{
    #[DataProvider('incompleteBrokerProvider')]
    public function testIncompleteBrokerRecordsFailAtTheMissingMember(array $broker, string $member): void
    {
        $this->app->make('config')->set('auth.passwords.users', $broker);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage("Undefined array key \"{$member}\"");

        $this->app->make('auth.password')->broker('users');
    }

    /**
     * Provide incomplete password broker records.
     */
    public static function incompleteBrokerProvider(): array
    {
        return [
            'missing common driver' => [[
                'provider' => 'users',
                'table' => 'password_reset_tokens',
                'connection' => null,
                'expire' => 60,
                'throttle' => 60,
            ], 'driver'],
            'missing nullable database connection' => [[
                'driver' => 'database',
                'provider' => 'users',
                'table' => 'password_reset_tokens',
                'expire' => 60,
                'throttle' => 60,
            ], 'connection'],
            'missing nullable cache store' => [[
                'driver' => 'cache',
                'provider' => 'users',
                'expire' => 60,
                'throttle' => 60,
            ], 'store'],
        ];
    }
}
