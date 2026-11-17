<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite\Fixtures;

use GuzzleHttp\Client;
use Hypervel\Socialite\Two\FacebookProvider;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;

class FacebookTestProviderStub extends FacebookProvider
{
    public (Client&DoubleInterface)|null $http = null;

    protected function getUserByToken(string $token): array
    {
        return ['id' => 'foo'];
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
