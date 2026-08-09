<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Transport;

use Hypervel\ObjectPool\ObjectPool;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Sentry\Version;
use Sentry\HttpClient\HttpClient;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\Options;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Transport\HttpTransport;

/**
 * @extends ObjectPool<HttpTransport>
 */
class Pool extends ObjectPool
{
    public function __construct(
        protected Options $sentryOptions,
        PoolOptions $poolOptions,
    ) {
        parent::__construct($poolOptions);
    }

    protected function createObject(): HttpTransport
    {
        return new HttpTransport(
            $this->sentryOptions,
            $this->getHttpClient(),
            new PayloadSerializer($this->sentryOptions),
            $this->sentryOptions->getLogger()
        );
    }

    protected function getHttpClient(): HttpClientInterface
    {
        return new HttpClient(Version::getSdkIdentifier(), Version::getSdkVersion());
    }
}
