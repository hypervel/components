<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Testbench\TestCase;
use Override;

abstract class QueueTestCase extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithRedis;

    /**
     * The current database driver.
     */
    protected string $driver;

    /**
     * Define the test environment.
     * @param mixed $app
     */
    protected function defineEnvironment($app): void
    {
        $this->driver = $app['config']->get('queue.default', 'sync');
    }

    #[Override]
    protected function setUp(): void
    {
        $this->afterApplicationCreated(function () {
            if ($this->getQueueDriver() === 'redis') {
                $this->setUpRedis();
            }
        });

        $this->beforeApplicationDestroyed(function () {
            if ($this->getQueueDriver() === 'redis') {
                $this->tearDownRedis();
            }
        });

        parent::setUp();
    }

    /**
     * Run queue worker command.
     */
    protected function runQueueWorkerCommand(array $options = [], int $times = 1): void
    {
        if ($this->getQueueDriver() !== 'sync' && $times > 0) {
            $count = 0;

            do {
                $this->artisan('queue:work', array_merge($options, [
                    '--memory' => 1024,
                ]))->assertSuccessful();

                ++$count;
            } while ($count < $times);
        }
    }

    /**
     * Mark test as skipped when using given queue drivers.
     */
    protected function markTestSkippedWhenUsingQueueDrivers(array $drivers): void
    {
        foreach ($drivers as $driver) {
            if ($this->getQueueDriver() === $driver) {
                $this->markTestSkipped("Unable to use `{$driver}` queue driver for the test");
            }
        }
    }

    /**
     * Mark test as skipped when using "sync" queue driver.
     */
    protected function markTestSkippedWhenUsingSyncQueueDriver(): void
    {
        $this->markTestSkippedWhenUsingQueueDrivers(['sync']);
    }

    /**
     * Get the queue driver.
     */
    protected function getQueueDriver(): string
    {
        return $this->driver;
    }
}
