<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Command;
use Hypervel\Console\CommandMutex;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Contracts\Console\Isolatable;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class CommandMutexTest extends TestCase
{
    protected Command $command;

    protected CommandMutex|m\MockInterface $commandMutex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new class extends Command implements Isolatable {
            public int $ran = 0;

            public function __invoke()
            {
                ++$this->ran;
            }
        };
        $this->command->setHypervel($this->app);

        $this->commandMutex = m::mock(CommandMutex::class);

        $this->app->instance(CommandMutex::class, $this->commandMutex);
    }

    public function testCanRunIsolatedCommandIfNotBlocked()
    {
        $this->commandMutex->shouldReceive('create')
            ->andReturn(true)
            ->once();
        $this->commandMutex->shouldReceive('forget')
            ->andReturn(true)
            ->once();

        $this->runCommand();

        $this->assertEquals(1, $this->command->ran);
    }

    public function testCannotRunIsolatedCommandIfBlocked()
    {
        $this->commandMutex->shouldReceive('create')
            ->andReturn(false)
            ->once();

        $this->runCommand();

        $this->assertEquals(0, $this->command->ran);
    }

    public function testCanRunCommandAgainAfterOtherCommandFinished()
    {
        $this->commandMutex->shouldReceive('create')
            ->andReturn(true)
            ->twice();
        $this->commandMutex->shouldReceive('forget')
            ->andReturn(true)
            ->twice();

        $this->runCommand();
        $this->runCommand();

        $this->assertEquals(2, $this->command->ran);
    }

    public function testCanRunCommandAgainNonAutomated()
    {
        $this->commandMutex->shouldNotHaveBeenCalled();

        $this->runCommand(false);

        $this->assertEquals(1, $this->command->ran);
    }

    public function testCommandReleasesTheExactMutexInstanceThatItAcquired(): void
    {
        $acquiredMutex = m::mock(CommandMutex::class);
        $unusedMutex = m::mock(CommandMutex::class);
        $resolutions = 0;

        $acquiredMutex->shouldReceive('create')->once()->with($this->command)->andReturnTrue();
        $acquiredMutex->shouldReceive('forget')->once()->with($this->command)->andReturnTrue();
        $unusedMutex->shouldNotReceive('create', 'forget');

        $this->app->bind(CommandMutex::class, function () use ($acquiredMutex, $unusedMutex, &$resolutions) {
            return $resolutions++ === 0 ? $acquiredMutex : $unusedMutex;
        });

        $this->runCommand();

        $this->assertSame(1, $resolutions);
    }

    public function testAfterExecuteFailureStillReleasesMutex(): void
    {
        $this->commandMutex->shouldReceive('create')->once()->andReturnTrue();
        $this->commandMutex->shouldReceive('forget')->once()->andReturnTrue();

        $this->app->make(Dispatcher::class)->listen(
            AfterExecute::class,
            fn () => throw new RuntimeException('after execute failed')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('after execute failed');

        $this->runCommand();
    }

    public function testCommandFailureRemainsPrimaryWhenTerminalPhasesFail(): void
    {
        $command = new class extends Command implements Isolatable {
            public function __invoke(): void
            {
                throw new RuntimeException('command failed');
            }
        };
        $command->setHypervel($this->app);

        $this->commandMutex->shouldReceive('create')->once()->andReturnTrue();
        $this->commandMutex->shouldReceive('forget')->once()->andThrow(new RuntimeException('mutex release failed'));

        $this->app->make(Dispatcher::class)->listen(
            AfterExecute::class,
            fn () => throw new RuntimeException('after execute failed')
        );

        try {
            $command->run(new ArrayInput(['--isolated' => true]), new NullOutput);
            $this->fail('The command exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('command failed', $exception->getMessage());
        }
    }

    protected function runCommand(bool $withIsolated = true)
    {
        $input = new ArrayInput(['--isolated' => $withIsolated]);
        $output = new NullOutput;
        $this->command->run($input, $output);
    }
}
