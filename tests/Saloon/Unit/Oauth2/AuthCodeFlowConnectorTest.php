<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\Oauth2;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Exceptions\OAuthConfigValidationException;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Auth\AccessTokenAuthenticator;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\OAuth2Connector;

// Connectors take no mock client, so OAuth sends are faked through Saloon::fake(). The configuration is immutable,
// so connectors receive changed configuration arguments instead of calling its setters.
class AuthCodeFlowConnectorTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testTheOauth2ConfigClassCanBeConfiguredProperly(): void
    {
        $connector = new OAuth2Connector;

        $config = $connector->oauthConfig();

        $this->assertInstanceOf(OAuthConfig::class, $config);
        $this->assertSame('client-id', $config->clientId);
        $this->assertSame('client-secret', $config->clientSecret);
        $this->assertSame('https://my-app.saloon.dev/auth/callback', $config->redirectUri);
    }

    public function testTheOauthConfigIsValidatedWhenGeneratingAnAuthorizationUrl(): void
    {
        $connector = new OAuth2Connector(['clientId' => '']);

        $this->expectException(OAuthConfigValidationException::class);
        $this->expectExceptionMessageIs('The Client ID is empty or has not been provided.');

        $connector->authorizationUrl();
    }

    public function testTheOauthConfigIsValidatedWhenCreatingAccessTokens(): void
    {
        $connector = new OAuth2Connector(['clientId' => '']);

        $this->expectException(OAuthConfigValidationException::class);
        $this->expectExceptionMessageIs('The Client ID is empty or has not been provided.');

        $connector->getAccessToken('code');
    }

    public function testTheOauthConfigIsValidatedWhenRefreshingAccessTokens(): void
    {
        $connector = new OAuth2Connector(['clientId' => '']);

        $this->expectException(OAuthConfigValidationException::class);
        $this->expectExceptionMessageIs('The Client ID is empty or has not been provided.');

        $connector->refreshAccessToken('');
    }

    public function testTheOldRefreshTokenIsCarriedOverIfAResponseDoesNotIncludeANewRefreshToken(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access-new', 'expires_in' => 3600]),
        ]);

        $connector = new OAuth2Connector;

        Saloon::fake($mockClient);

        $authenticator = new AccessTokenAuthenticator('access', 'refresh-old', Date::now()->addSeconds(3600)->toDateTimeImmutable());

        $newAuthenticator = $connector->refreshAccessToken($authenticator);

        $this->assertSame('refresh-old', $newAuthenticator->getRefreshToken());
    }

    public function testTheOldRefreshTokenIsCarriedOverIfAResponseDoesNotIncludeANewRefreshTokenAndTheRefreshIsAString(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access-new', 'expires_in' => 3600]),
        ]);

        $connector = new OAuth2Connector;

        Saloon::fake($mockClient);

        $newAuthenticator = $connector->refreshAccessToken('refresh-old');

        $this->assertSame('refresh-old', $newAuthenticator->getRefreshToken());
    }
}
