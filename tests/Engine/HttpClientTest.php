<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Http\Client;
use Hypervel\Tests\TestCase;

class HttpClientTest extends TestCase
{
    public function testClosingAnUnconnectedClientReportsNoConnection(): void
    {
        if (SWOOLE_VERSION_ID <= 60203) {
            $this->markTestSkipped('Requires initialized coroutine HTTP client socket state from https://github.com/swoole/swoole-src/pull/6186.');
        }

        $client = new Client('127.0.0.1', 80);

        $this->assertFalse($client->close());
        $this->assertSame(SWOOLE_ERROR_CLIENT_NO_CONNECTION, $client->errCode);
    }
}
