<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature\Oauth2;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Exceptions\PendingRequestException;
use Hypervel\Saloon\Http\OAuth2\GetClientCredentialsTokenRequest;
use Hypervel\Saloon\Http\OAuth2\GetUserRequest;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\AbsoluteTokenOAuthConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\AbsoluteTokenOAuthConnectorWithoutAllow;
use Hypervel\Tests\Saloon\Fixtures\Connectors\AuthorizeUrlOAuthConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\AuthorizeUrlOAuthConnectorWithConnectorAllowOnly;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;

// Rejected endpoints throw PendingRequestException, and requests opt in by overriding allowsBaseUrlOverride(). The URL
// is resolved after request middleware may change it, so the rejection cases read the pending request's URI.
class AbsoluteOAuthEndpointTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testOAuthClientCredentialsPendingRequestUsesAbsoluteTokenUrlWhenOAuthConfigAllowsBaseOverride(): void
    {
        $connector = new AbsoluteTokenOAuthConnector;
        $request = new GetClientCredentialsTokenRequest($connector->oauthConfig());

        $pending = $connector->createPendingRequest($request);

        $this->assertSame('https://auth.external.com/oauth/token', (string) $pending->uri());
    }

    public function testOAuthClientCredentialsThrowsWhenTokenEndpointIsAbsoluteAndOAuthConfigDoesNotAllowOverride(): void
    {
        $connector = new AbsoluteTokenOAuthConnectorWithoutAllow;
        $request = new GetClientCredentialsTokenRequest($connector->oauthConfig());

        $this->expectException(PendingRequestException::class);

        $connector->createPendingRequest($request)->uri();
    }

    public function testRequestAllowBaseUrlOverrideFalseOverridesOAuthConfigAllowForTokenUrl(): void
    {
        $connector = new AbsoluteTokenOAuthConnector;
        $request = new class($connector->oauthConfig()) extends GetClientCredentialsTokenRequest {
            /**
             * Resolve whether the trusted OAuth endpoint may replace the connector base URL.
             */
            public function allowsBaseUrlOverride(): bool
            {
                return false;
            }
        };

        $this->expectException(PendingRequestException::class);

        $connector->createPendingRequest($request)->uri();
    }

    public function testGetAuthorizationUrlWorksWithAbsoluteAuthorizeEndpointWhenOAuthConfigAllowsOverride(): void
    {
        $connector = new AuthorizeUrlOAuthConnector;
        $url = (string) $connector->authorizationUrl();

        $this->assertStringStartsWith('https://login.provider.com/authorize', $url);
    }

    public function testGetAuthorizationUrlWorksWithAbsoluteAuthorizeWhenConnectorAllowsOverrideOnly(): void
    {
        $connector = new AuthorizeUrlOAuthConnectorWithConnectorAllowOnly;
        $url = (string) $connector->authorizationUrl();

        $this->assertStringStartsWith('https://login.provider.com/authorize', $url);
    }

    public function testTheUserRequestUsesAnAbsoluteUserUrlWhenOAuthConfigAllowsBaseOverride(): void
    {
        $config = new OAuthConfig(
            'client',
            'secret',
            userEndpoint: 'https://oauth.example.net/user',
            allowBaseUrlOverride: true,
        );

        $pending = (new TestConnector)->createPendingRequest(new GetUserRequest($config));

        $this->assertSame('https://oauth.example.net/user', (string) $pending->uri());
    }
}
