<?php

declare(strict_types=1);

namespace Hypervel\Sentry\HttpClient;

use FriendsOfHyperf\Sentry\HttpClient\HttpClient;
use FriendsOfHyperf\Sentry\Version;
use Hypervel\Foundation\Contracts\Application;

class HttpClientFactory
{
    public function __invoke(Application $container): HttpClient
    {
        return new HttpClient(
            Version::getSdkIdentifier(),
            Version::getSdkVersion(),
        );
    }
}
