<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Fixtures;

use GuzzleHttp\Handler\StreamHandler;
use Hypervel\Http\Client\CurlStreamingHandler;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\PendingRequest;

class StreamingHttpFactory extends Factory
{
    /**
     * Choose the real streaming transport for local acceptance tests.
     */
    public function __construct(private readonly bool $native)
    {
        parent::__construct();
    }

    /**
     * Keep the HTTP request stack while selecting its underlying transport.
     */
    protected function newPendingRequest(): PendingRequest
    {
        return parent::newPendingRequest()->setHandler($this->native ? new CurlStreamingHandler : new StreamHandler);
    }
}
