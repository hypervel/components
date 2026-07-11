<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Transport;

use Hypervel\Contracts\Container\Container;
use Hypervel\ObjectPool\ObjectPool;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Sentry\HttpClient\HttpClient;
use Sentry\Client as SentryClient;
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
        Container $container,
        PoolOptions $poolOptions,
    ) {
        parent::__construct($container, $poolOptions);
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
        return new HttpClient(SentryClient::SDK_IDENTIFIER, SentryClient::SDK_VERSION);
    }
}
