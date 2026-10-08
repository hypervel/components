<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\Oauth2;

use Hypervel\Saloon\Data\AuthorizationUrl;
use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Exceptions\OAuthConfigValidationException;
use Hypervel\Saloon\Http\Request;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Hypervel\Tests\TestCase;

// The configuration is immutable: its constructor arguments replace upstream's setters and its public properties
// replace the getters.
class OAuthConfigTest extends TestCase
{
    public function testAllDefaultPropertiesAreCorrectAndAllPropertiesCanBeConfigured(): void
    {
        $config = new OAuthConfig('client-id', 'client-secret');

        $this->assertSame('', $config->redirectUri);
        $this->assertSame('authorize', $config->authorizeEndpoint);
        $this->assertSame('token', $config->tokenEndpoint);
        $this->assertNull($config->refreshEndpoint);
        $this->assertSame('user', $config->userEndpoint);
        $this->assertSame([], $config->defaultScopes);
        $this->assertNull($config->requestModifier);
        $this->assertFalse($config->allowBaseUrlOverride);

        $config = new OAuthConfig(
            clientId: 'client-id',
            clientSecret: 'client-secret',
            redirectUri: 'https://my-app.saloon.dev/auth/callback',
            authorizeEndpoint: 'auth/authorize',
            tokenEndpoint: 'auth/token',
            refreshEndpoint: 'auth/refresh',
            userEndpoint: 'auth/user',
            defaultScopes: ['profile'],
            allowBaseUrlOverride: true,
        );

        $this->assertSame('client-id', $config->clientId);
        $this->assertSame('client-secret', $config->clientSecret);
        $this->assertSame('https://my-app.saloon.dev/auth/callback', $config->redirectUri);
        $this->assertSame('auth/authorize', $config->authorizeEndpoint);
        $this->assertSame('auth/token', $config->tokenEndpoint);
        $this->assertSame('auth/refresh', $config->refreshEndpoint);
        $this->assertSame('auth/user', $config->userEndpoint);
        $this->assertSame(['profile'], $config->defaultScopes);
        $this->assertTrue($config->allowBaseUrlOverride);
    }

    // REMOVED: make method creates an instance of OAuthConfig. The configuration is built with its constructor.

    public function testItWillThrowAnExceptionIfYouDoNotSpecifyTheClientId(): void
    {
        $config = new OAuthConfig('', 'client-secret', 'https://my-app.saloon.dev/auth/callback');

        $this->expectException(OAuthConfigValidationException::class);
        $this->expectExceptionMessageIs('The Client ID is empty or has not been provided.');

        $config->validate();
    }

    public function testItWillThrowAnExceptionIfYouDoNotSpecifyTheClientSecret(): void
    {
        $config = new OAuthConfig('client-id', '', 'https://my-app.saloon.dev/auth/callback');

        $this->expectException(OAuthConfigValidationException::class);
        $this->expectExceptionMessageIs('The Client Secret is empty or has not been provided.');

        $config->validate();
    }

    public function testItWillThrowAnExceptionIfYouDoNotSpecifyTheRedirectUri(): void
    {
        $config = new OAuthConfig('client-id', 'client-secret');

        $this->expectException(OAuthConfigValidationException::class);
        $this->expectExceptionMessageIs('The Redirect URI is empty or has not been provided.');

        $config->validate();
    }

    public function testValidationAcceptsNonEmptyValuesAndOptionalRedirectUri(): void
    {
        $this->assertTrue((new OAuthConfig('0', '0', '0'))->validate());
        $this->assertTrue((new OAuthConfig('client-id', 'client-secret'))->validate(withRedirectUrl: false));
    }

    public function testTheRequestModifierChangesARequest(): void
    {
        $config = new OAuthConfig(
            clientId: 'client-id',
            clientSecret: 'client-secret',
            requestModifier: fn (Request $request): Request => $request->withHeader('X-OAuth', 'configured'),
        );
        $request = new UserRequest;

        $this->assertSame($request, $config->modify($request));
        $this->assertSame(['X-OAuth' => 'configured'], $request->headers());
    }

    public function testAuthorizationUrlKeepsTheUrlAndStateTogether(): void
    {
        $authorization = new AuthorizationUrl('https://provider.example.com/authorize', 'state');

        $this->assertSame('https://provider.example.com/authorize', (string) $authorization);
        $this->assertSame('state', $authorization->state);
    }
}
