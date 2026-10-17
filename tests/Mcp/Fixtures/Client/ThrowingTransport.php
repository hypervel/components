<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures\Client;

use Hypervel\Mcp\Exceptions\ClientException;

class ThrowingTransport extends FakeTransport
{
    /**
     * Simulate a transport teardown failure.
     */
    public function disconnect(): never
    {
        throw new ClientException('disconnect failed');
    }
}
