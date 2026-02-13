<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Guzzle\Stub;

use Hypervel\Engine\Http\Client;
use Hypervel\Guzzle\PoolHandler;

class PoolHandlerStub extends PoolHandler
{
    public int $count = 0;

    protected function makeClient(string $host, int $port, bool $ssl): Client
    {
        ++$this->count;

        return parent::makeClient($host, $port, $ssl);
    }
}
