<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher;

use Closure;
use Hypervel\Engine\Channel;
use Hypervel\Tests\TestCase;
use Hypervel\Watcher\Driver\DriverInterface;
use Hypervel\Watcher\RestartStrategy;
use Hypervel\Watcher\Watcher;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

class WatcherTest extends TestCase
{
    public function testSynchronousFinalBatchIsDrainedAndRestartedOnce(): void
    {
        $driver = new WatcherDriver(static function (Channel $channel): void {
            $channel->push('first.php');
            $channel->push('second.php');
        });
        $strategy = new WatcherRestartStrategy;
        $output = new BufferedOutput;

        (new Watcher($driver, $output, $strategy))->run();

        $this->assertSame(1, $strategy->startCount);
        $this->assertSame(1, $strategy->restartCount);
        $this->assertSame(1, $strategy->stopCount);
        $this->assertSame(1, $driver->stopCount);
        $this->assertSame(
            "File changed: first.php\nFile changed: second.php\n",
            $output->fetch(),
        );
    }

    public function testCleanEmptyDriverReturnDoesNotRestart(): void
    {
        $driver = new WatcherDriver(static function (Channel $channel): void {
        });
        $strategy = new WatcherRestartStrategy;

        (new Watcher($driver, new BufferedOutput, $strategy))->run();

        $this->assertSame(0, $strategy->restartCount);
        $this->assertSame(1, $driver->stopCount);
        $this->assertSame(1, $strategy->stopCount);
    }

    public function testDriverFailureIsPrimaryAndDoesNotDrainOrRestart(): void
    {
        $failure = new RuntimeException('driver failed');
        $driver = new WatcherDriver(static function (Channel $channel) use ($failure): never {
            $channel->push('ignored.php');

            throw $failure;
        });
        $strategy = new WatcherRestartStrategy;
        $output = new BufferedOutput;

        try {
            (new Watcher($driver, $output, $strategy))->run();
            $this->fail('Expected the watcher driver to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame('', $output->fetch());
        $this->assertSame(0, $strategy->restartCount);
        $this->assertSame(1, $driver->stopCount);
        $this->assertSame(1, $strategy->stopCount);
    }

    public function testDebouncedBatchAndFinalTailEachRestartOnce(): void
    {
        $driver = new WatcherDriver(static function (Channel $channel): void {
            $channel->push('first.php');
            usleep(10_000);
            $channel->push('tail.php');
        });
        $strategy = new WatcherRestartStrategy;
        $output = new BufferedOutput;

        (new Watcher($driver, $output, $strategy))->run();

        $contents = $output->fetch();
        $this->assertSame(2, $strategy->restartCount);
        $this->assertStringContainsString('first.php', $contents);
        $this->assertStringContainsString('tail.php', $contents);
        $this->assertSame(1, $driver->stopCount);
        $this->assertSame(1, $strategy->stopCount);
    }

    public function testRestartFailureStillStopsDriverAndStrategy(): void
    {
        $failure = new RuntimeException('restart failed');
        $driver = new WatcherDriver(static function (Channel $channel): void {
            $channel->push('changed.php');
            usleep(10_000);
        });
        $strategy = new WatcherRestartStrategy($failure);

        try {
            (new Watcher($driver, new BufferedOutput, $strategy))->run();
            $this->fail('Expected restart to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(1, $driver->stopCount);
        $this->assertSame(1, $strategy->stopCount);
    }

    public function testCleanupUnblocksADriverPushingToTheFullChangeChannel(): void
    {
        $failure = new RuntimeException('output failed');
        $driverCompleted = false;
        $driver = new WatcherDriver(static function (Channel $channel) use (&$driverCompleted): void {
            try {
                for ($index = 0; $index < 1_000; ++$index) {
                    $channel->push("changed-{$index}.php");
                }
            } finally {
                $driverCompleted = true;
            }
        });
        $output = m::mock(BufferedOutput::class);
        $output->shouldReceive('writeln')->once()->andThrow($failure);

        try {
            (new Watcher($driver, $output))->run();
            $this->fail('Expected output handling to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertTrue($driverCompleted);
        $this->assertSame(1, $driver->stopCount);
    }
}

class WatcherDriver implements DriverInterface
{
    public int $stopCount = 0;

    public function __construct(protected Closure $watch)
    {
    }

    public function watch(Channel $channel): void
    {
        ($this->watch)($channel);
    }

    public function stop(): void
    {
        ++$this->stopCount;
    }
}

class WatcherRestartStrategy implements RestartStrategy
{
    public int $startCount = 0;

    public int $restartCount = 0;

    public int $stopCount = 0;

    public function __construct(protected ?RuntimeException $restartFailure = null)
    {
    }

    public function start(): void
    {
        ++$this->startCount;
    }

    public function restart(): void
    {
        ++$this->restartCount;

        if ($this->restartFailure !== null) {
            throw $this->restartFailure;
        }
    }

    public function stop(): void
    {
        ++$this->stopCount;
    }
}
