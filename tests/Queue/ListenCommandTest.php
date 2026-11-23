<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Queue\Listener;
use Hypervel\Queue\ListenerOptions;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\TestWith;

class ListenCommandTest extends TestCase
{
    use VerifiesDoubles;

    #[TestWith(['60%'])]
    #[TestWith(['128.5'])]
    public function testMemoryLimitIsPassedToTheListenerUnchanged(string $memory): void
    {
        $listener = Double::for(Listener::class)->strict();
        $listener->allows('setOutputHandler');
        $listener->expects('listen')->with(Argument::any(), Argument::any(), Argument::satisfies(static fn (ListenerOptions $options): bool => $options->memory === $memory));
        $this->app->instance(Listener::class, $listener);

        $this->artisan('queue:listen', ['--memory' => $memory])->assertSuccessful();
    }

    public function testFractionalSleepAndRestSecondsAreKept(): void
    {
        $listener = Double::for(Listener::class)->strict();
        $listener->allows('setOutputHandler');
        $listener->expects('listen')->with(Argument::any(), Argument::any(), Argument::satisfies(static fn (ListenerOptions $options): bool => $options->sleep === 0.5 && $options->rest === 0.25));
        $this->app->instance(Listener::class, $listener);

        $this->artisan('queue:listen', ['--sleep' => '0.5', '--rest' => '0.25'])->assertSuccessful();
    }

    public function testBackoffScheduleIsKept(): void
    {
        $listener = Double::for(Listener::class)->strict();
        $listener->allows('setOutputHandler');
        $listener->expects('listen')->with(Argument::any(), Argument::any(), Argument::satisfies(static fn (ListenerOptions $options): bool => $options->backoff === [1, 5, 10]));
        $this->app->instance(Listener::class, $listener);

        $this->artisan('queue:listen', ['--backoff' => '1,5,10'])->assertSuccessful();
    }
}
