<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Broadcasting\BroadcastManager;
use Hypervel\Bus\Dispatcher as BusDispatcher;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Events\Dispatcher;
use Hypervel\Foundation\Application;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Queue\NullQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Queue\QueueServiceProvider;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class QueueRouteContainerTest extends TestCase
{
    #[DataProvider('routingConsumers')]
    public function testRoutesUseTheOwningContainerAndHonorReplacements(string $class): void
    {
        $global = Container::getInstance();
        $global->instance('queue.routes', $foreign = new QueueRoutes);
        $foreign->set(stdClass::class, 'foreign-queue', 'foreign-connection');
        $owner = new Container;
        $owner->instance('config', new Repository);
        $owner->instance('queue.routes', $routes = new QueueRoutes);
        $routes->set(stdClass::class, 'owner-queue', 'owner-connection');
        $consumer = $class === NullQueue::class
            ? (new NullQueue)->setContainer($owner)
            : new $class($owner);
        $job = new stdClass;

        $this->assertSame('owner-queue', $consumer->resolveQueueFromQueueRoute($job));
        $this->assertSame('owner-connection', $consumer->resolveConnectionFromQueueRoute($job));

        $owner->instance('queue.routes', $replacement = new QueueRoutes);
        $replacement->set(stdClass::class, 'replacement-queue', 'replacement-connection');

        $this->assertSame('replacement-queue', $consumer->resolveQueueFromQueueRoute($job));
        $this->assertSame('replacement-connection', $consumer->resolveConnectionFromQueueRoute($job));
    }

    /**
     * Provide the framework services that resolve queue routes.
     */
    public static function routingConsumers(): array
    {
        return [
            'queue manager' => [QueueManager::class],
            'queue' => [NullQueue::class],
            'bus' => [BusDispatcher::class],
            'events' => [Dispatcher::class],
            'broadcasts' => [BroadcastManager::class],
            'notifications' => [ChannelManager::class],
        ];
    }

    public function testRoutesPersistWithoutABindingAndRemainLocalToTheirContainer(): void
    {
        $owner = new Container;
        $manager = new QueueManager($owner);
        $manager->route(stdClass::class, 'reports');
        $manager->forward('reports', 'processing', 'redis');
        $secondManager = new QueueManager($owner);
        $otherManager = new QueueManager(new Container);
        $job = new stdClass;

        $this->assertSame('reports', $secondManager->resolveQueueFromQueueRoute($job));
        $this->assertSame('redis', $secondManager->resolveConnectionFromQueueRoute($job));
        $this->assertNull($otherManager->resolveQueueFromQueueRoute($job));
        $this->assertNull($otherManager->resolveConnectionFromQueueRoute($job));
    }

    public function testProviderPreservesRoutesRegisteredBeforeItsBinding(): void
    {
        $application = new Application;
        $manager = new QueueManager($application);
        $manager->route(stdClass::class, 'reports');

        (new QueueServiceProvider($application))->register();

        $this->assertSame('reports', $manager->resolveQueueFromQueueRoute(new stdClass));
    }
}
