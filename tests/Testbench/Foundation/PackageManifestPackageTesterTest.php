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
    private string $manifestDirectory;

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifestDirectory = ParallelTesting::tempDir('PackageManifestPackageTesterTest');
        $this->fixturePath = __DIR__ . '/Fixtures/PackageManifest';

        $files = new Filesystem;
        $files->deleteDirectory($this->manifestDirectory);
        $files->ensureDirectoryExists($this->manifestDirectory);
    }

    #[Override]
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->manifestDirectory);

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

    /**
     * Build the package manifest in a fresh PHP process.
     *
     * @param array<string, false|string> $env
     * @param array<int, string> $arguments
     * @return array<string, mixed>
     */
    private function buildManifest(string $manifestName, array $env = [], array $arguments = []): array
    {
        $manifestPath = $this->manifestDirectory . '/' . $manifestName . '.php';

        $process = new Process(
            command: [
                php_binary(),
                $this->fixturePath . '/build-manifest.php',
                $manifestPath,
                ...$arguments,
            ],
            env: [
                'TESTBENCH_WORKING_PATH' => $this->fixturePath,
                ...$env,
            ],
        );

        $process->mustRun();

        /** @var array<string, mixed> $manifest */
        $manifest = require $manifestPath;

        return $manifest;
    }
}
