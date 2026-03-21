<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Foundation\Application;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Concerns\Database\InteractsWithSqliteDatabaseFile;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\remote;

/**
 * @internal
 * @coversNothing
 */
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
                '0001_01_01_000001_testbench_create_cache_table',
                '0001_01_01_000002_testbench_create_jobs_table',
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
}
