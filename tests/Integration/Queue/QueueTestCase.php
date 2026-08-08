<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Testbench\TestCase;

abstract class QueueTestCase extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithRedis {
        // Hypervel auto-runs these hooks for every subclass, so aliases let this base gate them by queue driver.
        setUpInteractsWithRedis as setUpRedis;
        tearDownInteractsWithRedis as tearDownRedis;
    }

    private bool $redisWasSetUp = false;

    /**
     * Set up Redis when testing the Redis queue driver.
     */
    protected function setUpInteractsWithRedis(): void
    {
        if ($this->getQueueDriver() !== 'redis') {
            return;
        }

        $this->setUpRedis();
        $this->redisWasSetUp = true;
    }

    /**
     * Tear down Redis when it was set up for this test.
     */
    protected function tearDownInteractsWithRedis(): void
    {
        if ($this->redisWasSetUp) {
            $this->tearDownRedis();
        }
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
        return $this->app->make('config')->string('queue.default');
    }
}
