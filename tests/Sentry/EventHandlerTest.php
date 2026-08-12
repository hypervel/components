<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Core\Events\OnWorkerExit;
use Hypervel\Sentry\EventHandler;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Mockery as m;
use ReflectionClass;
use RuntimeException;
use Sentry\Client;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\ResultStatus;
use Swoole\Server;

class EventHandlerTest extends SentryTestCase
{
    public function testMissingEventHandlerThrowsException(): void
    {
        $handler = new EventHandler($this->app, []);

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

    public function testWorkerExitListenerClosesTheTransportPool(): void
    {
        $client = $this->getSentryClientFromContainer();
        $this->assertInstanceOf(Client::class, $client);

        $transport = $client->getTransport();
        $this->assertInstanceOf(HttpPoolTransport::class, $transport);

        $this->dispatchHypervelEvent(new OnWorkerExit(m::mock(Server::class), 1));

        $this->assertSame(
            ResultStatus::skipped(),
            $transport->send(Event::createEvent())->getStatus(),
        );
    }

    public function testWorkerExitClosesTheTransportPoolWhenFlushFails(): void
    {
        $transport = m::mock(HttpPoolTransport::class);
        $transport->shouldReceive('shutdown')->once();
        $client = m::mock(Client::class);
        $client->shouldReceive('flush')
            ->once()
            ->with(null)
            ->andThrow(new RuntimeException('Unable to flush.'));
        $client->shouldReceive('getTransport')
            ->once()
            ->andReturn($transport);
        $previousHub = SentrySdk::getCurrentHub();
        SentrySdk::setCurrentHub(new Hub($client));

        try {
            $handler = new EventHandler($this->app, []);
            $handler->workerExit(new OnWorkerExit(m::mock(Server::class), 1));
        } finally {
            SentrySdk::setCurrentHub($previousHub);
        }
    }

    private function tryAllEventHandlerMethods(array $methods): void
    {
        $handler = new EventHandler($this->app, []);

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
