<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite\Fixtures;

use GuzzleHttp\Client;
use Hypervel\Socialite\Two\GoogleProvider;
use Hypervel\Socialite\Two\User;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;

class GoogleTestProviderStub extends GoogleProvider
{
    public (Client&DoubleInterface)|null $http = null;

    protected function getAuthUrl(?string $state): string
    {
        return $this->buildAuthUrlFromBase('http://auth.url', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'http://token.url';
    }

    protected function getUserByToken(string $token): array
    {
        return ['id' => 'foo'];
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->map(['id' => $user['id']]);
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
