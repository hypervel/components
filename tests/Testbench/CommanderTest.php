<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Concerns\Database\InteractsWithSqliteDatabaseFile;
use Hypervel\Testbench\Console\Commander;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function Hypervel\Testbench\remote;

#[RequiresOperatingSystem('Linux|Darwin')]
class CommanderTest extends TestCase
{
    use InteractsWithSqliteDatabaseFile;

    #[Test]
    public function itCanCallCommanderUsingCliAndGetCurrentVersion(): void
    {
        $this->withoutSqliteDatabase(function () {
            $process = remote(['--version', '--no-ansi']);
            $process->mustRun();

            $this->assertSame('Hypervel Framework ' . Application::VERSION . PHP_EOL, $process->getOutput());
        });
    }

    #[Test]
    public function itCanCallCommanderUsingCliAndGetCurrentEnvironment(): void
    {
        $this->withoutSqliteDatabase(function () {
            $process = remote('env --no-ansi', ['APP_ENV' => 'workbench']);
            $process->mustRun();

            $this->assertSame('INFO  The application environment is [workbench].', trim($process->getOutput()));
        });
    }

    #[Test]
    public function itCanCallCommanderUsingCliAndDiscoverPackages(): void
    {
        $this->withoutSqliteDatabase(function () {
            $process = remote('package:discover --no-ansi');
            $process->mustRun();

            $this->assertStringContainsString('INFO  Discovering packages.', $process->getOutput());
        });
    }

    #[Test]
    public function itOutputCorrectDefaults(): void
    {
        $this->withoutSqliteDatabase(function () {
            $process = remote('about --json');
            $process->mustRun();

            $output = json_decode($process->getOutput(), true);

            $this->assertSame('Testbench', $output['environment']['application_name']);
            $this->assertSame(true, $output['environment']['debug_mode']);
            $this->assertSame('testing', $output['drivers']['database']);
        });
    }

    #[Test]
    public function itOutputCorrectDefaultsWithDatabaseFile(): void
    {
        $this->withSqliteDatabase(function () {
            $process = remote('about --json');
            $process->mustRun();

            $output = json_decode($process->getOutput(), true);

            $this->assertSame('Testbench', $output['environment']['application_name']);
            $this->assertSame(true, $output['environment']['debug_mode']);
            $this->assertSame('sqlite', $output['drivers']['database']);
        });
    }

    #[Test]
    public function itOutputCorrectDefaultsWithEnvironmentOverrides(): void
    {
        $this->withSqliteDatabase(function () {
            $process = remote('about --json', [
                'APP_NAME' => 'Testbench Tests',
                'APP_DEBUG' => '(false)',
                'DB_CONNECTION' => 'testing',
            ]);
            $process->mustRun();

            $output = json_decode($process->getOutput(), true);

            $this->assertSame('Testbench Tests', $output['environment']['application_name']);
            $this->assertSame(false, $output['environment']['debug_mode']);
            $this->assertSame('testing', $output['drivers']['database']);
        });
    }

    #[Test]
    public function itCanCallCommanderUsingCliAndRunMigration(): void
    {
        $this->withSqliteDatabase(function () {
            $process = remote('migrate', [
                'DB_CONNECTION' => 'sqlite',
            ]);
            $process->mustRun();

            $this->assertSame([
                '0001_01_01_000000_testbench_create_users_table',
                '0001_01_01_000001_testbench_create_password_reset_tokens_table',
                '0001_01_01_000002_testbench_create_sessions_table',
                '0001_01_01_000003_testbench_create_cache_table',
                '0001_01_01_000004_testbench_create_cache_locks_table',
                '0001_01_01_000005_testbench_create_job_batches_table',
                '0001_01_01_000006_testbench_create_jobs_table',
                '0001_01_01_000007_testbench_create_failed_jobs_table',
                '2013_07_26_182750_create_testbench_users_table',
            ], DB::connection('sqlite')->table('migrations')->pluck('migration')->all());
        });
    }

    #[Test]
    public function itCanCallCommanderUsingCliAndRunMigrationWithoutDefaultMigration(): void
    {
        $this->withSqliteDatabase(function () {
            $process = remote('migrate', [
                'DB_CONNECTION' => 'sqlite',
                'TESTBENCH_WITHOUT_DEFAULT_MIGRATIONS' => '(true)',
                'APP_MAINTENANCE_STORE' => 'array',
                'CACHE_STORE' => 'array',
            ]);
            $process->mustRun();

            $this->assertSame([
                '2013_07_26_182750_create_testbench_users_table',
            ], DB::connection('sqlite')->table('migrations')->pluck('migration')->all());
        });
    }

    #[Test]
    public function itRendersApplicationThrowablesToTheConsoleErrorOutput(): void
    {
        $exception = new RuntimeException('Application failure');
        $output = new ConsoleOutput;
        $errorOutput = new BufferedOutput;
        $output->setErrorOutput($errorOutput);

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->expects('report')->with($exception);
        $handler->expects('renderForConsole')->with($errorOutput, $exception);
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $commander = new CommanderThrowableHarness([], __DIR__);
        $commander->useApplication($this->app);

        $this->assertSame(1, $commander->renderThrowable($output, $exception));
    }

    #[Test]
    public function itRetainsApplicationThrowableWhenReportingFails(): void
    {
        $original = new RuntimeException('Application failure');
        $reportingFailure = new RuntimeException('Reporting failure');
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->expects('report')->with($original)->andThrow($reportingFailure);
        $handler->shouldNotReceive('renderForConsole');
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $commander = new CommanderThrowableHarness([], __DIR__);
        $commander->useApplication($this->app);

        try {
            $commander->renderThrowable(new ConsoleOutput, $original);
            $this->fail('Expected exception reporting to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($reportingFailure, $exception);
            $this->assertSame($original, $exception->getPrevious());
        }
    }

    #[Test]
    public function itRendersBootstrapThrowablesToTheConsoleErrorOutput(): void
    {
        $output = new ConsoleOutput;
        $errorOutput = new BufferedOutput;
        $output->setErrorOutput($errorOutput);

        $commander = new CommanderThrowableHarness([], __DIR__);

        $this->assertSame(1, $commander->renderThrowable($output, new RuntimeException('Bootstrap failure')));
        $this->assertStringContainsString('Bootstrap failure', $errorOutput->fetch());
    }
}

final class CommanderThrowableHarness extends Commander
{
    public function useApplication(ApplicationContract $app): void
    {
        $this->app = $app;
    }

    public function renderThrowable(OutputInterface $output, Throwable $throwable): int
    {
        return $this->handleException($output, $throwable);
    }
}
