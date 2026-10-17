<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use DateInterval;
use Hypervel\Passport\AuthCode;
use Hypervel\Passport\Client;
use Hypervel\Passport\DeviceCode;
use Hypervel\Passport\Passport;
use Hypervel\Passport\PushedAuthorizationRequest;
use Hypervel\Passport\RefreshToken;
use Hypervel\Passport\ServerSettings;
use Hypervel\Passport\Token;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;

// REMOVED: RedirectRuleTest covers validation owned by the deprecated client JSON API; applications validate their own client-management input.
class PassportTest extends TestCase
{
    public function testScopesCanBeManaged(): void
    {
        Passport::tokensCan([
            'user' => 'get user information',
        ]);

        $this->assertTrue(Passport::hasScope('user'));
        $this->assertEquals(['user'], Passport::scopeIds());
        $this->assertSame('user', Passport::scopes()[0]->id);
    }

    public function testAuthCodeInstanceCanBeCreated(): void
    {
        $authCode = Passport::authCode();

        $this->assertInstanceOf(AuthCode::class, $authCode);
        $this->assertInstanceOf(Passport::authCodeModel(), $authCode);
    }

    public function testResourcesUseExactAbsoluteIdentifiersAndReadableDescriptions(): void
    {
        Passport::tokensCanAccess(['urn:example:calendar' => 'Calendar', 'https://api.example?account=1' => 'Account']);
        $this->assertTrue(Passport::hasResource('urn:example:calendar'));
        $this->assertFalse(Passport::hasResource('https://api.example?account=2'));
        $resource = Passport::resourcesFor(['urn:example:calendar', 'urn:unknown'])[0];
        $this->assertSame(['id' => 'urn:example:calendar', 'description' => 'Calendar'], $resource->toArray());
        $this->assertSame($resource->toArray(), json_decode($resource->toJson(), true));
    }

    #[TestWith(['/relative'])]
    #[TestWith(['https://api.example#fragment'])]
    public function testResourceCatalogRejectsInvalidIdentifiers(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);
        Passport::tokensCanAccess([$identifier => 'Invalid']);
    }

    public function testServerSettingsRejectInvalidResourceIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ServerSettings([], resources: ['/relative' => 'Invalid']);
    }

    public function testClientInstanceCanBeCreated(): void
    {
        $client = Passport::client();

        $this->assertInstanceOf(Client::class, $client);
        $this->assertInstanceOf(Passport::clientModel(), $client);
    }

    public function testTokenInstanceCanBeCreated(): void
    {
        $token = Passport::token();

        $this->assertInstanceOf(Token::class, $token);
        $this->assertInstanceOf(Passport::tokenModel(), $token);
    }

    public function testRefreshTokenInstanceCanBeCreated(): void
    {
        $refreshToken = Passport::refreshToken();

        $this->assertInstanceOf(RefreshToken::class, $refreshToken);
        $this->assertInstanceOf(Passport::refreshTokenModel(), $refreshToken);
    }

    public function testRefreshTokenModelCanBeChanged(): void
    {
        Passport::useRefreshTokenModel(RefreshTokenStub::class);

        $refreshToken = Passport::refreshToken();

        $this->assertInstanceOf(RefreshTokenStub::class, $refreshToken);
        $this->assertInstanceOf(Passport::refreshTokenModel(), $refreshToken);
    }

    public function testDeviceCodeInstanceCanBeCreated(): void
    {
        $deviceCode = Passport::deviceCode();

        $this->assertInstanceOf(DeviceCode::class, $deviceCode);
        $this->assertInstanceOf(Passport::deviceCodeModel(), $deviceCode);
    }

    public function testClientCredentialsTokensExpireInFallsBackToTokensExpireIn(): void
    {
        $this->assertEquals(new DateInterval('P1Y'), Passport::clientCredentialsTokensExpireIn());

        Passport::tokensExpireIn($interval = new DateInterval('P1D'));

        $this->assertSame($interval, Passport::clientCredentialsTokensExpireIn());

        Passport::clientCredentialsTokensExpireIn($interval = new DateInterval('PT1H'));

        $this->assertSame($interval, Passport::clientCredentialsTokensExpireIn());
    }

    public function testFlushStateRestoresConfigurationAndRemovesApplicationCallbacks(): void
    {
        Passport::useRefreshTokenModel(RefreshTokenStub::class);
        Passport::usePushedAuthorizationRequestModel(PushedAuthorizationRequestStub::class);
        Passport::cookie('custom_cookie');
        Passport::tokensCan(['custom' => 'Custom scope']);
        Passport::tokensCanAccess(['urn:example:custom' => 'Custom resource']);
        Passport::tokensExpireIn(new DateInterval('PT5M'));
        Passport::inheritGrantAttributes(['organization_id']);
        Passport::authenticateAccessTokensUsing(static fn (): bool => false);
        Passport::authenticateClientsUsing(static fn (): bool => false);
        Passport::authorizeIntrospectionUsing(static fn (): bool => false);
        Passport::bindCookiesUsing(static fn (): string => 'organization');
        Passport::rememberConsentFor(new DateInterval('PT5M'));
        Passport::accessTokenClaimsUsing(static fn (): array => ['organization_id' => 1]);
        Passport::issueRefreshTokensUsing(static fn (): bool => false);

        Passport::flushState();

        $this->assertSame(RefreshToken::class, Passport::refreshTokenModel());
        $this->assertSame(PushedAuthorizationRequest::class, Passport::pushedAuthorizationRequestModel());
        $this->assertSame('hypervel_token', Passport::cookie());
        $this->assertSame([], Passport::scopeIds());
        $this->assertFalse(Passport::hasResource('urn:example:custom'));
        $this->assertEquals(new DateInterval('P1Y'), Passport::tokensExpireIn());
        $this->assertSame([], Passport::$inheritedGrantAttributes);
        $this->assertNull(Passport::$accessTokenAuthenticationCallback);
        $this->assertNull(Passport::$clientAuthenticationCallback);
        $this->assertNull(Passport::$introspectionAuthorizationCallback);
        $this->assertNull(Passport::$cookieBindingCallback);
        $this->assertNull(Passport::rememberConsentFor());
        $this->assertNull(Passport::$accessTokenClaimsCallback);
        $this->assertNull(Passport::$issueRefreshTokensCallback);
    }
}

class RefreshTokenStub extends RefreshToken
{
}

class PushedAuthorizationRequestStub extends PushedAuthorizationRequest
{
}
