<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Transport;

use Hypervel\ObjectPool\ObjectPool;
use Hypervel\ObjectPool\PoolOptions;
use Sentry\ClientBuilder;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Transport\HttpTransport;

/**
 * @extends ObjectPool<HttpTransport>
 */
class HttpTransportPool extends ObjectPool
{
    /**
     * Create a pool of reusable Sentry HTTP transports.
     */
    public function __construct(
        protected ClientBuilder $clientBuilder,
        PoolOptions $poolOptions,
    ) {
        parent::__construct($poolOptions);
    }

    /**
     * Create an HTTP transport with its own rate-limit state.
     *
     * Transports are created on first use, after the builder has been customized, so they use its
     * configured HTTP client and logger like the SDK's own transport. Without a configured client,
     * the builder creates a separate SDK client for each transport.
     */
    protected function createObject(): HttpTransport
    {
        $options = $this->clientBuilder->getOptions();

        return new HttpTransport(
            $options,
            $this->clientBuilder->getHttpClient(),
            new PayloadSerializer($options),
            $this->clientBuilder->getLogger()
        );
    }
}
