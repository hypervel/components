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
    public function itFailsWhenTheRunnerDoesNotWriteTheOwnedReport(): void
    {
        $fixturePath = ParallelTesting::tempDir('ComponentsProfileScriptMissingReport');
        $filesystem = new Filesystem;

        try {
            $filesystem->deleteDirectory($fixturePath);
            $filesystem->makeDirectory($fixturePath, 0700, true);
            $this->writeProfileFixture($filesystem, $fixturePath, 'Fast', 10000, true);
            $customReportPath = $fixturePath . DIRECTORY_SEPARATOR . 'custom-report.xml';

            [$process, $processId] = $this->runProfiler(
                $fixturePath,
                ['--log-junit=' . $customReportPath],
            );
            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertNotSame(0, $process->getExitCode(), $output);
            $this->assertStringContainsString('Unable to find PHPUnit profile', $output);
            $this->assertFileExists($customReportPath);
            $this->assertSame([], $this->profilePaths($processId));
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
     * Run the Components profile script.
     *
     * @param array<int, string> $arguments
     * @return array{Process, int}
     */
    private function runProfiler(string $fixturePath, array $arguments = []): array
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 3) . '/bin/profile-tests.php',
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
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . "hypervel-components-profile-{$processId}-*.xml",
        ) ?: [];

        sort($paths);

        return $paths;
    }
}
