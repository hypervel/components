<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Queue\Listener;
use Hypervel\Queue\ListenerOptions;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class ListenCommandTest extends TestCase
{
    public function testFractionalSleepAndRestSecondsAreKept(): void
    {
        $listener = m::mock(Listener::class);
        $listener->allows('setOutputHandler');
        $listener->expects('listen')->withArgs(static fn (?string $connection, string $queue, ListenerOptions $options): bool => $options->sleep === 0.5 && $options->rest === 0.25);
        $this->app->instance(Listener::class, $listener);

        $this->artisan('queue:listen', ['--sleep' => '0.5', '--rest' => '0.25'])->assertSuccessful();
    }
}
