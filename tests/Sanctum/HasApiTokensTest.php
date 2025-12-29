<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\TransientToken;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Stub\TokenAbility;
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

    public function testTokenCanWithBackedEnum(): void
    {
        $user = new UserWithApiTokens();

        $token = new PersonalAccessToken();
        $token->abilities = ['posts:read', 'posts:write'];

        $user->withAccessToken($token);

        $this->assertTrue($user->tokenCan(TokenAbility::PostsRead));
        $this->assertTrue($user->tokenCan(TokenAbility::PostsWrite));
        $this->assertFalse($user->tokenCan(TokenAbility::UsersRead));
    }

    public function testTokenCantWithBackedEnum(): void
    {
        $user = new UserWithApiTokens();

        $token = new PersonalAccessToken();
        $token->abilities = ['posts:read'];

        $user->withAccessToken($token);

        $this->assertFalse($user->tokenCant(TokenAbility::PostsRead));
        $this->assertTrue($user->tokenCant(TokenAbility::PostsWrite));
    }

    public function testTransientTokenCanWithBackedEnum(): void
    {
        $user = new UserWithApiTokens();
        $user->withAccessToken(new TransientToken());

        // TransientToken allows everything
        $this->assertTrue($user->tokenCan(TokenAbility::PostsRead));
        $this->assertTrue($user->tokenCan(TokenAbility::UsersWrite));
    }
}
