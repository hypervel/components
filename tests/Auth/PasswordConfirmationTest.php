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

    public function testExplicitNullTimeoutInheritsGlobal(): void
    {
        $config = new Repository([
            'auth' => [
                'password_timeout' => 3600,
                'guards' => [
                    'admin' => [
                        'password_timeout' => null,
                    ],
                ],
            ],
        ]);

        $this->assertSame(3600, PasswordConfirmation::timeout($config, 'admin'));
    }

    public function testMissingGuardTimeoutInheritsGlobal(): void
    {
        $config = new Repository([
            'auth' => [
                'password_timeout' => 3600,
                'guards' => [
                    'admin' => [],
                ],
            ],
        ]);

        $this->assertSame(3600, PasswordConfirmation::timeout($config, 'admin'));
    }

    public function testTimeoutFailsWhenGlobalSettingIsMissing(): void
    {
        $config = new Repository([
            'auth' => [
                'guards' => [
                    'admin' => [
                        'password_timeout' => null,
                    ],
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration value for key [auth.password_timeout] must be an integer, NULL given.');

        PasswordConfirmation::timeout($config, 'admin');
    }

    public function testShippedWebGuardInheritsGlobalTimeout(): void
    {
        $auth = require __DIR__ . '/../../src/foundation/config/auth.php';
        $config = new Repository(['auth' => $auth]);

        $this->assertSame(
            $config->integer('auth.password_timeout'),
            PasswordConfirmation::timeout($config, 'web')
        );
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
