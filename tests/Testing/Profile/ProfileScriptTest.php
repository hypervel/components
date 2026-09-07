<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Profile;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Process\Process;

class ProfileScriptTest extends TestCase
{
    #[Test]
    public function itProfilesRawParallelTestsIncludingSetupTime(): void
    {
        $fixturePath = ParallelTesting::tempDir('ComponentsProfileScriptSuccess');
        $filesystem = new Filesystem;

        try {
            $filesystem->deleteDirectory($fixturePath);
            $filesystem->makeDirectory($fixturePath, 0700, true);
            $this->writeProfileFixture($filesystem, $fixturePath, 'Slowest', 400000, true);

            foreach (range(1, 10) as $fixtureNumber) {
                $this->writeProfileFixture(
                    $filesystem,
                    $fixturePath,
                    sprintf('Slow%02d', $fixtureNumber),
                    310000,
                    true,
                );
            }

            $this->writeProfileFixture($filesystem, $fixturePath, 'Fast', 10000, true);

            [$process, $processId] = $this->runProfiler($fixturePath);
            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertSame(0, $process->getExitCode(), $output);
            $this->assertStringContainsString('Tests taking at least 0.300 seconds (11)', $output);
            $this->assertStringContainsString('ProfileScriptSlow10FixtureTest', $output);
            $this->assertStringNotContainsString('ProfileScriptFastFixtureTest', $output);
            $this->assertSame(1, preg_match(
                '/(?<duration>\d+\.\d{3})s  ProfileScriptSlowestFixtureTest::test_profiles_setup_time/',
                $output,
                $matches,
            ));
            $this->assertGreaterThanOrEqual(0.35, (float) $matches['duration']);
            $this->assertLessThan(
                strpos($output, 'ProfileScriptSlow10FixtureTest'),
                strpos($output, 'ProfileScriptSlowestFixtureTest'),
            );
            $this->assertSame([], $this->profilePaths($processId));
        } finally {
            $filesystem->deleteDirectory($fixturePath);
        }
    }

    #[Test]
    public function itPreservesTheParallelTestExitCodeAndCleansTheReport(): void
    {
        $fixturePath = ParallelTesting::tempDir('ComponentsProfileScriptFailure');
        $filesystem = new Filesystem;

        try {
            $filesystem->deleteDirectory($fixturePath);
            $filesystem->makeDirectory($fixturePath, 0700, true);
            $this->writeProfileFixture($filesystem, $fixturePath, 'Failure', 310000, false);

            [$process, $processId] = $this->runProfiler($fixturePath);
            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertSame(1, $process->getExitCode(), $output);
            $this->assertStringContainsString('ProfileScriptFailureFixtureTest', $output);
            $this->assertStringContainsString('Tests taking at least 0.300 seconds (1)', $output);
            $this->assertSame([], $this->profilePaths($processId));
        } finally {
            $filesystem->deleteDirectory($fixturePath);
        }
    }

    #[Test]
    public function itPreservesRunnerFailuresThatProduceNoReport(): void
    {
        $fixturePath = ParallelTesting::tempDir('ComponentsProfileScriptMissingPath');
        $filesystem = new Filesystem;

        try {
            $filesystem->deleteDirectory($fixturePath);

            [$process, $processId] = $this->runProfiler($fixturePath);
            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertSame(1, $process->getExitCode(), $output);
            $this->assertStringNotContainsString('Unable to find PHPUnit profile', $output);
            $this->assertSame([], $this->profilePaths($processId));
        } finally {
            $filesystem->deleteDirectory($fixturePath);
        }
    }

    #[Test]
    public function itReportsWhenParaTestIsNotInstalled(): void
    {
        $fixturePath = ParallelTesting::tempDir('ComponentsProfileScriptMissingParallelTest');
        $filesystem = new Filesystem;

        try {
            $filesystem->deleteDirectory($fixturePath);
            $filesystem->makeDirectory($fixturePath . DIRECTORY_SEPARATOR . 'bin', 0700, true);

            [$process, $processId] = $this->runProfiler(
                $fixturePath,
                scriptPath: $this->writeProfilerWrapper($filesystem, $fixturePath),
            );
            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertSame(1, $process->getExitCode(), $output);
            $this->assertStringContainsString(
                'Install the brianium/paratest package to profile tests.',
                $output,
            );
            $this->assertSame([], $this->profilePaths($processId));
        } finally {
            $filesystem->deleteDirectory($fixturePath);
        }
    }

