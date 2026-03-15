<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Transport;

use Hypervel\Contracts\Container\Container;
use Hypervel\ObjectPool\ObjectPool;
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
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected Options $options,
        Container $container,
        array $config = [],
    ) {
        parent::__construct($container, $config);
    }

    protected function createObject(): HttpTransport
    {
        return new HttpTransport(
            $this->options,
            $this->getHttpClient(),
            new PayloadSerializer($this->options),
            $this->options->getLogger()
        );
    }

    protected function getHttpClient(): HttpClientInterface
    {
        return new HttpClient(SentryClient::SDK_IDENTIFIER, SentryClient::SDK_VERSION);
    }
}
