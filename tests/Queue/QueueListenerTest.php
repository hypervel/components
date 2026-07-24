<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Queue\Listener;
use Hypervel\Queue\ListenerOptions;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Process\Process;

class QueueListenerTest extends TestCase
{
    public function testRunProcessCallsProcess(): void
    {
        $process = m::mock(Process::class)->makePartial();
        $process->shouldReceive('run')->once();
        $listener = m::mock(Listener::class)->makePartial();
        $listener->shouldReceive('memoryExceeded')->once()->with(1)->andReturn(false);

        $listener->runProcess($process, 1);
    }

    public function testListenerStopsWhenMemoryIsExceeded(): void
    {
        $process = m::mock(Process::class)->makePartial();
        $process->shouldReceive('run')->once();
        $listener = m::mock(Listener::class)->makePartial();
        $listener->shouldReceive('memoryExceeded')->once()->with(1)->andReturn(true);
        $listener->shouldReceive('stop')->once();

        $listener->runProcess($process, 1);
    }

    public function testMakeProcessCorrectlyFormatsCommandLine(): void
    {
        $listener = new Listener(__DIR__);
        $options = new ListenerOptions;
        $options->backoff = 1;
        $options->memory = 2;
        $options->timeout = 3;
        $process = $listener->makeProcess('connection', 'queue', $options);
        $escape = '\\' === DIRECTORY_SEPARATOR ? '' : '\'';

        $this->assertInstanceOf(Process::class, $process);
        $this->assertSame(__DIR__, $process->getWorkingDirectory());
        $this->assertSame(3.0, $process->getTimeout());
        $this->assertSame($escape . PHP_BINARY . $escape . " {$escape}artisan{$escape} {$escape}queue:work{$escape} {$escape}connection{$escape} {$escape}--once{$escape} {$escape}--name=default{$escape} {$escape}--queue=queue{$escape} {$escape}--backoff=1{$escape} {$escape}--memory=2{$escape} {$escape}--sleep=3{$escape} {$escape}--tries=1{$escape}", $process->getCommandLine());
    }

    public function testMakeProcessCorrectlyFormatsCommandLineWithAnEnvironmentSpecified(): void
    {
        $listener = new Listener(__DIR__);
        $options = new ListenerOptions('default', 'test');
        $options->backoff = 1;
        $options->memory = 2;
        $options->timeout = 3;
        $process = $listener->makeProcess('connection', 'queue', $options);
        $escape = '\\' === DIRECTORY_SEPARATOR ? '' : '\'';

        $this->assertInstanceOf(Process::class, $process);
        $this->assertSame(__DIR__, $process->getWorkingDirectory());
        $this->assertSame(3.0, $process->getTimeout());
        $this->assertSame($escape . PHP_BINARY . $escape . " {$escape}artisan{$escape} {$escape}queue:work{$escape} {$escape}connection{$escape} {$escape}--once{$escape} {$escape}--name=default{$escape} {$escape}--queue=queue{$escape} {$escape}--backoff=1{$escape} {$escape}--memory=2{$escape} {$escape}--sleep=3{$escape} {$escape}--tries=1{$escape} {$escape}--env=test{$escape}", $process->getCommandLine());
    }

    public function testMakeProcessCorrectlyFormatsCommandLineWhenTheConnectionIsNotSpecified(): void
    {
        $listener = new Listener(__DIR__);
        $options = new ListenerOptions('default', 'test');
        $options->backoff = 1;
        $options->memory = 2;
        $options->timeout = 3;
        $process = $listener->makeProcess(null, 'queue', $options);
        $escape = '\\' === DIRECTORY_SEPARATOR ? '' : '\'';

        $this->assertInstanceOf(Process::class, $process);
        $this->assertSame(__DIR__, $process->getWorkingDirectory());
        $this->assertSame(3.0, $process->getTimeout());
        $this->assertSame($escape . PHP_BINARY . $escape . " {$escape}artisan{$escape} {$escape}queue:work{$escape} {$escape}--once{$escape} {$escape}--name=default{$escape} {$escape}--queue=queue{$escape} {$escape}--backoff=1{$escape} {$escape}--memory=2{$escape} {$escape}--sleep=3{$escape} {$escape}--tries=1{$escape} {$escape}--env=test{$escape}", $process->getCommandLine());
    }
}
