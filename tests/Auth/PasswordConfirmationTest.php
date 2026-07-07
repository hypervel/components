<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\PasswordConfirmation;
use Hypervel\Config\Repository;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class PasswordConfirmationTest extends TestCase
{
    public function testSessionKeyIsGuardSuffixed(): void
    {
        $this->assertSame('auth.password_confirmed_at_admin', PasswordConfirmation::sessionKey('admin'));
    }

    public function testTimeoutUsesGuardDeclaration(): void
    {
        $config = new Repository([
            'auth' => [
                'guards' => [
                    'admin' => [
                        'password_timeout' => 900,
                    ],
                ],
            ],
        ]);

        $this->assertSame(900, PasswordConfirmation::timeout($config, 'admin'));
    }

    public function testTimeoutFallsBackToGlobal(): void
    {
        $config = new Repository([
            'auth' => [
                'password_timeout' => 3600,
            ],
        ]);

        $this->assertSame(3600, PasswordConfirmation::timeout($config, 'admin'));
    }

    public function testTimeoutDefaultsWhenUnconfigured(): void
    {
        $this->assertSame(10800, PasswordConfirmation::timeout(new Repository, 'admin'));
    }

    public function testTimeoutFailsFastOnMalformedGuardValue(): void
    {
        $config = new Repository([
            'auth' => [
                'guards' => [
                    'admin' => [
                        'password_timeout' => 'abc',
                    ],
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration value for key [auth.guards.admin.password_timeout] must be an integer, string given.');

        PasswordConfirmation::timeout($config, 'admin');
    }

    public function testGuardDeclarationWinsWhenGlobalIsMalformed(): void
    {
        $config = new Repository([
            'auth' => [
                'password_timeout' => 'abc',
                'guards' => [
                    'admin' => [
                        'password_timeout' => 900,
                    ],
                ],
            ],
        ]);

        $this->assertSame(900, PasswordConfirmation::timeout($config, 'admin'));
    }

    public function testExplicitOverrideWinsOverAllTiers(): void
    {
        $config = new Repository([
            'auth' => [
                'password_timeout' => 3600,
                'guards' => [
                    'admin' => [
                        'password_timeout' => 900,
                    ],
                ],
            ],
        ]);

        $this->assertSame(300, PasswordConfirmation::timeout($config, 'admin', '300'));
    }
}
