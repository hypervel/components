<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console;

use Closure;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Console\Events\ScheduledTaskFailed;
use Hypervel\Console\Scheduling\CacheEventMutex;
use Hypervel\Console\Scheduling\CacheSchedulingMutex;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Console\Scheduling\SchedulingMutex;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Factory;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Testbench\TestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class CallbackSchedulingTest extends TestCase
{
    use RunTestsInCoroutine;

    protected array $log = [];

    protected function setUp(): void
    {
        parent::setUp();

        $cache = new class implements Factory {
            public Repository $store;

            public function __construct()
            {
                $this->store = new Repository(new ArrayStore(true));
            }

            public function store(?string $name = null): Repository
            {
                return $this->store;
            }
        };

        $container = Container::getInstance();

        $container->instance(EventMutex::class, new CacheEventMutex($cache));
        $container->instance(SchedulingMutex::class, new CacheSchedulingMutex($cache));
    }

    public function testExecutionOrder()
    {
        $this->app->make(Schedule::class)
            ->call($this->logger('call'))
            ->after($this->logger('after 1'))
            ->before($this->logger('before 1'))
            ->after($this->logger('after 2'))
            ->before($this->logger('before 2'));

        $this->artisan('schedule:run', ['--once' => true]);

        $this->assertLogged('before 1', 'before 2', 'call', 'after 1', 'after 2');
    }

    public function testCallbacksCannotRunInBackground()
    {
        $this->expectException(RuntimeException::class);

        $this->app->make(Schedule::class)
            ->call($this->logger('call'))
            ->runInBackground();
    }

    public function testExceptionHandlingInCallback()
    {
        $this->app['config']->set('logging.default', 'null');

        $event = $this->app->make(Schedule::class)
            ->call($this->logger('call'))
            ->name('test-event')
            ->withoutOverlapping();

        $event->before($this->logger('before'))->after($this->logger('after'));

        // Register a hook to validate that the mutex was initially created
        $mutexWasCreated = false;
        $event->before(function () use (&$mutexWasCreated, $event) {
            $mutexWasCreated = $event->mutex->exists($event);
        });

        // Trigger an exception in an "after" hook to test exception handling
        $event->after(function () {
            throw new RuntimeException();
        });

        // Listen for the "failed" event
        $failed = false;
        $this->app->make(Dispatcher::class)
            ->listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $failure) use (&$failed, $event) {
                if ($failure->task === $event) {
                    $failed = true;
                }
            });

        $this->artisan('schedule:run', ['--once' => true]);

        // Hooks and execution should happen in correct order
        $this->assertLogged('before', 'call', 'after');

        // Exception should have resulted in a failure event
        $this->assertTrue($failed);

        // Mutex was originally created, but since been removed (even though exception was thrown)
        $this->assertTrue($mutexWasCreated);
        $this->assertFalse($event->mutex->exists($event));
    }

    protected function logger(string $message): Closure
    {
        return function () use ($message) {
            $this->log[] = $message;
        };
    }

    protected function assertLogged(string ...$messages): void
    {
        $this->assertEquals($messages, $this->log);
    }
}
