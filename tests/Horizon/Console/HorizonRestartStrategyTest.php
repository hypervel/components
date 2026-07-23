<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Horizon\Console\HorizonRestartStrategy;
use Hypervel\Support\DotenvManager;
use Hypervel\Support\Env;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Mockery as m;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;

class HorizonRestartStrategyTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testStartAndRestartReloadTheConfiguredEnvironmentBeforeCreatingTheProcess(): void
    {
        $tempDir = ParallelTesting::tempDir('horizon-restart-environment');
        $files = new Filesystem;
        $files->ensureDirectoryExists($tempDir);
        $environmentFile = $tempDir . '/.env.custom';
        $files->put($environmentFile, 'HORIZON_RELOAD_VALUE=initial');
        $this->app->useEnvironmentPath($tempDir);
        $this->app->loadEnvironmentFrom('.env.custom');
        DotenvManager::safeLoad([$tempDir], '.env.custom');

        $process = m::mock(Process::class);
        $process->shouldReceive('start')->twice();
        $process->shouldReceive('isTerminated')->twice()->andReturnFalse();
        $process->shouldReceive('isRunning')->once()->andReturnFalse();

        $strategy = new class($this->app, new NullOutput, null, $process) extends HorizonRestartStrategy {
            /** @var list<string> */
            public array $valuesAtProcessCreation = [];

            public function __construct(
                ApplicationContract $application,
                NullOutput $output,
                ?string $environment,
                private Process $process,
            ) {
                parent::__construct($application, $output, $environment);
            }

            protected function createProcess(): Process
            {
                $this->valuesAtProcessCreation[] = (string) env('HORIZON_RELOAD_VALUE');

                return $this->process;
            }
        };

        try {
            $files->put($environmentFile, 'HORIZON_RELOAD_VALUE=first');
            $strategy->start();

            $files->put($environmentFile, 'HORIZON_RELOAD_VALUE=second');
            $strategy->restart();

            $this->assertSame(
                ['first', 'second'],
                $strategy->valuesAtProcessCreation,
            );
        } finally {
            DotenvManager::flushState();
            Env::flushState();
            $files->deleteDirectory($tempDir);
        }
    }
}
