<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Console\Commands\ScheduleRunCommand;
use Hypervel\Console\Scheduling\CallbackEvent;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Coroutine\Concurrent;
use Hypervel\Engine\Channel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Stringable;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use ReflectionMethod;
use ReflectionProperty;
use Swoole\Coroutine;

class ScheduleRunContextPropagationTest extends TestCase
{
    protected Dispatcher $dispatcher;

    protected ExceptionHandler $handler;

    protected ?string $outputFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = m::mock(Dispatcher::class);
        $this->dispatcher->shouldReceive('hasListeners')->andReturnTrue();
        $this->dispatcher->shouldReceive('dispatch');

        $this->handler = m::mock(ExceptionHandler::class);
    }

    /**
     * Remove captured process output.
     */
    protected function tearDown(): void
    {
        try {
            if ($this->outputFile !== null) {
                (new Filesystem)->delete($this->outputFile);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testBackgroundTaskReceivesParentContext()
    {
        ContextRepository::getInstance()->add('trace_id', 'parent-trace-123');

        $channel = new Channel(1);

        $event = $this->makeBackgroundEvent(function () use ($channel) {
            $channel->push(ContextRepository::getInstance()->get('trace_id'));
        });

        $this->runBackgroundEvents([$event]);

        $this->assertSame('parent-trace-123', $channel->pop(1.0));
    }

    public function testBackgroundTaskReceivesHiddenContext()
    {
        ContextRepository::getInstance()->addHidden('checkin_id', 'secret-id-456');

        $channel = new Channel(1);

        $event = $this->makeBackgroundEvent(function () use ($channel) {
            $channel->push(ContextRepository::getInstance()->getHidden('checkin_id'));
        });

        $this->runBackgroundEvents([$event]);

        $this->assertSame('secret-id-456', $channel->pop(1.0));
    }

    public function testBackgroundTaskContextDoesNotLeakBackToParent()
    {
        ContextRepository::getInstance()->add('parent_key', 'parent_value');

        $channel = new Channel(1);

        $event = $this->makeBackgroundEvent(function () use ($channel) {
            ContextRepository::getInstance()->add('parent_key', 'modified_by_child');
            ContextRepository::getInstance()->add('child_only', 'child_data');
            $channel->push(true);
        });

        $this->runBackgroundEvents([$event]);

        $channel->pop(1.0);

        $this->assertSame('parent_value', ContextRepository::getInstance()->get('parent_key'));
        $this->assertNull(ContextRepository::getInstance()->get('child_only'));
    }

    public function testBackgroundTaskDoesNotReceiveNonContextCoroutineState()
    {
        CoroutineContext::set('__request_specific', 'should-not-propagate');
        ContextRepository::getInstance()->add('should_propagate', 'yes');

        $channel = new Channel(2);

        $event = $this->makeBackgroundEvent(function () use ($channel) {
            $channel->push(CoroutineContext::get('__request_specific'));
            $channel->push(ContextRepository::getInstance()->get('should_propagate'));
        });

        $this->runBackgroundEvents([$event]);

        $this->assertNull($channel->pop(1.0));
        $this->assertSame('yes', $channel->pop(1.0));
    }

    public function testForegroundTaskReceivesIndependentCopyOfParentLogContext(): void
    {
        ContextRepository::getInstance()->add('parent_key', 'original');
        $childValues = [];

        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->andReturn(true);
        $eventMutex->shouldReceive('forget');

        $event = new CallbackEvent($eventMutex, function () use (&$childValues) {
            $context = ContextRepository::getInstance();
            $childValues['inherited'] = $context->get('parent_key');

            $context->add('parent_key', 'modified');
            $context->add('child_only', 'child');

            $childValues['modified'] = $context->get('parent_key');
            $childValues['child_only'] = $context->get('child_only');

            return 0;
        });

        $command = $this->makeCommand();
        $this->invokeRunEvents($command, [$event]);

        $this->assertSame('original', $childValues['inherited']);
        $this->assertSame('modified', $childValues['modified']);
        $this->assertSame('child', $childValues['child_only']);
        $this->assertSame('original', ContextRepository::getInstance()->get('parent_key'));
        $this->assertNull(ContextRepository::getInstance()->get('child_only'));
    }

    public function testSystemTaskReceivesContextThroughItsEnvironment(): void
    {
        ContextRepository::getInstance()
            ->add('trace_id', 'parent-trace-123')
            ->addHidden('token', 'secret');

        $context = null;
        $event = new Event(m::mock(EventMutex::class), 'printf %s "$__HYPERVEL_CONTEXT"', isSystem: true);
        $event->thenWithOutput(static function (Stringable $output) use (&$context): void {
            $context = (string) $output;
        });
        $this->outputFile = $event->output;

        $event->run($this->app);

        $this->assertSame(0, $event->exitCode());
        $this->assertSame([
            'data' => ['trace_id' => serialize('parent-trace-123')],
            'hidden' => ['token' => serialize('secret')],
        ], unserialize(base64_decode($context, true), ['allowed_classes' => false]));
    }

    /**
     * Create a background Event mock that executes a callback inside run().
     */
    protected function makeBackgroundEvent(callable $callback): Event
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->andReturn(true);
        $eventMutex->shouldReceive('forget');

        $event = m::mock(Event::class, [$eventMutex, 'test:context', null, false])->makePartial();
        $event->shouldReceive('run')->once()->andReturnUsing(function () use ($callback) {
            $callback();
        });
        $event->runInBackground();

        return $event;
    }

    /**
     * Run background events and wait for completion.
     */
    protected function runBackgroundEvents(array $events): void
    {
        $command = $this->makeCommand();
        $concurrent = new Concurrent(10);
        (new ReflectionProperty($command, 'concurrent'))->setValue($command, $concurrent);

        $this->invokeRunEvents($command, $events);

        while (! $concurrent->isEmpty()) {
            Coroutine::sleep(0.01);
        }
    }

    /**
     * Create a ScheduleRunCommand with mocked dependencies.
     */
    protected function makeCommand(): ScheduleRunCommand
    {
        $command = new ScheduleRunCommand;
        $command->setHypervel($this->app);

        (new ReflectionProperty($command, 'schedule'))->setValue($command, m::mock(Schedule::class));
        (new ReflectionProperty($command, 'dispatcher'))->setValue($command, $this->dispatcher);
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('get')
            ->byDefault()
            ->with('hypervel:schedule:paused', false)
            ->andReturnFalse();
        (new ReflectionProperty($command, 'cache'))->setValue($command, $cache);
        (new ReflectionProperty($command, 'handler'))->setValue($command, $this->handler);

        return $command;
    }

    /**
     * Invoke the protected runEvents method.
     */
    protected function invokeRunEvents(ScheduleRunCommand $command, array $events): void
    {
        $method = new ReflectionMethod($command, 'runEvents');
        $method->invoke($command, new Collection($events), CarbonImmutable::now());
    }
}
