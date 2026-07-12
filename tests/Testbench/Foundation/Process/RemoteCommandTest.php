<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Process;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\Database\InteractsWithSqliteDatabaseFile;
use Hypervel\Testbench\Foundation\Process\ProcessDecorator;
use Hypervel\Testbench\Foundation\Process\ProcessResult;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Stringable;
use Symfony\Component\Process\Process as SymfonyProcess;

use function Hypervel\Testbench\remote;

#[RequiresOperatingSystem('Linux|Darwin')]
#[WithConfig('app.key', 'SECXIvnK5r28GVIWUAxmbBSjTsmF')]
class RemoteCommandTest extends TestCase
{
    use InteractsWithSqliteDatabaseFile;

    #[Test]
    public function itCanCallRemoteAndGetCurrentVersion(): void
    {
        $this->withoutSqliteDatabase(function (): void {
            $process = remote(['--version', '--no-ansi']);
            $result = $process->mustRun();

            $this->assertInstanceOf(ProcessDecorator::class, $process);
            $this->assertInstanceOf(ProcessResult::class, $result);
            $this->assertSame('Hypervel Framework ' . Application::VERSION . PHP_EOL, $process->getOutput());
            $this->assertSame('Hypervel Framework ' . Application::VERSION . PHP_EOL, $result->output());
        });
    }

    #[Test]
    public function itCanCallRemoteUsingASerializedClosure(): void
    {
        $this->withoutSqliteDatabase(function (): void {
            $process = remote(static fn () => 1 + 1);
            $result = $process->mustRun();

            $this->assertInstanceOf(ProcessDecorator::class, $process);
            $this->assertInstanceOf(ProcessResult::class, $result);
            $this->assertSame('{"successful":true,"result":"i:2;"}', $process->getOutput());
            $this->assertSame(2, $result->output());
        });
    }

    #[Test]
    public function itDoesNotForwardTheParentRuntimeCopyToServeCommands(): void
    {
        $this->withoutSqliteDatabase(function (): void {
            $serveProcess = remote('serve --help');
            $aboutProcess = remote('about --json');

            $serveEnvironment = $this->processEnvironment($serveProcess);
            $aboutEnvironment = $this->processEnvironment($aboutProcess);

            $this->assertArrayNotHasKey('TESTBENCH_BASE_PATH', $serveEnvironment);
            $this->assertSame(BASE_PATH, $aboutEnvironment['TESTBENCH_BASE_PATH'] ?? null);
        });
    }

    #[Test]
    public function itRestoresThePackageManifestAfterRemoteCommands(): void
    {
        $this->withoutSqliteDatabase(function (): void {
            $files = new Filesystem;
            $path = $this->app->getCachedPackagesPath();
            // This must match the baseline captured by the base TestCase earlier in setUp.
            $existed = $files->isFile($path);
            $contents = $existed ? $files->get($path) : '';

            // The base TestCase registered its restoration callback during setUp,
            // so this later callback observes the already-restored manifest.
            $this->beforeApplicationDestroyed(function () use ($files, $path, $existed, $contents): void {
                if ($existed) {
                    $this->assertFileExists($path);
                    $this->assertSame($contents, $files->get($path));
                } else {
                    $this->assertFileDoesNotExist($path);
                }
            });

            // Force the child to build the manifest instead of reusing the baseline cache.
            if ($files->isFile($path)) {
                $this->assertTrue($files->delete($path));
            }

            remote('about --json')->mustRun();

            $this->assertFileExists($path);
            $this->assertNotSame($contents, $files->get($path));
        });
    }

    /**
     * Get the configured environment variables for the wrapped Symfony process.
     *
     * @return array<string, null|string|Stringable>
     */
    private function processEnvironment(ProcessDecorator $process): array
    {
        $reflection = new ReflectionClass($process);
        $property = $reflection->getProperty('process');
        /** @var SymfonyProcess $symfonyProcess */
        $symfonyProcess = $property->getValue($process);

        return $symfonyProcess->getEnv();
    }
}
