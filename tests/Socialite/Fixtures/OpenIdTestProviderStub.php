<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite\Fixtures;

use GuzzleHttp\Client;
use Hypervel\Socialite\Two\OpenIdProvider;
use Hypervel\Socialite\Two\User;
use Mockery as m;
use SensitiveParameter;

class OpenIdTestProviderStub extends OpenIdProvider
{
    public ?Client $http = null;

    protected function getBaseUrl(): string
    {
        return $this->getConfig('base_url', 'http://base.url');
    }

    protected function getAuthUrl(?string $state, ?string $nonce = null): string
    {
        return $this->buildAuthUrlFromBase('http://auth.url', $state, $nonce);
    }

    protected function getTokenUrl(): string
    {
        return 'http://token.url';
    }

    /**
     * Get user based on the OIDC token.
     */
    protected function getUserByOIDCToken(#[SensitiveParameter] string $token): array
    {
        $this->validateOIDCPayload(
            $data = [
                'sub' => 'foo',
                'iss' => 'http://base.url',
                'aud' => 'client_id',
                'nonce' => 'nonce',
            ]
        );

        return $data;
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->map(['id' => $user['sub']]);
    }

    public function getProviderOpenIdConfig(bool $refresh = false): array
    {
        return $this->getOpenIdConfig($refresh);
    }

    public function validateProviderPayload(array $payload): void
    {
        $this->validateOIDCPayload($payload);
    }

    public function getProviderUserByTokenResponse(#[SensitiveParameter] array $response): array
    {
        return $this->getUserByTokenResponse($response);
    }

    public function getProviderUserByToken(#[SensitiveParameter] string $token): array
    {
        return $this->getUserByToken($token);
    }

    /**
     * Get a fresh instance of the Guzzle HTTP client.
     */
    protected function getHttpClient(): Client
    {
        if ($this->http) {
            return $this->http;
        }

        return $this->http = m::mock(Client::class);
    }
}
