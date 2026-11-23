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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;

class HorizonRestartStrategyTest extends TestCase
{
    use VerifiesDoubles;

    #[DataProvider('environments')]
    #[RunInSeparateProcess]
    public function testStartsHorizonFromTheApplicationDirectoryWithTheConfiguredEnvironment(string $environment): void
    {
        [$files, $applicationPath, $application] = $this->createTemporaryApplication(
            'horizon-real-process',
            <<<'PHP'
<?php

file_put_contents(__DIR__ . '/result.json', json_encode([
    'working_directory' => getcwd(),
    'arguments' => $argv,
], JSON_THROW_ON_ERROR));

usleep(1_000_000);
PHP,
        );
        $strategy = new HorizonRestartStrategy($application, new NullOutput, $environment);

        try {
            $strategy->start();

            $resultPath = $applicationPath . '/result.json';
            $deadline = microtime(true) + 2;

            while (! $files->exists($resultPath) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            $result = json_decode($files->get($resultPath), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame($applicationPath, $result['working_directory']);
            $this->assertSame('artisan', basename($result['arguments'][0]));
            $this->assertSame(['horizon', '--environment=' . $environment], array_slice($result['arguments'], 1));
        } finally {
            $strategy->stop();
            $files->deleteDirectory($applicationPath);
        }
    }

    public static function environments(): array
    {
        return [
            'named environment' => ['staging'],
            'zero-named environment' => ['0'],
        ];
    }

    #[DataProvider('startupFailures')]
    #[RunInSeparateProcess]
    public function testStartupFailureIncludesAvailableProcessDetails(
        int $exitCode,
        string $errorOutput,
        string $expectedMessage,
    ): void {
        $process = Double::for(Process::class)->strict();
        // Symfony's destructor stops the process with a zero timeout.
        $process->allows('stop')->with(0.0);
        $process->expects('start');
        $process->expects('isTerminated')->returns(true);
        $process->expects('getExitCode')->returns($exitCode);
        $process->expects('getErrorOutput')->returns($errorOutput);

        $strategy = new class($this->app, new NullOutput, null, $process) extends HorizonRestartStrategy {
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
                return $this->process;
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        $strategy->start();
    }

    public static function startupFailures(): array
    {
        return [
            'stderr' => [7, "broken configuration\n", 'Horizon failed to start with exit code [7]. broken configuration'],
            'empty stderr' => [9, '', 'Horizon failed to start with exit code [9].'],
        ];
    }

    #[RunInSeparateProcess]
    public function testStartAndRestartReloadTheConfiguredEnvironmentBeforeCreatingTheProcess(): void
    {
        $tempDir = ParallelTesting::tempDir('horizon-restart-environment');
        $files = new Filesystem;
        $files->deleteDirectory($tempDir);
        $files->ensureDirectoryExists($tempDir);
        $environmentFile = $tempDir . '/.env.custom';
        $files->put($environmentFile, 'HORIZON_RELOAD_VALUE=initial');
        $this->app->useEnvironmentPath($tempDir);
        $this->app->loadEnvironmentFrom('.env.custom');
        DotenvManager::safeLoad([$tempDir], '.env.custom');

        $process = Double::for(Process::class)->strict();
        $process->allows('stop')->with(0.0);
        $process->expects('start')->times(2);
        $process->expects('isTerminated')->times(2)->returns(false);
        $process->expects('isRunning')->returns(false);

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

    /**
     * Create a temporary application containing an Artisan script.
     *
     * @return array{Filesystem, string, ApplicationContract}
     */
    private function createTemporaryApplication(string $name, string $script): array
    {
        $applicationPath = ParallelTesting::tempDir($name) . ' with spaces';
        $files = new Filesystem;
        $files->deleteDirectory($applicationPath);
        $files->ensureDirectoryExists($applicationPath);
        $files->put($applicationPath . '/.env', '');
        $files->put($applicationPath . '/artisan', $script);

        $application = Double::for(ApplicationContract::class, override: true)->strict();
        $application->expects('environmentFilePath')->returns($applicationPath . '/.env');
        $application->expects('basePath')->returns($applicationPath);

        return [$files, $applicationPath, $application->instance()];
    }
}
