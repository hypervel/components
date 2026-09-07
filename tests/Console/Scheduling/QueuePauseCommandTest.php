<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Queue\Events\QueuePaused;
use Hypervel\Queue\Events\QueuesPaused;
use Hypervel\Queue\Events\QueuesResumed;
use Hypervel\Queue\Worker;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class QueuePauseCommandTest extends TestCase
{
    /**
     * Configure the test environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('cache.default', 'array');
    }

    public function testDispatchesEvent(): void
    {
        Event::fake();

        $this->artisan('queue:pause default')->assertSuccessful();

        Event::assertDispatched(QueuePaused::class);
    }

    public function testPauseAllDispatchesEvent(): void
    {
        Event::fake();

        $this->artisan('queue:pause --all')->assertSuccessful();

        Event::assertDispatched(QueuesPaused::class);
    }

    public function testResumeAllDispatchesEvent(): void
    {
        Event::fake();

        $this->artisan('queue:resume --all')->assertSuccessful();

        Event::assertDispatched(QueuesResumed::class);
    }

    public function testDisabledError(): void
    {
        Event::fake();

        Worker::$pausable = false;

        $this->artisan('queue:pause default')->assertFailed();

        Event::assertNotDispatched(QueuePaused::class);
    }

    public function testContinueAliasResumesAllQueues(): void
    {
        Queue::pauseAll();
        $this->assertTrue(Queue::isPaused('redis', 'default'));

        $this->artisan('queue:continue --all')->assertSuccessful();

        $this->assertFalse(Queue::isPaused('redis', 'default'));
    }

    #[DataProvider('commandsWithoutQueue')]
    public function testQueueNameIsRequiredWithoutAll(string $command, array $arguments): void
    {
        Event::fake();
        $connection = Queue::getDefaultDriver();
        Queue::pause($connection, 'emails');

        $this->artisan($command, $arguments)
            ->expectsOutputToContain('A queue name is required unless the --all option is used.')
            ->assertFailed();

        $this->assertTrue(Queue::isPaused($connection, 'emails'));
        $this->assertFalse(Queue::isPaused($connection, 'default'));
        Event::assertNotDispatched(QueuesPaused::class);
        Event::assertNotDispatched(QueuesResumed::class);
    }

    /**
     * Provide missing and empty queue arguments for both commands.
     */
    public static function commandsWithoutQueue(): array
    {
        return [
            ['queue:pause', []],
            ['queue:pause', ['queue' => '']],
            ['queue:resume', []],
            ['queue:resume', ['queue' => '']],
        ];
    }

    public function testDisabledErrorPreventsGlobalPause(): void
    {
        Event::fake();
        Worker::$pausable = false;

        $this->artisan('queue:pause --all')
            ->expectsOutputToContain('Queue pausing is currently disabled.')
            ->assertFailed();

        $this->assertFalse(Queue::isPaused('redis', 'default'));
        Event::assertNotDispatched(QueuesPaused::class);
    }

    public function testZeroQueueNameCanBePausedAndResumed(): void
    {
        $connection = Queue::getDefaultDriver();

        $this->artisan('queue:pause', ['queue' => '0'])->assertSuccessful();

        $this->assertTrue(Queue::isPaused($connection, '0'));
        $this->assertFalse(Queue::isPaused($connection, 'default'));

        $this->artisan('queue:resume', ['queue' => '0'])->assertSuccessful();

        $this->assertFalse(Queue::isPaused($connection, '0'));
    }
}
