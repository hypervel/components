<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\WorkCommandTest;

use Hypervel\Bus\Queueable;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Queue\Worker;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Queue\QueueTestCase;
use Mockery as m;
use RuntimeException;

#[WithMigration]
#[WithMigration('queue')]
/**
 * @internal
 * @coversNothing
 */
class WorkCommandTest extends QueueTestCase
{
    use DatabaseMigrations;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'database');

        parent::defineEnvironment($app);
    }

    protected function setUp(): void
    {
        $this->beforeApplicationDestroyed(function () {
            FirstJob::$ran = false;
            SecondJob::$ran = false;
            ThirdJob::$ran = false;
        });

        parent::setUp();

        $this->markTestSkippedWhenUsingSyncQueueDriver();
    }

    public function testRunningOneJob()
    {
        Queue::push(new FirstJob());
        Queue::push(new SecondJob());

        $this->artisan('queue:work', [
            '--once' => true,
            '--memory' => 1024,
        ])->assertExitCode(0);

        $this->assertSame(1, Queue::size());
        $this->assertTrue(FirstJob::$ran);
        $this->assertFalse(SecondJob::$ran);
    }

    public function testOnceDoesNotRunInMaintenanceModeUnlessForced()
    {
        Queue::push(new FirstJob());

        try {
            $this->artisan('down')->assertExitCode(0);

            $this->artisan('queue:work', [
                '--once' => true,
                '--sleep' => 0,
                '--memory' => 1024,
            ])->assertExitCode(0);

            $this->assertSame(1, Queue::size());
            $this->assertFalse(FirstJob::$ran);

            $this->artisan('queue:work', [
                '--once' => true,
                '--force' => true,
                '--sleep' => 0,
                '--memory' => 1024,
            ])->assertExitCode(0);

            $this->assertSame(0, Queue::size());
            $this->assertTrue(FirstJob::$ran);
        } finally {
            if ($this->app->isDownForMaintenance()) {
                $this->artisan('up')->assertExitCode(0);
            }
        }
    }

    public function testRunTimestampOutputWithDefaultAppTimezone()
    {
        // queue.output_timezone not set at all
        $this->travelTo(Carbon::create(2023, 1, 18, 10, 10, 11));
        Queue::push(new FirstJob());

        $this->artisan('queue:work', [
            '--once' => true,
            '--memory' => 1024,
        ])->expectsOutputToContain('2023-01-18 10:10:11')
            ->assertExitCode(0);
    }

    public function testRunTimestampOutputWithDifferentLogTimezone()
    {
        $this->app['config']->set('queue.output_timezone', 'Europe/Helsinki');

        $this->travelTo(Carbon::create(2023, 1, 18, 10, 10, 11));
        Queue::push(new FirstJob());

        $this->artisan('queue:work', [
            '--once' => true,
            '--memory' => 1024,
        ])->expectsOutputToContain('2023-01-18 12:10:11')
            ->assertExitCode(0);
    }

    public function testRunTimestampOutputWithSameAppDefaultAndQueueLogDefault()
    {
        $this->app['config']->set('queue.output_timezone', 'UTC');

        $this->travelTo(Carbon::create(2023, 1, 18, 10, 10, 11));
        Queue::push(new FirstJob());

        $this->artisan('queue:work', [
            '--once' => true,
            '--memory' => 1024,
        ])->expectsOutputToContain('2023-01-18 10:10:11')
            ->assertExitCode(0);
    }

    public function testDaemon()
    {
        Queue::push(new FirstJob());
        Queue::push(new SecondJob());

        $this->artisan('queue:work', [
            '--daemon' => true,
            '--stop-when-empty' => true,
            '--memory' => 1024,
        ])->assertExitCode(0);

        $this->assertSame(0, Queue::size());
        $this->assertTrue(FirstJob::$ran);
        $this->assertTrue(SecondJob::$ran);
    }

    public function testMemoryExceeded()
    {
        Queue::push(new FirstJob());
        Queue::push(new SecondJob());

        $this->artisan('queue:work', [
            '--daemon' => true,
            '--stop-when-empty' => true,
            '--memory' => 0.1,
        ])->assertExitCode(12);

        // Memory limit isn't checked until after the first job is attempted.
        $this->assertSame(1, Queue::size());
        $this->assertTrue(FirstJob::$ran);
        $this->assertFalse(SecondJob::$ran);
    }

    public function testMaxJobsExceeded()
    {
        $this->markTestSkippedWhenUsingQueueDrivers(['redis', 'beanstalkd']);

        Queue::push(new FirstJob());
        Queue::push(new SecondJob());

        $this->artisan('queue:work', [
            '--daemon' => true,
            '--stop-when-empty' => true,
            '--max-jobs' => 1,
        ]);

        // Memory limit isn't checked until after the first job is attempted.
        $this->assertSame(1, Queue::size());
        $this->assertTrue(FirstJob::$ran);
        $this->assertFalse(SecondJob::$ran);
    }

    public function testMaxTimeExceeded()
    {
        $this->markTestSkippedWhenUsingQueueDrivers(['redis', 'beanstalkd']);

        Queue::push(new ThirdJob());
        Queue::push(new FirstJob());
        Queue::push(new SecondJob());

        $this->artisan('queue:work', [
            '--daemon' => true,
            '--stop-when-empty' => true,
            '--max-time' => 1,
        ]);

        // Memory limit isn't checked until after the first job is attempted.
        $this->assertSame(2, Queue::size());
        $this->assertTrue(ThirdJob::$ran);
        $this->assertFalse(FirstJob::$ran);
        $this->assertFalse(SecondJob::$ran);
    }

    public function testMemoryExitCode()
    {
        $this->markTestSkippedWhenUsingQueueDrivers(['redis', 'beanstalkd']);

        Worker::$memoryExceededExitCode = 0;

        Queue::push(new FirstJob());
        Queue::push(new SecondJob());

        $this->artisan('queue:work', [
            '--memory' => 0.1,
        ])->assertExitCode(0);

        // Memory limit isn't checked until after the first job is attempted.
        $this->assertSame(1, Queue::size());
        $this->assertTrue(FirstJob::$ran);
        $this->assertFalse(SecondJob::$ran);

        Worker::$memoryExceededExitCode = null;
    }

    public function testDisableLastRestartCheck()
    {
        $this->markTestSkippedWhenUsingQueueDrivers(['redis', 'beanstalkd']);

        Worker::$restartable = false;

        $cache = m::mock(Repository::class);
        $cache->shouldNotReceive('get')->with(Worker::RESTART_SIGNAL_CACHE_KEY);
        $cache->shouldReceive('get')->with(m::pattern('/^illuminate:queue:paused:/'), false);

        $cacheManager = m::mock(CacheManager::class);
        $cacheManager->shouldReceive('driver')->andReturn($cache);
        $cacheManager->shouldReceive('store')->andReturn($cache);

        $this->app->instance('cache', $cacheManager);

        Queue::push(new FirstJob());

        $this->artisan('queue:work', [
            '--max-jobs' => 1,
            '--stop-when-empty' => true,
        ]);

        $this->assertSame(0, Queue::size());
        $this->assertTrue(FirstJob::$ran);

        Worker::$restartable = true;
    }

    public function testDisablePauseQueueCheck()
    {
        $this->markTestSkippedWhenUsingQueueDrivers(['redis', 'beanstalkd']);

        Worker::$pausable = false;

        $cache = m::mock(Repository::class);

        $cache->shouldReceive('get')->with(Worker::RESTART_SIGNAL_CACHE_KEY)->andReturn(null);
        $cache->shouldNotReceive('get')->with(m::pattern('/^illuminate:queue:paused:/'), false);

        $cacheManager = m::mock(CacheManager::class);
        $cacheManager->shouldReceive('driver')->andReturn($cache);
        $cacheManager->shouldReceive('store')->andReturn($cache);

        $this->app->instance('cache', $cacheManager);

        Queue::push(new FirstJob());

        $this->artisan('queue:work', [
            '--max-jobs' => 1,
            '--stop-when-empty' => true,
        ]);

        $this->assertSame(0, Queue::size());
        $this->assertTrue(FirstJob::$ran);

        Worker::$pausable = true;
    }

    public function testFailedJobListenerOnlyRunsOnce()
    {
        $this->markTestSkippedWhenUsingQueueDrivers(['redis', 'beanstalkd']);

        Exceptions::fake();

        Queue::push(new FirstJob());
        $this->withoutMockingConsoleOutput()->artisan('queue:work', ['--once' => true, '--sleep' => 0]);

        Queue::push(new JobWillFail());
        $this->withoutMockingConsoleOutput()->artisan('queue:work', ['--once' => true]);
        Exceptions::assertNotReported(UniqueConstraintViolationException::class);
        $this->assertSame(2, substr_count(Artisan::output(), JobWillFail::class));
    }
}

class FirstJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public static bool $ran = false;

    public function handle(): void
    {
        static::$ran = true;
    }
}

class SecondJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public static bool $ran = false;

    public function handle(): void
    {
        static::$ran = true;
    }
}

class ThirdJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public static bool $ran = false;

    public function handle(): void
    {
        sleep(1);

        static::$ran = true;
    }
}

class JobWillFail implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException();
    }
}
