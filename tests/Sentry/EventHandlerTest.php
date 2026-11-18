<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Core\Events\OnWorkerExit;
use Hypervel\Sentry\EventHandler;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
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

class EventHandlerTest extends TestCase
{
    use VerifiesDoubles;

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

    // REMOVED: testAllMappedOctaneEventHandlersExist, EventHandler/OctaneEventsTest and OctaneIntegrationTest; Hypervel has no Octane.
    // Each request runs in its own coroutine with its own scope and transaction name (see CoroutineSafetyTest and HubTest).

    public function testWorkerExitListenerReturnsBeforeCoordinatorReleaseThenDrainsAndCloses(): void
    {
        $closed = new Channel(1);
        $transport = Double::for(HttpPoolTransport::class)->strict();
        $transport->expects('shutdown')->resolves(function () use ($closed): void {
            $closed->push(true);
        });
        $client = Double::for(Client::class)->strict();
        $client->expects('getOptions')->returns(new Options(['http_timeout' => 0.1]));
        $client->expects('flush')
            ->with(Argument::none())
            ->ordered()
            ->returns(new Result(ResultStatus::success()));
        $client->expects('flush')
            ->with(1)
            ->ordered()
            ->returns(new Result(ResultStatus::success()));
        $client->expects('getTransport')->returns($transport);
        $previousHub = SentrySdk::getCurrentHub();
        SentrySdk::setCurrentHub(new Hub($client));
        CoordinatorManager::initialize(Constants::WORKER_EXIT);
        $laterListenerRan = false;
        $this->app->make('events')->listen(OnWorkerExit::class, function () use (&$laterListenerRan): void {
            $laterListenerRan = true;
        });

        try {
            $this->dispatchHypervelEvent(new OnWorkerExit(Double::for(Server::class)->strict(), 1));

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
        $transport = Double::for(HttpPoolTransport::class)->strict();
        $transport->expects('shutdown')->resolves(function () use ($closed): void {
            $closed->push(true);
        });
        $client = Double::for(Client::class)->strict();
        $client->expects('getOptions')->returns(new Options(['http_timeout' => 0.1]));
        $client->expects('flush')
            ->with(Argument::none())
            ->ordered()
            ->returns(new Result(ResultStatus::success()));
        $client->expects('flush')
            ->with(1)
            ->ordered()
            ->throws($flushException);
        $client->expects('getTransport')
            ->returns($transport);
        $previousHub = SentrySdk::getCurrentHub();
        SentrySdk::setCurrentHub(new Hub($client));
        CoordinatorManager::initialize(Constants::WORKER_EXIT);
        $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
        $exceptionHandler->expects('report')->with(Argument::same($flushException));
        $this->app->instance(ExceptionHandlerContract::class, $exceptionHandler);

        try {
            $handler = new EventHandler($this->app, config()->array('sentry'));
            $handler->workerExit(new OnWorkerExit(Double::for(Server::class)->strict(), 1));

            CoordinatorManager::until(Constants::WORKER_EXIT)->resume();

            $this->assertTrue($closed->pop(1.0));
        } finally {
            CoordinatorManager::clear(Constants::WORKER_EXIT);
            SentrySdk::setCurrentHub($previousHub);
        }
    }

    /**
     * Assert that a handler method exists for each mapped event.
     */
    private function tryAllEventHandlerMethods(array $methods): void
    {
        $handler = new EventHandler($this->app, config()->array('sentry'));

        $methods = array_map(static function (string $method): string {
            return "{$method}Handler";
        }, array_unique(array_values($methods)));

        foreach ($methods as $handlerMethod) {
            $this->assertTrue(method_exists($handler, $handlerMethod));
        }
    }

    /**
     * Get the given event handler map from the event handler.
     */
    private function getEventHandlerMapFromEventHandler(string $eventHandlerMapName): array
    {
        $class = new ReflectionClass(EventHandler::class);

        $attributes = $class->getStaticProperties();

        return $attributes[$eventHandlerMapName];
    }
}
