<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Data;

use Hypervel\Saloon\Data\AuthorizationUrl;
use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Exceptions\OAuthConfigValidationException;
use Hypervel\Saloon\Http\Request;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class OAuthConfigTest extends TestCase
{
    public function testConfigurationIsImmutableAndModifiesARequest(): void
    {
        $config = new OAuthConfig(
            clientId: 'client',
            clientSecret: 'secret',
            redirectUri: 'https://app.example.com/callback',
            defaultScopes: ['openid'],
            requestModifier: fn (Request $request) => $request->withHeader('X-OAuth', 'configured'),
        );
        $request = new OAuthConfigRequestStub;

        $this->assertSame($request, $config->modify($request));
        $this->assertSame(['X-OAuth' => 'configured'], $request->headers());
        $config->validate();
    }

    #[DataProvider('invalidConfigurations')]
    public function testItValidatesRequiredConfiguration(OAuthConfig $config, bool $withRedirectUri, string $message): void
    {
        $this->expectException(OAuthConfigValidationException::class);
        $this->expectExceptionMessage($message);

        $config->validate($withRedirectUri);
    }

    public static function invalidConfigurations(): array
    {
        return [
            'client id' => [new OAuthConfig('', 'secret', 'https://app.example.com/callback'), true, 'client ID'],
            'client secret' => [new OAuthConfig('client', '', 'https://app.example.com/callback'), true, 'client secret'],
            'redirect uri' => [new OAuthConfig('client', 'secret'), true, 'redirect URI'],
        ];
    }

    public function testRedirectUriIsOptionalForClientCredentials(): void
    {
        $config = new OAuthConfig('client', 'secret');

        $config->validate(false);

        $this->addToAssertionCount(1);
    }

    public function testAuthorizationUrlKeepsTheUrlAndStateTogether(): void
    {
        $authorization = new AuthorizationUrl('https://provider.example.com/authorize', 'state');

        $this->assertSame('https://provider.example.com/authorize', (string) $authorization);
        $this->assertSame('state', $authorization->state);
    }
}

class OAuthConfigRequestStub extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/oauth';
    }
}
