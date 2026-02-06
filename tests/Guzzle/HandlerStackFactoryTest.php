<?php

declare(strict_types=1);

namespace Hypervel\Tests\Guzzle;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\HandlerStack;
use Hypervel\Container\Container;
use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Guzzle\CoroutineHandler;
use Hypervel\Guzzle\HandlerStackFactory;
use Hypervel\Guzzle\PoolHandler;
use Hypervel\Guzzle\RetryMiddleware;
use Hypervel\Pool\SimplePool\PoolFactory;
use Hypervel\Tests\Guzzle\Stub\CoroutineHandlerStub;
use Hypervel\Tests\Guzzle\Stub\HandlerStackFactoryStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionClass;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class HandlerStackFactoryTest extends TestCase
{
    use RunTestsInCoroutine;

    /**
     * Test that factory creates a CoroutineHandler when pool is not available.
     */
    public function testCreateCoroutineHandler()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(CoroutineHandler::class)->andReturnFalse();
        $container->shouldReceive('make')->with(CoroutineHandler::class, m::any())->andReturn(new CoroutineHandler());
        ApplicationContext::setContainer($container);

        $factory = new HandlerStackFactory();
        $stack = $factory->create();
        $this->assertInstanceOf(HandlerStack::class, $stack);
        $this->assertTrue($stack->hasHandler());

        $reflection = new ReflectionClass($stack);

        $handler = $reflection->getProperty('handler');
        $this->assertInstanceOf(CoroutineHandler::class, $handler->getValue($stack));

        $property = $reflection->getProperty('stack');
        foreach ($property->getValue($stack) as $stackItem) {
            $this->assertTrue(in_array($stackItem[1], ['http_errors', 'allow_redirects', 'cookies', 'prepare_body', 'retry']));
        }
    }

    /**
     * Test that factory uses container to make CoroutineHandler when available.
     */
    public function testMakeCoroutineHandler()
    {
        $container = m::mock(Container::class);
        ApplicationContext::setContainer($container);
        $container->shouldReceive('has')->with(CoroutineHandler::class)->andReturnFalse();
        $container->shouldReceive('make')->with(CoroutineHandler::class, m::any())->andReturn(new CoroutineHandler());

        $factory = new HandlerStackFactoryStub();
        $stack = $factory->create();
        $this->assertInstanceOf(HandlerStack::class, $stack);
        $this->assertTrue($stack->hasHandler());

        $reflection = new ReflectionClass($stack);

        $handler = $reflection->getProperty('handler');
        $this->assertInstanceOf(CoroutineHandler::class, $handler->getValue($stack));

        $property = $reflection->getProperty('stack');
        foreach ($property->getValue($stack) as $stackItem) {
            $this->assertTrue(in_array($stackItem[1], ['http_errors', 'allow_redirects', 'cookies', 'prepare_body', 'retry']));
        }
    }

    /**
     * Test that factory creates a PoolHandler when pool factory is available.
     */
    public function testCreatePoolHandler()
    {
        $this->setContainer();

        $factory = new HandlerStackFactory();
        $stack = $factory->create();
        $this->assertTrue($stack->hasHandler());
        $this->assertInstanceOf(HandlerStack::class, $stack);

        $reflection = new ReflectionClass($stack);

        $handler = $reflection->getProperty('handler');
        $this->assertInstanceOf(PoolHandler::class, $handler->getValue($stack));

        $property = $reflection->getProperty('stack');
        $items = array_column($property->getValue($stack), 1);

        $this->assertSame(['http_errors', 'allow_redirects', 'cookies', 'prepare_body', 'retry'], $items);
    }

    /**
     * Test that pool options are passed through to the handler.
     */
    public function testPoolHandlerOption()
    {
        $this->setContainer();

        $factory = new HandlerStackFactory();
        $stack = $factory->create(['max_connections' => 50]);

        $stackReflection = new ReflectionClass($stack);
        $handler = $stackReflection->getProperty('handler');
        $handler = $handler->getValue($stack);

        $handlerReflection = new ReflectionClass($handler);
        $option = $handlerReflection->getProperty('option');

        $this->assertSame(50, $option->getValue($handler)['max_connections']);
    }

    /**
     * Test that custom middleware can be added to the handler stack.
     */
    public function testPoolHandlerMiddleware()
    {
        $this->setContainer();

        $factory = new HandlerStackFactory();
        $stack = $factory->create([], ['retry_again' => [RetryMiddleware::class, [1, 10]]]);

        $reflection = new ReflectionClass($stack);
        $property = $reflection->getProperty('stack');
        $items = array_column($property->getValue($stack), 1);
        $this->assertSame(['http_errors', 'allow_redirects', 'cookies', 'prepare_body', 'retry', 'retry_again'], $items);
    }

    /**
     * Test that retry middleware retries failed requests.
     */
    public function testRetryMiddleware()
    {
        $this->setContainer();

        $factory = new HandlerStackFactory();
        $stack = $factory->create([], ['retry_again' => [RetryMiddleware::class, [1, 10]]]);
        $stack->setHandler($stub = new CoroutineHandlerStub(201));

        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'http://127.0.0.1:9501',
        ]);

        $response = $client->get('/');
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(1, $stub->count);

        $stack = $factory->create([], ['retry' => [RetryMiddleware::class, [1, 10]]]);
        $stack->setHandler($stub = new CoroutineHandlerStub(400));
        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'http://127.0.0.1:9501',
        ]);

        $this->expectExceptionCode(400);
        $this->expectException(ClientException::class);
        $this->expectExceptionMessageMatches('/400 Bad Request/');

        try {
            $client->get('/');
        } catch (Throwable $exception) {
            $this->assertSame(2, $stub->count);
            throw $exception;
        }
    }

    /**
     * Set up a mock container with pool factory for testing.
     */
    protected function setContainer()
    {
        $container = m::mock(Container::class);
        $factory = new PoolFactory($container);
        $container->shouldReceive('make')->with(PoolHandler::class, m::any())->andReturnUsing(function ($class, $args) use ($factory) {
            return new PoolHandler($factory, $args['option']);
        });

        ApplicationContext::setContainer($container);
    }
}
