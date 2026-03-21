<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Process;

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

/**
 * @internal
 * @coversNothing
 */
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

            $this->assertArrayNotHasKey('TESTBENCH_BASE_PATH', $this->processEnvironment($serveProcess));
            $this->assertSame(BASE_PATH, $this->processEnvironment($aboutProcess)['TESTBENCH_BASE_PATH'] ?? null);
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
