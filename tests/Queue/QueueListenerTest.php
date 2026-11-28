<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Queue\Listener;
use Hypervel\Queue\ListenerOptions;
use Hypervel\Support\Sleep;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

use function Hypervel\Support\artisan_binary;
use function Hypervel\Support\php_binary;

class QueueListenerTest extends TestCase
{
    use VerifiesDoubles;

    public function testOutputFailureStopsTheWorkerProcess(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'echo "progress"; usleep(5000000);']);
        $listener = new Listener(sys_get_temp_dir());
        $failure = new RuntimeException('Unable to write worker output.');
        $listener->setOutputHandler(static fn () => throw $failure);
        $caught = null;

        try {
            try {
                $listener->runProcess($process, 128);
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            $this->assertSame($failure, $caught);
            $this->assertFalse($process->isRunning());
        } finally {
            $process->stop(0);
        }
    }

    public function testRunProcessCallsProcess(): void
    {
        $process = Double::for(Process::class)->passthru(new Process([PHP_BINARY]));
        $process->expects('run');
        $listener = Double::for(Listener::class)->passthru(new Listener(__DIR__));
        $listener->expects('memoryExceeded')->with(1.0)->returns(false);

        $listener->runProcess($process, 1);
    }

    public function testListenerStopsWhenMemoryIsExceeded(): void
    {
        $process = Double::for(Process::class)->passthru(new Process([PHP_BINARY]));
        $process->expects('run');
        $listener = Double::for(Listener::class)->passthru(new Listener(__DIR__));
        $listener->expects('memoryExceeded')->with(1.0)->returns(true);
        $listener->expects('stop');

        $listener->runProcess($process, 1);
    }

    public function testListenRestsForFractionalSecondsBetweenWorkers(): void
    {
        Sleep::fake();
        $stop = new RuntimeException('Stop listening.');
        $runs = 0;
        $listener = Double::for(Listener::class)->passthru(new Listener(__DIR__));
        $listener->expects('runProcess')->times(2)->resolves(static function () use (&$runs, $stop): void {
            if (++$runs === 2) {
                throw $stop;
            }
        });

        try {
            $listener->listen('connection', 'queue', new ListenerOptions(rest: 0.25));
            $this->fail('The listener should have stopped.');
        } catch (RuntimeException $exception) {
            $this->assertSame($stop, $exception);
        }

        Sleep::assertSequence([Sleep::usleep(250_000)]);
    }

    public function testListenerChecksPercentageMemoryLimits(): void
    {
        $listener = new Listener(__DIR__);
        $originalLimit = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) * 4));

        try {
            $this->assertFalse($listener->memoryExceeded('50%'));
            $this->assertTrue($listener->memoryExceeded('1%'));
        } finally {
            ini_set('memory_limit', $originalLimit);
        }
    }

    #[TestWith([2])]
    #[TestWith(['60%'])]
    #[TestWith([128.5])]
    public function testMakeProcessCorrectlyFormatsCommandLine(float|string $memory): void
    {
        $listener = new Listener(__DIR__);
        $options = new ListenerOptions;
        $options->backoff = 1;
        $options->memory = $memory;
        $options->timeout = 3;
        $process = $listener->makeProcess('connection', 'queue', $options);
        $artisan = escapeshellarg(php_binary()) . ' ' . escapeshellarg(artisan_binary());

        $this->assertInstanceOf(Process::class, $process);
        $this->assertSame(__DIR__, $process->getWorkingDirectory());
        $this->assertSame(3.0, $process->getTimeout());
        $this->assertSame("{$artisan} 'queue:work' 'connection' '--once' '--name=default' '--queue=queue' '--backoff=1' '--memory={$memory}' '--sleep=3' '--tries=1'", $process->getCommandLine());
    }

    public function testMakeProcessCorrectlyFormatsCommandLineWithAnEnvironmentSpecified(): void
    {
        $listener = new Listener(__DIR__);
        $options = new ListenerOptions('default', 'test');
        $options->backoff = 1;
        $options->memory = 2;
        $options->timeout = 3;
        $process = $listener->makeProcess('connection', 'queue', $options);
        $artisan = escapeshellarg(php_binary()) . ' ' . escapeshellarg(artisan_binary());

        $this->assertInstanceOf(Process::class, $process);
        $this->assertSame(__DIR__, $process->getWorkingDirectory());
        $this->assertSame(3.0, $process->getTimeout());
        $this->assertSame("{$artisan} 'queue:work' 'connection' '--once' '--name=default' '--queue=queue' '--backoff=1' '--memory=2' '--sleep=3' '--tries=1' '--env=test'", $process->getCommandLine());
    }

    public function testMakeProcessCorrectlyFormatsCommandLineWhenTheConnectionIsNotSpecified(): void
    {
        $listener = new Listener(__DIR__);
        $options = new ListenerOptions('default', 'test');
        $options->backoff = 1;
        $options->memory = 2;
        $options->timeout = 3;
        $process = $listener->makeProcess(null, 'queue', $options);
        $artisan = escapeshellarg(php_binary()) . ' ' . escapeshellarg(artisan_binary());

        $this->assertInstanceOf(Process::class, $process);
        $this->assertSame(__DIR__, $process->getWorkingDirectory());
        $this->assertSame(3.0, $process->getTimeout());
        $this->assertSame("{$artisan} 'queue:work' '--once' '--name=default' '--queue=queue' '--backoff=1' '--memory=2' '--sleep=3' '--tries=1' '--env=test'", $process->getCommandLine());
    }

    public function testMakeProcessPassesABackoffScheduleToTheWorker(): void
    {
        $listener = new Listener(__DIR__);
        $process = $listener->makeProcess('connection', 'queue', new ListenerOptions(backoff: [1, 5, 10]));

        $this->assertStringContainsString(" '--backoff=1,5,10' ", $process->getCommandLine());
    }
}
