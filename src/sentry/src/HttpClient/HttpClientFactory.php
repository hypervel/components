<?php

declare(strict_types=1);

namespace Hypervel\Sentry\HttpClient;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Sentry\Version;

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