    #[Test]
    public function itPreservesRunnerFailuresThatProduceMalformedReports(): void
    {
        $fixturePath = ParallelTesting::tempDir('ComponentsProfileScriptMalformedReport');
        $filesystem = new Filesystem;

        try {
            $filesystem->deleteDirectory($fixturePath);
            $this->writeMalformedReportRunner($filesystem, $fixturePath, 1);

            [$process, $processId] = $this->runProfiler(
                $fixturePath,
                scriptPath: $this->writeProfilerWrapper($filesystem, $fixturePath),
            );
            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertSame(1, $process->getExitCode(), $output);
            $this->assertStringContainsString('Synthetic runner output.', $output);
            $this->assertStringNotContainsString('Unable to read PHPUnit profile', $output);
            $this->assertSame([], $this->profilePaths($processId));
        } finally {
            $filesystem->deleteDirectory($fixturePath);
        }
    }

    #[Test]
    public function itRejectsMalformedReportsFromSuccessfulRuns(): void
    {
        $fixturePath = ParallelTesting::tempDir('ComponentsProfileScriptMalformedSuccessfulReport');
        $filesystem = new Filesystem;

        try {
            $filesystem->deleteDirectory($fixturePath);
            $this->writeMalformedReportRunner($filesystem, $fixturePath, 0);

            [$process, $processId] = $this->runProfiler(
                $fixturePath,
                scriptPath: $this->writeProfilerWrapper($filesystem, $fixturePath),
            );
            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertNotSame(0, $process->getExitCode(), $output);
            $this->assertStringContainsString('Unable to read PHPUnit profile', $output);
            $this->assertSame([], $this->profilePaths($processId));
        } finally {
            $filesystem->deleteDirectory($fixturePath);
        }
    }

    #[Test]
    public function itReportsWhenNoTestsCrossTheThreshold(): void
    {
        $fixturePath = ParallelTesting::tempDir('ComponentsProfileScriptFast');
        $filesystem = new Filesystem;

        try {
            $filesystem->deleteDirectory($fixturePath);
            $filesystem->makeDirectory($fixturePath, 0700, true);
            $this->writeProfileFixture($filesystem, $fixturePath, 'Fast', 10000, true);

            [$process, $processId] = $this->runProfiler($fixturePath);
            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertSame(0, $process->getExitCode(), $output);
            $this->assertStringContainsString('No tests took at least 0.300 seconds.', $output);
            $this->assertStringNotContainsString('ProfileScriptFastFixtureTest::', $output);
            $this->assertSame([], $this->profilePaths($processId));
        } finally {
            $filesystem->deleteDirectory($fixturePath);
        }
    }

    #[Test]
    public function itPreservesEmptyTestSuiteOutcomes(): void
    {
        $fixturePath = ParallelTesting::tempDir('ComponentsProfileScriptEmptySuite');
        $filesystem = new Filesystem;

        try {
            $filesystem->deleteDirectory($fixturePath);
            $filesystem->makeDirectory($fixturePath, 0700, true);
            $this->writeProfileFixture($filesystem, $fixturePath, 'Fast', 10000, true);

            [$process, $processId] = $this->runProfiler($fixturePath, [
                '--filter=ProfileScriptMissingFixtureTest',
                '--do-not-fail-on-empty-test-suite',
            ]);
            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertSame(0, $process->getExitCode(), $output);
            $this->assertStringContainsString('No tests executed!', $output);
            $this->assertStringNotContainsString('Unable to find PHPUnit profile', $output);
            $this->assertSame([], $this->profilePaths($processId));

            [$process, $processId] = $this->runProfiler(
                $fixturePath,
                ['--filter=ProfileScriptMissingFixtureTest'],
            );
            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertNotSame(0, $process->getExitCode(), $output);
            $this->assertStringContainsString('No tests executed!', $output);
            $this->assertStringNotContainsString('Unable to find PHPUnit profile', $output);
            $this->assertSame([], $this->profilePaths($processId));
        } finally {
            $filesystem->deleteDirectory($fixturePath);
        }
    }

    #[Test]
    public function itPreservesInformationalCommandsThatProduceNoReport(): void
    {
        $fixturePath = ParallelTesting::tempDir('ComponentsProfileScriptInformation');
        $filesystem = new Filesystem;

        try {
            $filesystem->deleteDirectory($fixturePath);
            $filesystem->makeDirectory($fixturePath, 0700, true);

            foreach ([
                [['--help'], 'Usage:'],
                [['--version'], 'ParaTest v'],
                [['-hV'], 'Usage:'],
            ] as [$arguments, $expectedOutput]) {
                [$process, $processId] = $this->runProfiler($fixturePath, $arguments);
                $output = $process->getOutput() . $process->getErrorOutput();
                $message = sprintf('Failed for arguments [%s].%s%s', implode(' ', $arguments), PHP_EOL, $output);

                $this->assertSame(0, $process->getExitCode(), $message);
                $this->assertStringContainsString($expectedOutput, $output, $message);
                $this->assertStringNotContainsString('Unable to find PHPUnit profile', $output, $message);
                $this->assertSame([], $this->profilePaths($processId), $message);
            }
        } finally {
            $filesystem->deleteDirectory($fixturePath);
        }
    }

