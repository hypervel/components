<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console\Concerns;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\Foundation\Console\TerminatingConsole;
use Hypervel\Testbench\Foundation\Env;

use function Hypervel\Testbench\join_paths;
use function Hypervel\Testbench\testbench_path;

trait CopyTestbenchFiles
{
    /**
     * Copy the "testbench.yaml" file.
     *
     * @internal
     */
    protected function copyTestbenchConfigurationFile(
        Application $app,
        Filesystem $filesystem,
        string $workingPath,
        bool $backupExistingFile = true,
        bool $resetOnTerminating = true
    ): void {
        $sourcePath = $this->resolveTestbenchSourcePath($filesystem, $workingPath);

        $configurationFile = (new LazyCollection(static function () {
            yield 'testbench.yaml';
            yield 'testbench.yaml.example';
            yield 'testbench.yaml.dist';
        }))->map(static fn ($file) => join_paths($sourcePath, $file))
            ->filter(static fn ($file) => $filesystem->isFile($file))
            ->first();

        $testbenchFile = $app->basePath(join_paths('bootstrap', 'cache', 'testbench.yaml'));

        if ($backupExistingFile === true && $filesystem->isFile($testbenchFile)) {
            $filesystem->copy($testbenchFile, "{$testbenchFile}.backup");

            TerminatingConsole::beforeWhen($resetOnTerminating, static function () use ($filesystem, $testbenchFile) {
                if ($filesystem->isFile("{$testbenchFile}.backup")) {
                    $filesystem->move("{$testbenchFile}.backup", $testbenchFile);
                }
            });
        }

        if ($configurationFile !== null) {
            $filesystem->copy($configurationFile, $testbenchFile);

            TerminatingConsole::beforeWhen($resetOnTerminating, static function () use ($filesystem, $testbenchFile) {
                if ($filesystem->isFile($testbenchFile)) {
                    $filesystem->delete($testbenchFile);
                }
            });
        }
    }

    /**
     * Copy the ".env" file.
     *
     * @internal
     */
    protected function copyTestbenchDotEnvFile(
        Application $app,
        Filesystem $filesystem,
        string $workingPath,
        bool $backupExistingFile = true,
        bool $resetOnTerminating = true
    ): void {
        $sourcePath = $this->resolveTestbenchSourcePath($filesystem, $workingPath);
        $workingPath = $filesystem->isDirectory(join_paths($sourcePath, 'workbench'))
            ? join_paths($sourcePath, 'workbench')
            : $sourcePath;

        $testbenchEnvFilename = $this->testbenchEnvironmentFile();

        $configurationFile = (new LazyCollection(static function () use ($testbenchEnvFilename) {
            $defaultTestbenchEnvFilename = '.env';

            yield $testbenchEnvFilename;
            yield "{$testbenchEnvFilename}.example";
            yield "{$testbenchEnvFilename}.dist";

            yield $defaultTestbenchEnvFilename;
            yield "{$defaultTestbenchEnvFilename}.example";
            yield "{$defaultTestbenchEnvFilename}.dist";
        }))->unique()
            ->map(static fn ($file) => join_paths($workingPath, $file))
            ->filter(static fn ($file) => $filesystem->isFile($file))
            ->first();

        if ($configurationFile === null && $filesystem->isFile($app->basePath('.env.example'))) {
            $configurationFile = $app->basePath('.env.example');
        }

        $environmentFile = $app->basePath('.env');

        if ($backupExistingFile === true && $filesystem->isFile($environmentFile)) {
            $filesystem->copy($environmentFile, "{$environmentFile}.backup");

            TerminatingConsole::beforeWhen($resetOnTerminating, static function () use ($filesystem, $environmentFile) {
                $filesystem->move("{$environmentFile}.backup", $environmentFile);
            });
        }

        if ($configurationFile !== null) {
            $filesystem->copy($configurationFile, $environmentFile);

            TerminatingConsole::beforeWhen($resetOnTerminating, static function () use ($filesystem, $environmentFile) {
                $filesystem->delete($environmentFile);
            });
        }
    }

    /**
     * Determine the Testbench's environment file.
     *
     * @internal
     */
    protected function testbenchEnvironmentFile(): string
    {
        return match (true) {
            property_exists($this, 'environmentFile') => $this->environmentFile, /* @phpstan-ignore function.alreadyNarrowedType */
            Env::has('TESTBENCH_ENVIRONMENT_FILENAME') => Env::get('TESTBENCH_ENVIRONMENT_FILENAME'),
            default => '.env',
        };
    }

    /**
     * Resolve the source path for testbench config and workbench fixtures.
     */
    protected function resolveTestbenchSourcePath(Filesystem $filesystem, string $workingPath): string
    {
        foreach (['testbench.yaml', 'testbench.yaml.example', 'testbench.yaml.dist'] as $configurationFile) {
            if ($filesystem->isFile(join_paths($workingPath, $configurationFile))) {
                return $workingPath;
            }
        }

        if ($filesystem->isDirectory(join_paths($workingPath, 'workbench'))) {
            return $workingPath;
        }

        return testbench_path();
    }
}
