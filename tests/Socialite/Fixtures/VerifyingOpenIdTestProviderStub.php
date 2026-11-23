<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite\Fixtures;

use GuzzleHttp\Client;
use Hypervel\Socialite\Two\OpenIdProvider;
use Hypervel\Socialite\Two\User;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use SensitiveParameter;

class VerifyingOpenIdTestProviderStub extends OpenIdProvider
{
    protected bool $usesNonce = false;

    public (Client&DoubleInterface)|null $http = null;

    public function verifyToken(#[SensitiveParameter] string $token): array
    {
        return $this->getUserByOIDCToken($token);
    }

    public function setJwksRefreshCooldownSeconds(int $seconds): void
    {
        $this->jwksRefreshCooldownSeconds = $seconds;
    }

    public function setJwksDefaultTtlSeconds(int $seconds): void
    {
        $this->jwksDefaultTtlSeconds = $seconds;
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
     * Return the test HTTP client.
     */
    protected function getHttpClient(): Client
    {
        if ($this->http) {
            return $this->http;
        }

        return $this->http = Double::for(Client::class)->strict();
    }
}
