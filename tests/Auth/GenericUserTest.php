<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\GenericUser;
use Hypervel\Tests\TestCase;

class GenericUserTest extends TestCase
{
    public function testGetAuthIdentifierNameReturnsId(): void
    {
        $user = new GenericUser(['id' => 1]);

        $this->assertSame('id', $user->getAuthIdentifierName());
    }

    public function testGetAuthIdentifierReturnsIdValue(): void
    {
        $user = new GenericUser(['id' => 42]);

        $this->assertSame(42, $user->getAuthIdentifier());
    }

    public function testGetAuthPasswordNameReturnsPassword(): void
    {
        $user = new GenericUser(['id' => 1]);

        $this->assertSame('password', $user->getAuthPasswordName());
    }

    public function testGetAuthPasswordReturnsPasswordValue(): void
    {
        $user = new GenericUser(['id' => 1, 'password' => 'secret']);

        $this->assertSame('secret', $user->getAuthPassword());
    }

    public function testGetRememberTokenReturnsTokenValue(): void
    {
        $user = new GenericUser(['id' => 1, 'remember_token' => 'token123']);

        $this->assertSame('token123', $user->getRememberToken());
    }

    public function testSetRememberTokenUpdatesToken(): void
    {
        $user = new GenericUser(['id' => 1, 'remember_token' => 'old']);

        $user->setRememberToken('new');

        $this->assertSame('new', $user->getRememberToken());
    }

    public function testGetRememberTokenNameReturnsColumnName(): void
    {
        $user = new GenericUser(['id' => 1]);

        $this->assertSame('remember_token', $user->getRememberTokenName());
    }

    public function testMagicGetReturnsAttributeValue(): void
    {
        $user = new GenericUser(['id' => 1, 'name' => 'Taylor']);

        $this->assertSame('Taylor', $user->name);
    }

    public function testMagicSetUpdatesAttribute(): void
    {
        $user = new GenericUser(['id' => 1]);

        $user->name = 'Taylor';

        $this->assertSame('Taylor', $user->name);
    }

    public function testMagicIssetReturnsTrueForExistingAttribute(): void
    {
        $user = new GenericUser(['id' => 1, 'name' => 'Taylor']);

        $this->assertTrue(isset($user->name));
    }

    public function testMagicIssetReturnsFalseForMissingAttribute(): void
    {
        $user = new GenericUser(['id' => 1]);

        $this->assertFalse(isset($user->name));
    }

    public function testMagicUnsetRemovesAttribute(): void
    {
        $user = new GenericUser(['id' => 1, 'name' => 'Taylor']);

        unset($user->name);

        $this->assertFalse(isset($user->name));
    }
}
