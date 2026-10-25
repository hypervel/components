<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue;

use Hypervel\Queue\Worker;
use Hypervel\Support\Facades\Cache;
use Hypervel\Testbench\TestCase;

class QueueRestartCommandTest extends TestCase
{
    public function testBroadcastsRestartSignal(): void
    {
        $this->artisan('queue:restart')
            ->expectsOutputToContain('Broadcasting queue restart signal.')
            ->assertSuccessful();

        $this->assertNotNull(Cache::get(Worker::RESTART_SIGNAL_CACHE_KEY));
    }

    public function testFailsWhenRestartingIsDisabled(): void
    {
        Worker::$restartable = false;

        $this->artisan('queue:restart')
            ->expectsOutputToContain('Queue restarting is currently disabled.')
            ->assertFailed();

        $this->assertNull(Cache::get(Worker::RESTART_SIGNAL_CACHE_KEY));
    }
}
