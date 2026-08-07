<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite\Fixtures;

use GuzzleHttp\Client;
use Hypervel\Socialite\Two\OpenIdProvider;
use Hypervel\Socialite\Two\User;
use Mockery as m;
use SensitiveParameter;

class VerifyingOpenIdTestProviderStub extends OpenIdProvider
{
    protected bool $usesNonce = false;

    public ?Client $http = null;

    public function verifyToken(#[SensitiveParameter] string $token): array
    {
        return $this->getUserByOIDCToken($token);
    }

    public function setJwksRefreshCooldownSeconds(int $seconds): void
    {
        $this->jwksRefreshCooldownSeconds = $seconds;
    }

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

    protected function getUserByToken(#[SensitiveParameter] string $token): array
    {
        return ['id' => 'foo'];
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->map(['id' => $user['sub']]);
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
