<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

use function Hypervel\Support\php_binary;

class PackageManifestPackageTesterTest extends TestCase
{
    private Filesystem $filesystem;

    private string $manifestDirectory;

    private string $fixturePath;

    private string $packagePath;

    private string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->fixturePath = __DIR__ . '/Fixtures/PackageManifest';
        $this->tempDirectory = ParallelTesting::tempDir('PackageManifestPackageTesterTest');
        $this->manifestDirectory = $this->tempDirectory . '/manifests';
        $this->packagePath = $this->tempDirectory . '/package';

        $this->filesystem->deleteDirectory($this->tempDirectory);
        $this->filesystem->ensureDirectoryExists($this->manifestDirectory);
        $this->filesystem->copyDirectory($this->fixturePath, $this->packagePath);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->tempDirectory);

        parent::tearDown();
    }

    #[Test]
    public function itAddsRootMetadataWhenRunningInsidePackageTester(): void
    {
        $manifest = $this->buildManifest(
            manifestName: 'package-tester',
            env: ['TESTBENCH_PACKAGE_TESTER' => '(true)'],
        );

        $this->assertArrayHasKey('testbench/example', $manifest);
    }

    #[Test]
    public function itDoesNotAddRootMetadataOutsideCliAndPackageTesterMode(): void
    {
        $manifest = $this->buildManifest(
            manifestName: 'without-cli-or-package-tester',
            env: ['TESTBENCH_PACKAGE_TESTER' => false],
        );

        $this->assertArrayNotHasKey('testbench/example', $manifest);
    }

    #[Test]
    public function itAddsRootMetadataWhenRunningInsideTheTestbenchCli(): void
    {
        $manifest = $this->buildManifest(
            manifestName: 'testbench-cli',
            env: ['TESTBENCH_PACKAGE_TESTER' => false],
            arguments: ['--testbench-core']
        );

        $this->assertArrayHasKey('testbench/example', $manifest);
    }

    #[Test]
    public function itFailsForMalformedRootMetadataWithoutPublishingAManifest(): void
    {
        $this->filesystem->put($this->packagePath . '/composer.json', '{');

        $process = $this->runManifest(
            manifestName: 'malformed-root',
            env: ['TESTBENCH_PACKAGE_TESTER' => '(true)'],
        );

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Syntax error', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->manifestPath('malformed-root'));
    }

    #[Test]
    public function itFailsForNonArrayRootMetadataWithoutPublishingAManifest(): void
    {
        $this->filesystem->put($this->packagePath . '/composer.json', 'null');

        $process = $this->runManifest(
            manifestName: 'non-array-root',
            env: ['TESTBENCH_PACKAGE_TESTER' => '(true)'],
        );

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            "Composer metadata [{$this->packagePath}/composer.json] must contain an array.",
            $process->getErrorOutput()
        );
        $this->assertFileDoesNotExist($this->manifestPath('non-array-root'));
    }

    #[Test]
    public function itFailsForMalformedInstalledMetadataWithoutPublishingAManifest(): void
    {
        $this->filesystem->put($this->packagePath . '/vendor/composer/installed.json', '{');

        $process = $this->runManifest(
            manifestName: 'malformed-installed',
            env: ['TESTBENCH_PACKAGE_TESTER' => '(true)'],
        );

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Syntax error', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->manifestPath('malformed-installed'));
    }

    /**
     * Build the package manifest in a fresh PHP process.
     *
     * @param array<string, false|string> $env
     * @param array<int, string> $arguments
     * @return array<string, mixed>
     */
    private function buildManifest(string $manifestName, array $env = [], array $arguments = []): array
    {
        $process = $this->runManifest($manifestName, $env, $arguments);
        $manifestPath = $this->manifestPath($manifestName);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertFileExists($manifestPath);

        /** @var array<string, mixed> $manifest */
        $manifest = require $manifestPath;

        return $manifest;
    }

    /**
     * Run the package manifest builder in a fresh PHP process.
     *
     * @param array<string, false|string> $env
     * @param array<int, string> $arguments
     */
    private function runManifest(string $manifestName, array $env = [], array $arguments = []): Process
    {
        $manifestPath = $this->manifestPath($manifestName);

        $process = new Process(
            command: [
                php_binary(),
                $this->fixturePath . '/build-manifest.php',
                $manifestPath,
                ...$arguments,
            ],
            env: [
                'TESTBENCH_PACKAGE_ROOT' => $this->packagePath,
                'TESTBENCH_WORKING_PATH' => $this->packagePath,
                ...$env,
            ],
        );

        $process->run();

        return $process;
    }

    /**
     * Get the generated manifest path.
     */
    private function manifestPath(string $manifestName): string
    {
        return $this->manifestDirectory . '/' . $manifestName . '.php';
    }
}
