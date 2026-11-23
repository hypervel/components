<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Passport\AccessToken;
use Hypervel\Passport\Contracts\OAuthenticatable;
use Hypervel\Passport\HasApiTokens;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class HasApiTokensTest extends TestCase
{
    use VerifiesDoubles;

    public function testTokenCanIndicatesIfTokenHasGivenScope(): void
    {
        $user = new HasApiTokensTestStub;
        $token = Double::for(AccessToken::class)->strict();
        $token->expects('can')->with('scope')->returns(true);
        $token->expects('can')->with('another-scope')->returns(false);

        $this->assertTrue($user->withAccessToken($token)->tokenCan('scope'));
        $this->assertFalse($user->withAccessToken($token)->tokenCan('another-scope'));
    }
}

class HasApiTokensTestStub extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;

    /**
     * Get the test user's authentication identifier.
     */
    public function getAuthIdentifier(): int
    {
        return 1;
    }
}
