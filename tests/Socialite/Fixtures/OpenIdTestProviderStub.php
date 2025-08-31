<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite\Fixtures;

use GuzzleHttp\Client;
use Hypervel\Socialite\Two\OpenIdProvider;
use Hypervel\Socialite\Two\User;
use Mockery as m;

class OpenIdTestProviderStub extends OpenIdProvider
{
    /**
     * @var \GuzzleHttp\Client|\Mockery\MockInterface
     */
    public $http;

    protected function getBaseUrl(): string
    {
        return 'http://base.url';
    }

    protected function getAuthUrl(?string $state, ?string $nonce = null): string
    {
        return $this->buildAuthUrlFromBase('http://auth.url', $state, $nonce);
    }

    protected function getTokenUrl(): string
    {
        return 'http://token.url';
    }

    protected function getUserByToken(string $token): array
    {
        return ['id' => 'foo'];
    }

    /**
     * Get user based on the OIDC token.
     */
    protected function getUserByOIDCToken(string $token): ?array
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
        return (new User())->map(['id' => $user['sub']]);
    }

    /**
     * Get a fresh instance of the Guzzle HTTP client.
     *
     * @return \GuzzleHttp\Client|\Mockery\MockInterface
     */
    protected function getHttpClient(): Client
    {
        if ($this->http) {
            return $this->http;
        }

        return $this->http = m::mock(Client::class);
    }
}
