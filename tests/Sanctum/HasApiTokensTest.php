<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\TransientToken;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Stub\UserWithApiTokens;

/**
 * @internal
 * @coversNothing
 */
class HasApiTokensTest extends TestCase
{
    public function testCanCheckTokenAbilitiesWithTransientToken(): void
    {
        $user = new UserWithApiTokens();
        $user->withAccessToken(new TransientToken());

        $this->assertTrue($user->tokenCan('foo'));
        $this->assertTrue($user->tokenCan('bar'));
        $this->assertFalse($user->tokenCant('foo'));
    }

    public function testCanCheckTokenAbilitiesWithPersonalAccessToken(): void
    {
        $user = new UserWithApiTokens();
        
        $token = new PersonalAccessToken();
        $token->abilities = ['foo', 'baz'];
        
        $user->withAccessToken($token);

        $this->assertTrue($user->tokenCan('foo'));
        $this->assertFalse($user->tokenCan('bar'));
        $this->assertTrue($user->tokenCan('baz'));
        $this->assertTrue($user->tokenCant('bar'));
        $this->assertFalse($user->tokenCant('foo'));
    }

    public function testCurrentAccessTokenGetter(): void
    {
        $user = new UserWithApiTokens();
        
        $this->assertNull($user->currentAccessToken());
        
        $token = new TransientToken();
        $user->withAccessToken($token);
        
        $this->assertSame($token, $user->currentAccessToken());
    }
}