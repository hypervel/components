<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite\Fixtures;

use GuzzleHttp\Client;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\AbstractProvider;
use Hypervel\Socialite\Two\User;
use Mockery as m;
use SensitiveParameter;

class OAuthTwoTestProviderStub extends AbstractProvider
{
    public ?Client $http = null;

    protected function getAuthUrl(?string $state): string
    {
        return $this->buildAuthUrlFromBase('http://auth.url', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'http://token.url';
    }

    protected function getUserByToken(#[SensitiveParameter] string $token): array
    {
        return ['id' => 'foo'];
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->map(['id' => $user['id']]);
    }

    public function parseProviderAccessToken(#[SensitiveParameter] array $response): string
    {
        return $this->parseAccessToken($response);
    }

    public function parseProviderRefreshToken(#[SensitiveParameter] array $response): ?string
    {
        return $this->parseRefreshToken($response);
    }

    public function parseProviderExpiresIn(#[SensitiveParameter] array $response): ?int
    {
        return $this->parseExpiresIn($response);
    }

    public function parseProviderApprovedScopes(#[SensitiveParameter] array $response): array
    {
        return $this->parseApprovedScopes($response);
    }

    public function getProviderUser(): ?User
    {
        return $this->getUser();
    }

    public function getProviderRequest(): Request
    {
        return $this->getRequest();
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
