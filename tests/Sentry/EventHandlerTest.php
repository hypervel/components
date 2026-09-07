<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Core\Events\OnWorkerExit;
use Hypervel\Sentry\EventHandler;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Mockery as m;
use ReflectionClass;
use RuntimeException;
use Sentry\Client;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Swoole\Coroutine\Channel;
use Swoole\Server;

class EventHandlerTest extends SentryTestCase
{
    public function testMissingEventHandlerThrowsException(): void
    {
        $handler = new EventHandler($this->app, config()->array('sentry'));

        $this->expectException(RuntimeException::class);

        /* @noinspection PhpUndefinedMethodInspection */
        $handler->thisIsNotAHandlerAndShouldThrowAnException();
    }

    public function testAllMappedEventHandlersExist(): void
    {
        $this->tryAllEventHandlerMethods(
            $this->getEventHandlerMapFromEventHandler('eventHandlerMap')
        );
    }

    public function testAllMappedAuthEventHandlersExist(): void
    {
        $this->tryAllEventHandlerMethods(
            $this->getEventHandlerMapFromEventHandler('authEventHandlerMap')
        );
    }

    public function testWorkerExitListenerReturnsBeforeCoordinatorReleaseThenDrainsAndCloses(): void
    {
        $closed = new Channel(1);
        $transport = m::mock(HttpPoolTransport::class);
        $transport->shouldReceive('shutdown')->once()->andReturnUsing(function () use ($closed): void {
            $closed->push(true);
        });
        $client = m::mock(Client::class);
        $client->shouldReceive('getOptions')->once()->andReturn(new Options(['http_timeout' => 0.1]));
        $client->shouldReceive('flush')
            ->once()
            ->withNoArgs()
            ->ordered()
            ->andReturn(new Result(ResultStatus::success()));
        $client->shouldReceive('flush')
            ->once()
            ->with(1)
            ->ordered()
            ->andReturn(new Result(ResultStatus::success()));
        $client->shouldReceive('getTransport')->once()->andReturn($transport);
        $previousHub = SentrySdk::getCurrentHub();
        SentrySdk::setCurrentHub(new Hub($client));
        CoordinatorManager::initialize(Constants::WORKER_EXIT);
        $laterListenerRan = false;
        $this->app->make('events')->listen(OnWorkerExit::class, function () use (&$laterListenerRan): void {
            $laterListenerRan = true;
        });

        try {
            $this->dispatchHypervelEvent(new OnWorkerExit(m::mock(Server::class), 1));

            $this->assertTrue($laterListenerRan);
            $this->assertTrue($closed->isEmpty());

            CoordinatorManager::until(Constants::WORKER_EXIT)->resume();

            $this->assertTrue($closed->pop(1.0));
        } finally {
            CoordinatorManager::clear(Constants::WORKER_EXIT);
            SentrySdk::setCurrentHub($previousHub);
        }
    }

    public function testWorkerExitClosesTheTransportPoolWhenFlushFails(): void
    {
        $closed = new Channel(1);
        $flushException = new RuntimeException('Unable to flush.');
        $transport = m::mock(HttpPoolTransport::class);
        $transport->shouldReceive('shutdown')->once()->andReturnUsing(function () use ($closed): void {
            $closed->push(true);
        });
        $client = m::mock(Client::class);
        $client->shouldReceive('getOptions')->once()->andReturn(new Options(['http_timeout' => 0.1]));
        $client->shouldReceive('flush')
            ->once()
            ->withNoArgs()
            ->ordered()
            ->andReturn(new Result(ResultStatus::success()));
        $client->shouldReceive('flush')
            ->once()
            ->with(1)
            ->ordered()
            ->andThrow($flushException);
        $client->shouldReceive('getTransport')
            ->once()
            ->andReturn($transport);
        $previousHub = SentrySdk::getCurrentHub();
        SentrySdk::setCurrentHub(new Hub($client));
        CoordinatorManager::initialize(Constants::WORKER_EXIT);
        $exceptionHandler = m::mock(ExceptionHandlerContract::class);
        $exceptionHandler->shouldReceive('report')->once()->with($flushException);
        $this->app->instance(ExceptionHandlerContract::class, $exceptionHandler);

        try {
            $handler = new EventHandler($this->app, config()->array('sentry'));
            $handler->workerExit(new OnWorkerExit(m::mock(Server::class), 1));

            CoordinatorManager::until(Constants::WORKER_EXIT)->resume();

            $this->assertTrue($closed->pop(1.0));
        } finally {
            CoordinatorManager::clear(Constants::WORKER_EXIT);
            SentrySdk::setCurrentHub($previousHub);
        }
    }

    private function tryAllEventHandlerMethods(array $methods): void
    {
        $handler = new EventHandler($this->app, config()->array('sentry'));

        $methods = array_map(static function ($method) {
            return "{$method}Handler";
        }, array_unique(array_values($methods)));

        foreach ($methods as $handlerMethod) {
            $this->assertTrue(method_exists($handler, $handlerMethod));
        }
    }

    private function getEventHandlerMapFromEventHandler(string $eventHandlerMapName): array
    {
        $class = new ReflectionClass(EventHandler::class);

        $attributes = $class->getStaticProperties();

        return $attributes[$eventHandlerMapName];
    }
}
