<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Guzzle;

use GuzzleHttp\Client;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Container\Container;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Pool\Channel;
use Hypervel\Pool\PoolOption;
use Hypervel\Pool\SimplePool\Connection;
use Hypervel\Pool\SimplePool\Pool;
use Hypervel\Pool\SimplePool\PoolFactory;
use Hypervel\Tests\Integration\Guzzle\Stub\PoolHandlerStub;
use Mockery as m;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Integration tests for PoolHandler.
 *
 * Tests connection pooling behavior using the HTTP test server.
 *
 * @internal
 * @coversNothing
 */
#[CoversNothing]
class PoolHandlerTest extends GuzzleIntegrationTestCase
{
    protected int $id = 0;

    /**
     * Test that try/finally works correctly (basic PHP behavior sanity check).
     */
    public function testTryFinally()
    {
        $this->get();

        $this->assertSame(2, $this->id);
    }

    /**
     * Test that the pool handler reuses connections.
     *
     * Makes two requests and verifies the handler only creates one client,
     * proving that connections are being pooled and reused.
     */
    public function testPoolHandler()
    {
        $container = $this->getContainer();
        $client = new Client([
            'handler' => $handler = new PoolHandlerStub($container->make(PoolFactory::class), []),
            'base_uri' => sprintf('http://%s:%d', $this->getServerHost(), $this->getServerPort()),
        ]);

        $response = $client->get('/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Hello World.', (string) $response->getBody());
        $this->assertSame(1, $handler->count);

        // Second request should reuse the pooled connection
        $client->get('/');
        $this->assertSame(1, $handler->count);
    }

    protected function get(): void
    {
        try {
            $this->id = 1;
            return;
        } finally {
            $this->id = 2;
        }
    }

    /**
     * Create a mock container with pool dependencies.
     */
    protected function getContainer(): Container
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('make')->with(PoolOption::class, m::andAnyOtherArgs())->andReturnUsing(function ($_, $args) {
            return new PoolOption(...array_values($args));
        });
        $container->shouldReceive('make')->with(Pool::class, m::andAnyOtherArgs())->andReturnUsing(function ($_, $args) use ($container) {
            return new Pool($container, $args['callback'], $args['option']);
        });
        $container->shouldReceive('make')->with(PoolFactory::class)->andReturnUsing(function () use ($container) {
            return new PoolFactory($container);
        });
        $container->shouldReceive('make')->with(Channel::class, m::any())->andReturnUsing(function ($_, $args) {
            return new Channel($args['size']);
        });
        $container->shouldReceive('make')->with(Connection::class, m::any())->andReturnUsing(function ($_, $args) use ($container) {
            return new Connection($container, $args['pool'], $args['callback']);
        });
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->andReturnFalse();
        $container->shouldReceive('has')->with(Dispatcher::class)->andReturnFalse();

        Container::setInstance($container);

        return $container;
    }
}
