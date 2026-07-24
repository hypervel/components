<?php

declare(strict_types=1);

namespace Hypervel\Tests\Events;

use Hypervel\Container\Container;
use Hypervel\Events\CallQueuedListener as HypervelCallQueuedListener;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\FakeJob;
use Hypervel\Tests\TestCase;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\Channel;

use function Hypervel\Coroutine\parallel;

class CallQueuedListenerTest extends TestCase
{
    public function testHypervelListenerToleratesUnknownPropertiesOnUnserialization()
    {
        $this->assertListenerToleratesUnknownProperties(
            HypervelCallQueuedListener::class
        );
    }

    public function testCloningCopiesObjectDataWithinTheArrayPayload(): void
    {
        $listener = new HypervelCallQueuedListener(stdClass::class, '__invoke', [
            $payload = new stdClass,
        ]);

        $clone = clone $listener;

        $this->assertNotSame($listener, $clone);
        $this->assertNotSame($payload, $clone->data[0]);
    }

    public function testConcurrentQueuedListenersReceiveTheirOwnJobInstance(): void
    {
        $container = new Container;
        $container->instance(QueuedListenerJobBarrier::class, new QueuedListenerJobBarrier);

        $first = new HypervelCallQueuedListener(JobAwareQueuedListener::class, 'handle', ['first']);
        $first->setJob($firstJob = new FakeJob);

        $second = new HypervelCallQueuedListener(JobAwareQueuedListener::class, 'handle', ['second']);
        $second->setJob($secondJob = new FakeJob);

        parallel([
            fn () => $first->handle($container),
            fn () => $second->handle($container),
        ]);

        $this->assertTrue($firstJob->isDeleted());
        $this->assertTrue($secondJob->isDeleted());
    }

    /**
     * Simulates cross-version deserialization: a job payload serialized by a
     * newer Laravel/Hypervel version (which adds extra properties to
     * CallQueuedListener) is unserialized by an older version that does not
     * declare those properties. Without #[AllowDynamicProperties], PHP 8.2+
     * raises an error on the dynamic property assignment during unserialize().
     */
    private function assertListenerToleratesUnknownProperties(string $class): void
    {
        $listener = new $class('App\Listeners\OrderShipped', 'handle', []);
        $serialized = serialize($listener);

        // Inject a synthetic property absent from the current class definition.
        $extra = 's:18:"newPropertyFromV11";s:5:"value";';
        $serialized = preg_replace_callback(
            '/^(O:\d+:"[^"]+":)(\d+):/',
            fn ($m) => $m[1] . ((int) $m[2] + 1) . ':',
            $serialized
        );
        $serialized = substr($serialized, 0, -1) . $extra . '}';

        $result = unserialize($serialized);

        $this->assertInstanceOf($class, $result);
        $this->assertSame('App\Listeners\OrderShipped', $result->class);
        $this->assertSame('value', $result->newPropertyFromV11);
    }
}

class JobAwareQueuedListener
{
    use InteractsWithQueue;

    public function __construct(
        private QueuedListenerJobBarrier $barrier
    ) {
    }

    public function handle(string $execution): void
    {
        if ($execution === 'first') {
            $this->barrier->firstReady->push(true);
            $this->barrier->waitFor($this->barrier->secondReady);
            $this->delete();
            $this->barrier->firstDeleted->push(true);

            return;
        }

        $this->barrier->waitFor($this->barrier->firstReady);
        $this->barrier->secondReady->push(true);
        $this->barrier->waitFor($this->barrier->firstDeleted);
        $this->delete();
    }
}

class QueuedListenerJobBarrier
{
    public Channel $firstReady;

    public Channel $secondReady;

    public Channel $firstDeleted;

    public function __construct()
    {
        $this->firstReady = new Channel(1);
        $this->secondReady = new Channel(1);
        $this->firstDeleted = new Channel(1);
    }

    public function waitFor(Channel $channel): void
    {
        if ($channel->pop(1.0) !== true) {
            throw new RuntimeException('Timed out while coordinating queued listener jobs.');
        }
    }
}
