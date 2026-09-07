<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console\Concerns;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\Foundation\Console\Concerns\CopyTestbenchFiles;
use Hypervel\Testbench\Foundation\Console\TerminatingConsole;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function Hypervel\Filesystem\join_paths;

class CopyTestbenchFilesTest extends TestCase
{
    protected string $tempDir;

    protected CopyTestbenchFilesFilesystem $filesystem;

    protected Application $app;

    protected CopyTestbenchFilesHarness $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('CopyTestbenchFilesTest');
        $this->filesystem = new CopyTestbenchFilesFilesystem;
        $this->filesystem->deleteDirectory($this->tempDir);
        $this->filesystem->makeDirectory($this->tempDir, 0700, recursive: true);
        $this->filesystem->makeDirectory($this->appPath('bootstrap/cache'), 0700, recursive: true);
        $this->filesystem->makeDirectory($this->workingPath(), 0700, recursive: true);
        $this->filesystem->put($this->workingPath('testbench.yaml'), '');
        $this->app = new Application($this->appPath());
        $this->action = new CopyTestbenchFilesHarness;
    }

    protected function tearDown(): void
    {
        TerminatingConsole::flush();
        $this->filesystem->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    #[Test]
    public function itDoesNotOwnRestorationWhenConfigurationBackupFails(): void
    {
        $source = $this->workingPath('testbench.yaml');
        $destination = $this->appPath('bootstrap/cache/testbench.yaml');
        $backup = "{$destination}.backup";
        $this->filesystem->put($source, 'new');
        $this->filesystem->put($destination, 'original');
        $this->filesystem->failedCopyTargets[] = $backup;

        try {
            $this->action->copyConfiguration($this->app, $this->filesystem, $this->workingPath());
            $this->fail('Expected configuration backup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame("Unable to back up Testbench configuration [{$destination}].", $exception->getMessage());
        }

        TerminatingConsole::handle();

        $this->assertSame('original', $this->filesystem->get($destination));
        $this->assertFalse($this->filesystem->exists($backup));
    }

    #[Test]
    public function itRunsConfigurationDeletionAndRestorationAfterFailures(): void
    {
        $source = $this->workingPath('testbench.yaml');
        $destination = $this->appPath('bootstrap/cache/testbench.yaml');
        $backup = "{$destination}.backup";
        $this->filesystem->put($source, 'new');
        $this->filesystem->put($destination, 'original');

        $this->action->copyConfiguration($this->app, $this->filesystem, $this->workingPath());
        $this->filesystem->failedDeletePaths[] = $destination;
        $this->filesystem->failedMoveSources[] = $backup;

        try {
            TerminatingConsole::handle();
            $this->fail('Expected configuration cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame("Unable to remove Testbench configuration [{$destination}].", $exception->getMessage());
        }

        $this->assertContains("delete:{$destination}", $this->filesystem->operations);
        $this->assertContains("move:{$backup}:{$destination}", $this->filesystem->operations);
    }

    #[Test]
    public function itFailsWhenTheEnvironmentFileCannotBePublished(): void
    {
        $source = $this->workingPath('.env');
        $destination = $this->appPath('.env');
        $this->filesystem->put($source, 'APP_ENV=testing');
        $this->filesystem->failedCopyTargets[] = $destination;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to publish Testbench environment [{$destination}].");

        $this->action->copyEnvironment($this->app, $this->filesystem, $this->workingPath());
    }

    #[Test]
    public function itRestoresTheOriginalEnvironmentFile(): void
    {
        $source = $this->workingPath('.env');
        $destination = $this->appPath('.env');
        $backup = "{$destination}.backup";
        $this->filesystem->put($source, 'APP_ENV=testing');
        $this->filesystem->put($destination, 'APP_ENV=original');

        $this->action->copyEnvironment($this->app, $this->filesystem, $this->workingPath());

        $this->assertSame('APP_ENV=testing', $this->filesystem->get($destination));

        TerminatingConsole::handle();

        $this->assertSame('APP_ENV=original', $this->filesystem->get($destination));
        $this->assertFalse($this->filesystem->exists($backup));
    }

    private function appPath(string $path = ''): string
    {
        return join_paths($this->tempDir, 'app', $path);
    }

    private function workingPath(string $path = ''): string
    {
        return join_paths($this->tempDir, 'working', $path);
    }
}

class CopyTestbenchFilesHarness
{
    use CopyTestbenchFiles;

    public function copyConfiguration(ApplicationContract $app, Filesystem $filesystem, string $workingPath): void
    {
        $this->copyTestbenchConfigurationFile($app, $filesystem, $workingPath);
    }

    public function copyEnvironment(ApplicationContract $app, Filesystem $filesystem, string $workingPath): void
    {
        $this->copyTestbenchDotEnvFile($app, $filesystem, $workingPath);
    }
}

class CopyTestbenchFilesFilesystem extends Filesystem
{
    /** @var list<string> */
    public array $failedCopyTargets = [];

    /** @var list<string> */
    public array $failedDeletePaths = [];

    /** @var list<string> */
    public array $failedMoveSources = [];

    /** @var list<string> */
    public array $operations = [];

    public function copy(string $path, string $target): bool
    {
        $this->operations[] = "copy:{$path}:{$target}";

        return in_array($target, $this->failedCopyTargets, true)
            ? false
            : parent::copy($path, $target);
    }

    public function delete(array|string $paths): bool
    {
        if (is_string($paths)) {
            $this->operations[] = "delete:{$paths}";

            if (in_array($paths, $this->failedDeletePaths, true)) {
                return false;
            }
        }

        return parent::delete($paths);
    }

    public function move(string $path, string $target): bool
    {
        $this->operations[] = "move:{$path}:{$target}";

        return in_array($path, $this->failedMoveSources, true)
            ? false
            : parent::move($path, $target);
    }
}
