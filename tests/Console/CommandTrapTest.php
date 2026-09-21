<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Command;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Process\Process;

#[RunTestsInSeparateProcesses]
class CommandTrapTest extends TestCase
{
    /** @var list<Command> */
    protected array $commands = [];

    protected ?string $state = null;

    /**
     * Remove command registrations before the test coroutine exits.
     */
    protected function tearDownInCoroutine(): void
    {
        foreach ($this->commands as $command) {
            $command->untrap();
        }
    }

    public function testTrapWhenAvailable(): void
    {
        $command = $this->createCommand();

        $command->trap(SIGWINCH, function () {
            $this->state = 'taylorotwell';
        });

        $this->handleSignal();

        $this->assertSame('taylorotwell', $this->state);
    }

    // REMOVED: The unavailable-PCNTL case; Hypervel requires Swoole and pcntl.

    public function testTrapAllowsTheCommandToFinishGracefully(): void
    {
        $process = new Process([PHP_BINARY, '-r', <<<'PHP'
            require $argv[1];

            Swoole\Coroutine\run(function (): void {
                $command = new Hypervel\Console\Command;
                $running = true;
                $command->trap(SIGTERM, function () use (&$running): void {
                    $running = false;
                });

                try {
                    posix_kill(posix_getpid(), SIGTERM);
                    while ($running) {
                        usleep(1000);
                    }
                    echo 'finished';
                } finally {
                    $command->untrap();
                }
            });
            PHP, dirname(__DIR__, 2) . '/vendor/autoload.php']);
        $process->setTimeout(5);

        try {
            $this->assertSame(0, $process->run());
            $this->assertSame('finished', $process->getOutput());
        } finally {
            $process->stop(0);
        }
    }

    public function testUntrap(): void
    {
        $command = $this->createCommand();

        $command->trap(SIGWINCH, function () {
            $this->state = 'taylorotwell';
        });

        $command->untrap();

        $this->handleSignal();

        $this->assertNull($this->state);
    }

    public function testNestedTraps(): void
    {
        $a = $this->createCommand();
        $a->trap(SIGWINCH, fn () => $this->state .= '1');

        $b = $this->createCommand();
        $b->trap(SIGWINCH, fn () => $this->state .= '2');

        $c = $this->createCommand();
        $c->trap(SIGWINCH, fn () => $this->state .= '3');

        $this->state = '';
        $this->handleSignal();
        $this->assertSame('321', $this->state);

        $c->untrap();
        $this->state = '';
        $this->handleSignal();
        $this->assertSame('21', $this->state);

        $d = $this->createCommand();
        $d->trap(SIGWINCH, fn () => $this->state .= '3');

        $this->state = '';
        $this->handleSignal();
        $this->assertSame('321', $this->state);

        $d->untrap();
        $this->state = '';
        $this->handleSignal();
        $this->assertSame('21', $this->state);

        $b->untrap();
        $this->state = '';
        $this->handleSignal();
        $this->assertSame('1', $this->state);

        $a->untrap();
        $this->state = '';
        $this->handleSignal();
        $this->assertSame('', $this->state);
    }

    public function testTrapAcceptsLazyIterableSignals(): void
    {
        $command = $this->createCommand();
        $command->trap(signals: fn () => collect([SIGWINCH]), callback: function (int $signal): void {
            $this->state = (string) $signal;
        });

        $this->handleSignal();
        $this->assertSame((string) SIGWINCH, $this->state);
    }

    public function testCoroutineExitRemovesOnlyItsCommandHandlers(): void
    {
        $outer = $this->createCommand();
        $outer->trap(SIGWINCH, fn () => $this->state .= 'outer');
        $ready = new Channel(1);
        $release = new Channel(1);
        $coroutineId = Coroutine::create(function () use ($ready, $release): void {
            $command = $this->createCommand();
            $command->trap(SIGWINCH, fn () => $this->state .= 'inner');
            $ready->push(true);
            $release->pop(1);
        });

        try {
            $this->assertTrue($ready->pop(1));
            $this->handleSignal();
            $this->assertSame('innerouter', $this->state);

            $release->push(true);
            Coroutine::join([$coroutineId], 1);
            $this->state = '';
            $this->handleSignal();
            $this->assertSame('outer', $this->state);
        } finally {
            $release->push(true, 0.001);
            Coroutine::join([$coroutineId], 1);
            $ready->close();
            $release->close();
        }
    }

    /**
     * Create a command whose traps are cleaned up after the test.
     */
    protected function createCommand(): Command
    {
        return $this->commands[] = new Command;
    }

    /**
     * Deliver a signal and allow its nonblocking callbacks to finish.
     */
    protected function handleSignal(): void
    {
        posix_kill(posix_getpid(), SIGWINCH);
        usleep(10000);
    }
}
