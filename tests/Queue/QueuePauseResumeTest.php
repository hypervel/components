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
use Hypervel\Queue\Events\QueuesPaused;
use Hypervel\Queue\Events\QueuesResumed;
use Hypervel\Queue\QueueManager;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use RuntimeException;

class QueuePauseResumeTest extends TestCase
{
    protected QueueManager $manager;

    protected CacheRepository $cache;

    protected Dispatcher $events;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new CacheRepository(new ArrayStore);

        $this->manager = $this->createManager($this->cache);
    }

    /**
     * Create a queue manager using the given cache repository.
     */
    protected function createManager(CacheRepository $cache): QueueManager
    {
        $container = new Container;
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
        $container->instance('cache', new class($cache) {
            /**
             * Create a cache manager fixture.
             */
            public function __construct(
                private readonly CacheRepository $repository,
            ) {
            }

            /**
             * Get the cache repository.
             */
            public function store(?string $name = null): CacheRepository
            {
                return $this->repository;
            }
        });
        $container->instance('events', $this->events);
        $container->instance(DispatcherContract::class, $this->events);

        return new QueueManager($container);
    }

    public function testPauseQueueWithConnection(): void
    {
        $this->manager->pause('default', 'redis');

        $this->assertTrue($this->manager->isPaused('default', 'redis'));
    }

    public function testConnectionDefaultsToTheDefaultConnection(): void
    {
        $this->manager->pause('emails');

        $this->assertTrue($this->manager->isPaused('emails', 'redis'));
        $this->assertFalse($this->manager->isPaused('emails', 'database'));
        $this->assertTrue($this->manager->isPaused('emails'));
        $this->assertSame(['emails'], $this->manager->getPausedQueues(['default', 'emails']));

        $this->manager->resume('emails');

        $this->assertFalse($this->manager->isPaused('emails', 'redis'));

        $this->manager->pauseFor('emails', 30);

        $this->assertTrue($this->manager->isPaused('emails', 'redis'));
        $this->assertFalse($this->manager->isPaused('emails', 'database'));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());

        $this->assertFalse($this->manager->isPaused('emails', 'redis'));
    }

    public function testPauseQueueWithTTL(): void
    {
        $this->manager->pauseFor('default', 30, 'redis');

        $this->assertTrue($this->manager->isPaused('default', 'redis'));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        $this->assertFalse($this->manager->isPaused('default', 'redis'));
    }

    public function testPauseQueueIndefinitely(): void
    {
        $this->manager->pause('default', 'redis');

        $this->assertTrue($this->manager->isPaused('default', 'redis'));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addYear());
        $this->assertTrue($this->manager->isPaused('default', 'redis'));
    }

    public function testResumeQueue(): void
    {
        $this->manager->pause('default', 'redis');
        $this->assertTrue($this->manager->isPaused('default', 'redis'));

        $this->manager->resume('default', 'redis');
        $this->assertFalse($this->manager->isPaused('default', 'redis'));
    }

    public function testPausingQueueOnOneConnectionDoesNotAffectAnother(): void
    {
        $this->manager->pause('default', 'redis');

        $this->assertTrue($this->manager->isPaused('default', 'redis'));
        $this->assertFalse($this->manager->isPaused('default', 'database'));
    }

    public function testPausingDifferentQueuesOnSameConnection(): void
    {
        $this->manager->pause('emails', 'redis');
        $this->manager->pause('notifications', 'redis');

        $this->assertTrue($this->manager->isPaused('emails', 'redis'));
        $this->assertTrue($this->manager->isPaused('notifications', 'redis'));
        $this->assertFalse($this->manager->isPaused('default', 'redis'));
    }

    public function testResumingOnlyAffectsSpecificQueue(): void
    {
        $this->manager->pause('emails', 'redis');
        $this->manager->pause('notifications', 'redis');

        $this->manager->resume('emails', 'redis');

        $this->assertFalse($this->manager->isPaused('emails', 'redis'));
        $this->assertTrue($this->manager->isPaused('notifications', 'redis'));
    }

    public function testPauseDispatchesQueuePausedEvent(): void
    {
        $dispatchedEvent = null;

        $this->events->listen(QueuePaused::class, function (QueuePaused $event) use (&$dispatchedEvent): void {
            $dispatchedEvent = $event;
        });

        $this->manager->pause('default', 'redis');

        $this->assertInstanceOf(QueuePaused::class, $dispatchedEvent);
        $this->assertSame('redis', $dispatchedEvent->connection);
        $this->assertSame('default', $dispatchedEvent->queue);
        $this->assertNull($dispatchedEvent->ttl);
    }

    public function testPauseForDispatchesQueuePausedEventWithTTL(): void
    {
        $dispatchedEvent = null;

        $this->events->listen(QueuePaused::class, function (QueuePaused $event) use (&$dispatchedEvent): void {
            $dispatchedEvent = $event;
        });

        $this->manager->pauseFor('emails', 60, 'redis');

        $this->assertInstanceOf(QueuePaused::class, $dispatchedEvent);
        $this->assertSame('redis', $dispatchedEvent->connection);
        $this->assertSame('emails', $dispatchedEvent->queue);
        $this->assertSame(60, $dispatchedEvent->ttl);
    }

    public function testResumeDispatchesQueueResumedEvent(): void
    {
        $dispatchedEvent = null;

        $this->events->listen(QueueResumed::class, function (QueueResumed $event) use (&$dispatchedEvent): void {
            $dispatchedEvent = $event;
        });

        $this->manager->resume('notifications', 'database');

        $this->assertInstanceOf(QueueResumed::class, $dispatchedEvent);
        $this->assertSame('database', $dispatchedEvent->connection);
        $this->assertSame('notifications', $dispatchedEvent->queue);
    }

    public function testPassiveObserversDoNotCauseQueueStateEventsToDispatch(): void
    {
        $observed = [];
        $this->events->observe(
            [QueuePaused::class, QueueResumed::class, QueuesPaused::class, QueuesResumed::class],
            static function (string $event) use (&$observed): void {
                $observed[] = $event;
            },
        );

        $this->manager->pause('default', 'redis');
        $this->manager->pauseFor('emails', 60, 'redis');
        $this->manager->resume('default', 'redis');

        $this->manager->pauseAll();
        $this->manager->resumeAll();

        $this->assertSame([], $observed);
    }

    public function testGetPausedQueues(): void
    {
        $this->assertSame([], $this->manager->getPausedQueues(['default', 'emails'], 'redis'));

        $this->manager->pause('emails', 'redis');
        $this->manager->pause('notifications', 'redis');

        $this->assertSame(
            ['emails', 'notifications'],
            $this->manager->getPausedQueues(['default', 'emails', 'notifications'], 'redis'),
        );
    }

    public function testPauseAllPausesEveryQueueAndResumeAllResumesThem(): void
    {
        $this->manager->pauseAll();

        $this->assertTrue($this->manager->isPaused('default', 'redis'));
        $this->assertTrue($this->manager->isPaused('emails', 'database'));
        $this->assertSame(
            ['default', 'emails'],
            $this->manager->getPausedQueues(['default', 'emails'], 'redis')
        );

        $this->manager->resumeAll();

        $this->assertFalse($this->manager->isPaused('default', 'redis'));
        $this->assertSame([], $this->manager->getPausedQueues(['default', 'emails'], 'redis'));
    }

    public function testResumeAllPreservesIndividuallyPausedQueues(): void
    {
        $this->manager->pause('emails', 'redis');
        $this->manager->pauseAll();
        $this->manager->resumeAll();

        $this->assertTrue($this->manager->isPaused('emails', 'redis'));
        $this->assertFalse($this->manager->isPaused('emails', 'database'));
        $this->assertSame(['emails'], $this->manager->getPausedQueues(['default', 'emails'], 'redis'));
    }

    public function testPauseChecksDoNotBatchTheGlobalKeyWithQueueKeys(): void
    {
        $store = new class extends ArrayStore {
            /**
             * Retrieve multiple keys without crossing the global pause key's slot.
             */
            public function many(array $keys): array
            {
                if (count($keys) > 1 && in_array('illuminate:queues:paused', $keys, true)) {
                    throw new RuntimeException("CROSSSLOT Keys in request don't hash to the same slot");
                }

                return parent::many($keys);
            }
        };

        $manager = $this->createManager(new CacheRepository($store));

        $this->assertFalse($manager->isPaused('default', 'redis'));
        $this->assertSame([], $manager->getPausedQueues(['default'], 'redis'));

        $manager->pauseAll();

        $this->assertTrue($manager->isPaused('default', 'redis'));
        $this->assertSame(['default'], $manager->getPausedQueues(['default'], 'redis'));
    }

    public function testPauseAllDispatchesQueuesPausedEvent(): void
    {
        $dispatchedEvent = null;

        $this->events->listen(QueuesPaused::class, function (QueuesPaused $event) use (&$dispatchedEvent): void {
            $dispatchedEvent = $event;
        });

        $this->manager->pauseAll();

        $this->assertInstanceOf(QueuesPaused::class, $dispatchedEvent);
    }

    public function testResumeAllDispatchesQueuesResumedEvent(): void
    {
        $dispatchedEvent = null;

        $this->events->listen(QueuesResumed::class, function (QueuesResumed $event) use (&$dispatchedEvent): void {
            $dispatchedEvent = $event;
        });

        $this->manager->resumeAll();

        $this->assertInstanceOf(QueuesResumed::class, $dispatchedEvent);
    }

    public function testParsingQueueString(): void
    {
        $parser = new class {
            use ParsesQueue;

            private Container $hypervel;

            /**
             * Create the queue parser.
             */
            public function __construct()
            {
                $this->hypervel = new Container;
                $this->hypervel->instance('config', new ConfigRepository([
                    'queue' => [
                        'default' => 'redis',
                    ],
                ]));
            }

            /**
             * Parse a queue connection and name.
             */
            public function parse(string $queue): array
            {
                return $this->parseQueue($queue);
            }
        };

        $this->assertSame(['redis', 'default'], $parser->parse(''));
        $this->assertSame(['redis', '0'], $parser->parse('0'));
        $this->assertSame(['redis', 'emails'], $parser->parse('emails'));
        $this->assertSame(['database', 'notifications'], $parser->parse('database:notifications'));
        $this->assertSame(['redis', 'foo:bar'], $parser->parse('redis:foo:bar'));
    }
}