    #[Test]
    public function itRejectsCallerSuppliedProfileReportsBeforeRunningTests(): void
    {
        $fixturePath = ParallelTesting::tempDir('ComponentsProfileScriptCallerReport');
        $filesystem = new Filesystem;

        try {
            $filesystem->deleteDirectory($fixturePath);
            $filesystem->makeDirectory($fixturePath, 0700, true);
            $this->writeProfileFixture($filesystem, $fixturePath, 'Fast', 10000, true);

            foreach (['equals', 'separate'] as $syntax) {
                $customReportPath = $fixturePath . DIRECTORY_SEPARATOR . "{$syntax}-report.xml";
                $arguments = $syntax === 'equals'
                    ? ['--log-junit=' . $customReportPath]
                    : ['--log-junit', $customReportPath];

                [$process, $processId] = $this->runProfiler($fixturePath, $arguments);
                $output = $process->getOutput() . $process->getErrorOutput();
                $message = sprintf('Failed for syntax [%s].%s%s', $syntax, PHP_EOL, $output);

                $this->assertSame(1, $process->getExitCode(), $message);
                $this->assertStringContainsString(
                    'The profiler manages the --log-junit option.',
                    $output,
                    $message,
                );
                $this->assertFileDoesNotExist($customReportPath, $message);
                $this->assertSame([], $this->profilePaths($processId), $message);
            }
        } finally {
            $filesystem->deleteDirectory($fixturePath);
        }
    }

    /**
     * Write a profile script test fixture.
     */
    private function writeProfileFixture(
        Filesystem $filesystem,
        string $fixturePath,
        string $name,
        int $setupDelay,
        bool $passes,
    ): void {
        $filesystem->put(
            $fixturePath . DIRECTORY_SEPARATOR . "ProfileScript{$name}FixtureTest.php",
            str_replace(
                ['{{ name }}', '{{ setupDelay }}', '{{ assertion }}'],
                [$name, (string) $setupDelay, $passes ? 'true' : 'false'],
                <<<'PHP'
<?php

declare(strict_types=1);

use Hypervel\Tests\TestCase;

final class ProfileScript{{ name }}FixtureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        usleep({{ setupDelay }});
    }

    public function test_profiles_setup_time(): void
    {
        $this->assertTrue({{ assertion }});
    }
}
PHP
            ),
        );
    }

    /**
     * Write a ParaTest fixture that produces a malformed report.
     */
    private function writeMalformedReportRunner(
        Filesystem $filesystem,
        string $fixturePath,
        int $exitCode,
    ): void {
        $filesystem->makeDirectory($fixturePath . DIRECTORY_SEPARATOR . 'bin', 0700, true);
        $filesystem->put(
            $fixturePath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'paratest',
            str_replace('{{ exitCode }}', (string) $exitCode, <<<'PHP'
<?php

declare(strict_types=1);

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--log-junit=')) {
        file_put_contents(substr($argument, 12), '<testsuites>');
    }
}

fwrite(STDERR, "Synthetic runner output.\n");

exit({{ exitCode }});
PHP),
        );
    }

    /**
     * Write a wrapper with Composer's runtime binary variables.
     */
    private function writeProfilerWrapper(Filesystem $filesystem, string $fixturePath): string
    {
        $wrapperPath = $fixturePath . DIRECTORY_SEPARATOR . 'profile.php';

        $filesystem->put($wrapperPath, sprintf(
            <<<'PHP'
<?php

declare(strict_types=1);

$_composer_bin_dir = %s;
$_composer_autoload_path = %s;

require %s;
PHP,
            var_export($fixturePath . DIRECTORY_SEPARATOR . 'bin', true),
            var_export(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'vendor/autoload.php', true),
            var_export(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src/testing/bin/hypervel-test-profile', true),
        ));

        return $wrapperPath;
    }

    /**
     * Run the Components profile script.
     *
     * @param array<int, string> $arguments
     * @return array{Process, int}
     */
    private function runProfiler(
        string $fixturePath,
        array $arguments = [],
        ?string $scriptPath = null,
    ): array {
        $process = new Process([
            PHP_BINARY,
            $scriptPath ?? dirname(__DIR__, 3) . '/src/testing/bin/hypervel-test-profile',
            '--processes=4',
            ...$arguments,
            $fixturePath,
        ]);
        $process->setTimeout(30);
        $process->start();
        $processId = $process->getPid();

        if ($processId === null) {
            throw new RuntimeException('Unable to start the Components profile script.');
        }

        $process->wait();

        return [$process, $processId];
    }

    /**
     * Get current Components profile report paths.
     *
     * @return array<int, string>
     */
    private function profilePaths(int $processId): array
    {
        $paths = glob(
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . "hypervel-test-profile-{$processId}-*.xml",
        ) ?: [];

        sort($paths);

        return $paths;
    }
}
