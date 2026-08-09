<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tinker;

use Hypervel\Console\Application as ConsoleApplication;
use Hypervel\Console\Command;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Env;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tinker\TinkerServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class TinkerCommandTest extends TestCase
{
    protected function getPackageProviders(Application $app): array
    {
        return [TinkerServiceProvider::class];
    }

    protected function defineEnvironment(Application $app): void
    {
        // Point to the real vendor directory so the classmap file is found.
        Env::getRepository()->set('COMPOSER_VENDOR_DIR', dirname(__DIR__, 2) . '/vendor');
    }

    public function testExecuteSuccess(): void
    {
        $this->artisan('tinker', ['--execute' => 'echo "hello";'])
            ->assertExitCode(0);
    }

    public function testExecuteFailure(): void
    {
        $this->artisan('tinker', ['--execute' => 'throw new \Exception("fail");'])
            ->assertExitCode(1);
    }

    public function testExecuteReturnsRequestedExitCodeWithoutRenderingAnError(): void
    {
        $this->artisan('tinker', ['--execute' => 'exit(3);'])
            ->doesntExpectOutput()
            ->assertExitCode(3);
    }

    #[DataProvider('falseyExecuteCodeProvider')]
    public function testFalseyExecuteValuesUseDirectExecution(string $code): void
    {
        $providersPath = BASE_PATH . '/bootstrap/providers.php';
        $originalProviders = file_get_contents($providersPath);

        if (! is_string($originalProviders)) {
            $this->fail('Unable to read the Testbench provider file.');
        }

        $input = new InputStream;
        $process = new Process(
            command: [PHP_BINARY, BASE_PATH . '/artisan', 'tinker', '--execute=' . $code],
            cwd: dirname(__DIR__, 2),
            env: array_merge($_ENV, [
                'COMPOSER_VENDOR_DIR' => (string) Env::get('COMPOSER_VENDOR_DIR'),
                'HYPERVEL_AUTOLOAD_PATH' => dirname(__DIR__, 2) . '/vendor/autoload.php',
            ]),
            timeout: 10,
        );
        $process->setInput($input);

        try {
            $this->assertTrue(ServiceProvider::addProviderToBootstrapFile(
                TinkerServiceProvider::class,
                $providersPath,
            ));

            $process->run();
        } catch (ProcessTimedOutException) {
            $this->fail('Falsey execute input started the interactive shell.');
        } finally {
            $input->close();
            $process->stop();
            file_put_contents($providersPath, $originalProviders);
        }

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    #[DataProvider('directExecutionOutcomeProvider')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testDirectExecutionPreservesTheSigintHandler(string $code, int $exitCode): void
    {
        $originalHandler = pcntl_signal_get_handler(SIGINT);
        $sentinelHandler = static function (): void {
        };

        pcntl_signal(SIGINT, $sentinelHandler);

        try {
            $this->artisan('tinker', ['--execute' => $code])
                ->assertExitCode($exitCode);

            $this->assertSame($sentinelHandler, pcntl_signal_get_handler(SIGINT));
        } finally {
            pcntl_signal(SIGINT, $originalHandler);
        }
    }

    public function testExecuteDoesNotChangeTheConsoleExceptionPolicy(): void
    {
        $application = $this->app->make(KernelContract::class)->getArtisan();

        $this->assertInstanceOf(ConsoleApplication::class, $application);

        $application->setCatchExceptions(true);

        $this->artisan('tinker', ['--execute' => 'echo "hello";'])
            ->assertExitCode(0);

        $this->assertTrue($application->areExceptionsCaught());
    }

    public function testDisabledConfiguredCommandsAreIgnored(): void
    {
        config()->set('tinker.commands', [DisabledTinkerCommand::class]);

        $this->artisan('tinker', ['--execute' => 'echo "hello";'])
            ->assertExitCode(0);
    }

    public function testExecuteRunsInsideCoroutine(): void
    {
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('TinkerCommandTest');
        $file = $directory . '/coroutine';

        $filesystem->deleteDirectory($directory);
        $filesystem->ensureDirectoryExists($directory);

        $code = sprintf(
            "file_put_contents('%s', \\Hypervel\\Coroutine\\Coroutine::inCoroutine() ? 'true' : 'false');",
            addslashes($file)
        );

        try {
            $this->artisan('tinker', ['--execute' => $code])
                ->assertExitCode(0);

            $this->assertSame('true', file_get_contents($file));
        } finally {
            $filesystem->deleteDirectory($directory);
        }
    }

    public static function falseyExecuteCodeProvider(): array
    {
        return [
            ['0'],
            [''],
        ];
    }

    public static function directExecutionOutcomeProvider(): array
    {
        return [
            ['echo "hello";', 0],
            ['throw new \Exception("fail");', 1],
        ];
    }
}

class DisabledTinkerCommand extends Command
{
    protected ?string $name = 'tinker:disabled';

    /**
     * Determine whether the command is enabled.
     */
    public function isEnabled(): bool
    {
        return false;
    }
}
