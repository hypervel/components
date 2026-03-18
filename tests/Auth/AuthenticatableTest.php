<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Foundation\Auth\User;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AuthenticatableTest extends TestCase
{
    public function testItReturnsSameRememberTokenForString()
    {
        $user = new User();
        $user->setRememberToken('sample_token');
        $this->assertSame('sample_token', $user->getRememberToken());
    }

    // REMOVED: testItReturnsStringAsRememberTokenWhenItWasSetToTrue
    // Tests implicit bool-to-string coercion which is a TypeError under strict_types.

    public function testItReturnsNullWhenRememberTokenNameWasSetToEmpty()
    {
        $user = new class extends User {
            public function getRememberTokenName(): string
            {
                return '';
            }
        };
        $user->setRememberToken('sample_token');
        $this->assertNull($user->getRememberToken());
    }
}
