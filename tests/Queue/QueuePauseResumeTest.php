<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Events\Dispatcher;
use Hypervel\Queue\Console\Concerns\ParsesQueue;
use Hypervel\Queue\Events\QueuePaused;
use Hypervel\Queue\Events\QueueResumed;
use Hypervel\Queue\QueueManager;
use Hypervel\Support\Carbon;
use Hypervel\Tests\TestCase;

class QueuePauseResumeTest extends TestCase
{
    protected QueueManager $manager;

    protected CacheRepository $cache;

    protected Dispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $this->cache = new CacheRepository(new ArrayStore);
        $this->events = new Dispatcher($container);

        $container->instance('config', new ConfigRepository([
            'queue' => [
                'default' => 'redis',
                'connections' => [
                    'redis' => ['driver' => 'redis'],
                    'database' => ['driver' => 'database'],
                ],
            ],
        ]));
        $container->instance('cache', new class($this->cache) {
            public function __construct(
                private readonly CacheRepository $repository,
            ) {
            }

            public function store(?string $name = null): CacheRepository
            {
                return $this->repository;
            }
        });
        $container->instance('events', $this->events);
        $container->instance(DispatcherContract::class, $this->events);

        $this->manager = new QueueManager($container);
    }

    public function testPauseQueueWithConnection()
    {
        $this->manager->pause('redis', 'default');

        $this->assertTrue($this->manager->isPaused('redis', 'default'));
    }

    public function testPauseQueueWithTTL()
    {
        Carbon::setTestNow();
        $this->manager->pauseFor('redis', 'default', 30);

        $this->assertTrue($this->manager->isPaused('redis', 'default'));

        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->assertFalse($this->manager->isPaused('redis', 'default'));
    }

    public function testPauseQueueIndefinitely()
    {
        Carbon::setTestNow();
        $this->manager->pause('redis', 'default');

        $this->assertTrue($this->manager->isPaused('redis', 'default'));

        Carbon::setTestNow(Carbon::now()->addYear());
        $this->assertTrue($this->manager->isPaused('redis', 'default'));
    }

    public function testResumeQueue()
    {
        $this->manager->pause('redis', 'default');
        $this->assertTrue($this->manager->isPaused('redis', 'default'));

        $this->manager->resume('redis', 'default');
        $this->assertFalse($this->manager->isPaused('redis', 'default'));
    }

    public function testPausingQueueOnOneConnectionDoesNotAffectAnother()
    {
        $this->manager->pause('redis', 'default');

        $this->assertTrue($this->manager->isPaused('redis', 'default'));
        $this->assertFalse($this->manager->isPaused('database', 'default'));
    }

    public function testPausingDifferentQueuesOnSameConnection()
    {
        $this->manager->pause('redis', 'emails');
        $this->manager->pause('redis', 'notifications');

        $this->assertTrue($this->manager->isPaused('redis', 'emails'));
        $this->assertTrue($this->manager->isPaused('redis', 'notifications'));
        $this->assertFalse($this->manager->isPaused('redis', 'default'));
    }

    public function testResumingOnlyAffectsSpecificQueue()
    {
        $this->manager->pause('redis', 'emails');
        $this->manager->pause('redis', 'notifications');

        $this->manager->resume('redis', 'emails');

        $this->assertFalse($this->manager->isPaused('redis', 'emails'));
        $this->assertTrue($this->manager->isPaused('redis', 'notifications'));
    }

    public function testPauseDispatchesQueuePausedEvent()
    {
        $dispatchedEvent = null;

        $this->events->listen(QueuePaused::class, function (QueuePaused $event) use (&$dispatchedEvent) {
            $dispatchedEvent = $event;
        });

        $this->manager->pause('redis', 'default');

        $this->assertInstanceOf(QueuePaused::class, $dispatchedEvent);
        $this->assertSame('redis', $dispatchedEvent->connection);
        $this->assertSame('default', $dispatchedEvent->queue);
        $this->assertNull($dispatchedEvent->ttl);
    }

    public function testPauseForDispatchesQueuePausedEventWithTTL()
    {
        $dispatchedEvent = null;

        $this->events->listen(QueuePaused::class, function (QueuePaused $event) use (&$dispatchedEvent) {
            $dispatchedEvent = $event;
        });

        $this->manager->pauseFor('redis', 'emails', 60);

        $this->assertInstanceOf(QueuePaused::class, $dispatchedEvent);
        $this->assertSame('redis', $dispatchedEvent->connection);
        $this->assertSame('emails', $dispatchedEvent->queue);
        $this->assertSame(60, $dispatchedEvent->ttl);
    }

    public function testResumeDispatchesQueueResumedEvent()
    {
        $dispatchedEvent = null;

        $this->events->listen(QueueResumed::class, function (QueueResumed $event) use (&$dispatchedEvent) {
            $dispatchedEvent = $event;
        });

        $this->manager->resume('database', 'notifications');

        $this->assertInstanceOf(QueueResumed::class, $dispatchedEvent);
        $this->assertSame('database', $dispatchedEvent->connection);
        $this->assertSame('notifications', $dispatchedEvent->queue);
    }

    public function testParsingQueueString()
    {
        $parser = new class {
            use ParsesQueue;

            private Container $hypervel;

            public function __construct()
            {
                $this->hypervel = new Container;
                $this->hypervel->instance('config', new ConfigRepository([
                    'queue' => [
                        'default' => 'redis',
                    ],
                ]));
            }

            public function parse(string $queue): array
            {
                return $this->parseQueue($queue);
            }
        };

        $this->assertSame(['redis', 'default'], $parser->parse(''));
        $this->assertSame(['redis', 'emails'], $parser->parse('emails'));
        $this->assertSame(['database', 'notifications'], $parser->parse('database:notifications'));
        $this->assertSame(['redis', 'foo:bar'], $parser->parse('redis:foo:bar'));
    }
}
