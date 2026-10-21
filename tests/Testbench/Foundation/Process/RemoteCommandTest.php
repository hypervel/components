<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Process;

use Closure;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Concerns\Database\InteractsWithSqliteDatabaseFile;
use Hypervel\Testbench\Foundation\Process\ProcessDecorator;
use Hypervel\Testbench\Foundation\Process\ProcessResult;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionProperty;
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
            $this->assertSame('{"successful":true,"result":"aToyOw=="}', $process->getOutput());
            $this->assertSame(2, $result->output());
        });
    }

    #[Test]
    public function itCanReturnBinaryDataFromASerializedClosure(): void
    {
        $this->withoutSqliteDatabase(function (): void {
            $result = remote(static fn () => "binary-\xFF\x00\x8B")->mustRun();

            $this->assertSame("binary-\xFF\x00\x8B", $result->output());
        });
    }

    #[Test]
    public function itCanPassQuotedArgumentsInStringCommands(): void
    {
        $this->withoutSqliteDatabase(function (): void {
            $process = remote('about --json --only="environment"')->mustRun();

            /** @var array<string, mixed> $output */
            $output = json_decode($process->output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(['environment'], array_keys($output));
        });
    }

    #[Test]
    public function itDoesNotForwardTheParentRuntimeCopyToServeCommands(): void
    {
        $this->withoutSqliteDatabase(function (): void {
            $serveCommands = [
                'serve --help',
                '--no-ansi serve --help',
                '--env workbench serve --help',
                ['--no-ansi', 'serve', '--help'],
            ];

            // Values this process inherited from a parent remote process must be removed.
            $this->withInheritedEnvironment([
                'TESTBENCH_BASE_PATH' => BASE_PATH,
                'TESTBENCH_RUNTIME_COPY' => '(true)',
            ], function () use ($serveCommands): void {
                foreach ($serveCommands as $serveCommand) {
                    $environment = $this->processEnvironment(remote($serveCommand));
                    $description = 'Forwarded the parent runtime copy to ' . json_encode($serveCommand) . '.';

                    $this->assertFalse($environment['TESTBENCH_BASE_PATH'], $description);
                    $this->assertFalse($environment['TESTBENCH_RUNTIME_COPY'], $description);
                }
            });

            $aboutEnvironment = $this->processEnvironment(remote('about --json'));

            $this->assertSame(BASE_PATH, $aboutEnvironment['TESTBENCH_BASE_PATH'] ?? null);
            $this->assertSame('(true)', $aboutEnvironment['TESTBENCH_RUNTIME_COPY']);
        });
    }

    #[Test]
    public function itOnlyMarksABasePathItKnowsIsADisposableCopy(): void
    {
        $this->withoutSqliteDatabase(function (): void {
            $runtimePath = new ReflectionProperty(Bootstrapper::class, 'runtimePath');
            $ownedRuntimePath = $runtimePath->getValue();

            $this->withInheritedEnvironment(['TESTBENCH_RUNTIME_COPY' => '(true)'], function () use ($runtimePath, $ownedRuntimePath): void {
                // A caller-supplied path has no known origin, whatever this process inherited.
                $environment = $this->processEnvironment(remote('about --json', ['TESTBENCH_BASE_PATH' => BASE_PATH]));

                $this->assertSame(BASE_PATH, $environment['TESTBENCH_BASE_PATH']);
                $this->assertFalse($environment['TESTBENCH_RUNTIME_COPY']);

                // A base path this process neither created nor borrowed as a copy is persistent.
                $runtimePath->setValue(null, null);

                try {
                    $this->assertFalse($this->processEnvironment(remote('about --json'))['TESTBENCH_RUNTIME_COPY']);

                    // A remote child passes on the copy it borrowed from its parent.
                    $this->withInheritedEnvironment([
                        'TESTBENCH_PACKAGE_REMOTE' => '(true)',
                        'TESTBENCH_BASE_PATH' => BASE_PATH,
                    ], function (): void {
                        $this->assertSame('(true)', $this->processEnvironment(remote('about --json'))['TESTBENCH_RUNTIME_COPY']);
                    });
                } finally {
                    $runtimePath->setValue(null, $ownedRuntimePath);
                }
            });
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

            // Exercise restoration deterministically even when the child's
            // rebuilt manifest matches the captured baseline byte-for-byte.
            $probe = "<?php\n\nreturn ['__testbench_manifest_probe__' => true];\n";
            $files->replace($path, $probe);

            $this->assertSame($probe, $files->get($path));
        });
    }

    /**
     * Run the callback with the given $_SERVER values, restoring the originals afterwards.
     *
     * @param array<string, string> $values
     * @param Closure(): void $callback
     */
    private function withInheritedEnvironment(array $values, Closure $callback): void
    {
        $originals = [];

        foreach ($values as $key => $value) {
            $originals[$key] = [array_key_exists($key, $_SERVER), $_SERVER[$key] ?? null];
            $_SERVER[$key] = $value;
        }

        try {
            $callback();
        } finally {
            foreach ($originals as $key => [$existed, $original]) {
                if ($existed) {
                    $_SERVER[$key] = $original;
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }

    /**
     * Get the configured environment variables for the wrapped Symfony process.
     *
     * @return array<string, null|false|string|Stringable>
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
