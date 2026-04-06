<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Tests\Sanctum\Fixtures\TokenAbility;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class PersonalAccessTokenTest extends TestCase
{
    public function testCanDetermineWhatItCanAndCantDo()
    {
        $token = new PersonalAccessToken;

        $token->abilities = [];

        $this->assertFalse($token->can('foo'));

        $token->abilities = ['foo'];

        $this->assertTrue($token->can('foo'));
        $this->assertFalse($token->can('bar'));
        $this->assertTrue($token->cant('bar'));
        $this->assertFalse($token->cant('foo'));

        $token->abilities = ['foo', '*'];

        $this->assertTrue($token->can('foo'));
        $this->assertTrue($token->can('bar'));
    }

    public function testCanCheckAbilitiesWithBackedEnum()
    {
        $token = new PersonalAccessToken;
        $token->abilities = ['posts:read', 'posts:write'];

        $this->assertTrue($token->can(TokenAbility::PostsRead));
        $this->assertTrue($token->can(TokenAbility::PostsWrite));
        $this->assertFalse($token->can(TokenAbility::UsersRead));
    }

    public function testCantCheckAbilitiesWithBackedEnum()
    {
        $token = new PersonalAccessToken;
        $token->abilities = ['posts:read'];

        $this->assertFalse($token->cant(TokenAbility::PostsRead));
        $this->assertTrue($token->cant(TokenAbility::PostsWrite));
    }

    public function testWildcardAbilityWorksWithBackedEnum()
    {
        $token = new PersonalAccessToken;
        $token->abilities = ['*'];

        $this->assertTrue($token->can(TokenAbility::PostsRead));
        $this->assertTrue($token->can(TokenAbility::PostsWrite));
        $this->assertTrue($token->can(TokenAbility::UsersRead));
    }

    public function testMixedStringAndEnumAbilitiesWork()
    {
        $token = new PersonalAccessToken;
        $token->abilities = ['posts:read', 'legacy-ability'];

        // Enum check
        $this->assertTrue($token->can(TokenAbility::PostsRead));
        // String check for same value
        $this->assertTrue($token->can('posts:read'));
        // String check for legacy
        $this->assertTrue($token->can('legacy-ability'));
    }
}
