<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\GenericUser;
use Hypervel\Tests\TestCase;

class GenericUserTest extends TestCase
{
    public function testGetAuthIdentifierNameReturnsId()
    {
        $user = new GenericUser(['id' => 1]);

        $this->assertSame('id', $user->getAuthIdentifierName());
    }

    public function testGetAuthIdentifierReturnsIdValue()
    {
        $user = new GenericUser(['id' => 42]);

        $this->assertSame(42, $user->getAuthIdentifier());
    }

    public function testGetAuthPasswordNameReturnsPassword()
    {
        $user = new GenericUser(['id' => 1]);

        $this->assertSame('password', $user->getAuthPasswordName());
    }

    public function testGetAuthPasswordReturnsPasswordValue()
    {
        $user = new GenericUser(['id' => 1, 'password' => 'secret']);

        $this->assertSame('secret', $user->getAuthPassword());
    }

    public function testGetRememberTokenReturnsTokenValue()
    {
        $user = new GenericUser(['id' => 1, 'remember_token' => 'token123']);

        $this->assertSame('token123', $user->getRememberToken());
    }

    public function testSetRememberTokenUpdatesToken()
    {
        $user = new GenericUser(['id' => 1, 'remember_token' => 'old']);

        $user->setRememberToken('new');

        $this->assertSame('new', $user->getRememberToken());
    }

    public function testGetRememberTokenNameReturnsColumnName()
    {
        $user = new GenericUser(['id' => 1]);

        $this->assertSame('remember_token', $user->getRememberTokenName());
    }

    public function testMagicGetReturnsAttributeValue()
    {
        $user = new GenericUser(['id' => 1, 'name' => 'Taylor']);

        $this->assertSame('Taylor', $user->name);
    }

    public function testMagicSetUpdatesAttribute()
    {
        $user = new GenericUser(['id' => 1]);

        $user->name = 'Taylor';

        $this->assertSame('Taylor', $user->name);
    }

    public function testMagicIssetReturnsTrueForExistingAttribute()
    {
        $user = new GenericUser(['id' => 1, 'name' => 'Taylor']);

        $this->assertTrue(isset($user->name));
    }

    public function testMagicIssetReturnsFalseForMissingAttribute()
    {
        $user = new GenericUser(['id' => 1]);

        $this->assertFalse(isset($user->name));
    }

    public function testMagicUnsetRemovesAttribute()
    {
        $user = new GenericUser(['id' => 1, 'name' => 'Taylor']);

        unset($user->name);

        $this->assertFalse(isset($user->name));
    }
}
