<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Features;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Features\ParallelRunner;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Testbench\Fixtures\ParallelRunnerConfiguredBootstrapper;
use Hypervel\Tests\Testbench\Fixtures\ParallelRunnerConfiguredServiceProvider;
use Hypervel\Tests\Testbench\Fixtures\ParallelRunnerExcludedServiceProvider;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Process\Process;

class ParallelRunnerTest extends TestCase
{
    #[Test]
    public function itBuildsDefaultApplicationsFromConfigurationWithoutRebootstrappingTheRuntime(): void
    {
        $packagePath = $this->createPackageFixture();

        try {
            $process = new Process([
                PHP_BINARY,
                dirname(__DIR__) . '/Fixtures/parallel-runner-default.php',
            ], env: [
                'TESTBENCH_PACKAGE_TESTER' => '(true)',
                'TESTBENCH_WORKING_PATH' => $packagePath,
            ]);
            $process->setTimeout(30);
            $process->run();
            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertSame(0, $process->getExitCode(), $output);

            /** @var array<string, bool|int|string> $result */
            $result = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame('configured', $result['environment']);
            $this->assertTrue($result['provider']);
            $this->assertFalse($result['excluded_provider']);
            $this->assertTrue($result['bootstrapper']);
            $this->assertGreaterThan(0, $result['setup_callbacks']);
            $this->assertGreaterThan(0, $result['teardown_callbacks']);
            $this->assertTrue($result['runtime_reused']);
            // A second bootstrap would silently overlay the live runtime path rather than throwing.
            $this->assertTrue($result['runtime_preserved']);
        } finally {
            (new Filesystem)->deleteDirectory($packagePath);
        }
    }

    // BASE_PATH can outlive Testbench's cached configuration between tests.
    #[Test]
    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    public function itDoesNotBootstrapTestbenchForACustomApplicationResolver(): void
    {
        $packagePath = $this->temporaryDirectory('custom-resolver');
        $application = new Application($packagePath);

        try {
            Env::set('TESTBENCH_WORKING_PATH', $packagePath);
            ParallelRunner::resolveApplicationUsing(static fn (): ApplicationContract => $application);

            $runner = (new ReflectionClass(ParallelRunner::class))->newInstanceWithoutConstructor();
            $createApplication = new ReflectionMethod(ParallelRunner::class, 'createApplication');

            $this->assertFalse(defined('BASE_PATH'));
            $this->assertNull(Bootstrapper::getConfiguration());
            $this->assertSame($application, $createApplication->invoke($runner));
            $this->assertFalse(defined('BASE_PATH'));
            $this->assertNull(Bootstrapper::getConfiguration());
        } finally {
            ParallelRunner::resolveApplicationUsing(null);
            $application->flush();
            (new Filesystem)->deleteDirectory($packagePath);
        }
    }

    /**
     * Create a package fixture with all supported Testbench application extras.
     */
    protected function createPackageFixture(): string
    {
        $packagePath = $this->temporaryDirectory('default-resolver');
        $filesystem = new Filesystem;

        $filesystem->deleteDirectory($packagePath);
        $filesystem->makeDirectory($packagePath, 0700, true);
        $filesystem->put($packagePath . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
            'name' => 'hypervel/tests-parallel-runner-fixture',
            'extra' => [
                'hypervel' => [
                    'providers' => [ParallelRunnerExcludedServiceProvider::class],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $filesystem->put($packagePath . DIRECTORY_SEPARATOR . 'testbench.yaml', sprintf(
            <<<'YAML'
env:
  HYPERVEL_TEST_PARALLEL_RUNNER_ENV: configured
providers:
  - %s
bootstrappers:
  - %s
dont-discover:
  - hypervel/tests-parallel-runner-fixture
YAML,
            ParallelRunnerConfiguredServiceProvider::class,
            ParallelRunnerConfiguredBootstrapper::class,
        ));

        return $packagePath;
    }

    /**
     * Get an isolated temporary directory path.
     */
    protected function temporaryDirectory(string $suffix): string
    {
        return ParallelTesting::tempDir('TestbenchParallelRunner-' . $suffix);
    }
}
