<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Engine;

use Hypervel\Http\Client\Factory;

use function Hypervel\Coroutine\parallel;

/**
 * Integration tests for shared HTTP client connection handlers under Swoole hooks.
 */
class HttpClientConnectionTest extends EngineIntegrationTestCase
{
    protected int $serverPort = 19505;

    public function testSharedConnectionHandlerServesConcurrentRequests(): void
    {
        $factory = new Factory;
        $factory->registerConnection('engine');

        $handler = $factory->getConnectionHandler('engine');
        $url = sprintf('http://%s:%d/', $this->getServerHost(), $this->getServerPort());

        $responses = parallel(array_fill(0, 10, fn () => $factory->connection('engine')->get($url)->body()));

        $this->assertSame($handler, $factory->getConnectionHandler('engine'));
        $this->assertSame(array_fill(0, 10, 'Hello World.'), $responses);
    }
}
