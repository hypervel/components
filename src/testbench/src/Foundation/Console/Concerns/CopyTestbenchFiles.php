<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console\Concerns;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\Foundation\Console\TerminatingConsole;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\Foundation\EnvironmentFile;
use RuntimeException;

use function Hypervel\Testbench\join_paths;

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
        $sourcePath = (new EnvironmentFile($filesystem))->sourcePath($workingPath);

        $configurationFile = (new LazyCollection(static function () {
            yield 'testbench.yaml';
            yield 'testbench.yaml.example';
            yield 'testbench.yaml.dist';
        }))->map(static fn ($file) => join_paths($sourcePath, $file))
            ->filter(static fn ($file) => $filesystem->isFile($file))
            ->first();

        $testbenchFile = $app->basePath(join_paths('bootstrap', 'cache', 'testbench.yaml'));

        if ($backupExistingFile === true && $filesystem->isFile($testbenchFile)) {
            $backupFile = "{$testbenchFile}.backup";

            if (! $filesystem->copy($testbenchFile, $backupFile)) {
                throw new RuntimeException("Unable to back up Testbench configuration [{$testbenchFile}].");
            }

            TerminatingConsole::beforeWhen($resetOnTerminating, static function () use ($filesystem, $testbenchFile, $backupFile) {
                if (! $filesystem->isFile($backupFile) || ! $filesystem->move($backupFile, $testbenchFile)) {
                    throw new RuntimeException("Unable to restore Testbench configuration [{$testbenchFile}].");
                }
            });
        }

        if ($configurationFile !== null) {
            if (! $filesystem->copy($configurationFile, $testbenchFile)) {
                throw new RuntimeException("Unable to publish Testbench configuration [{$testbenchFile}].");
            }

            TerminatingConsole::beforeWhen($resetOnTerminating, static function () use ($filesystem, $testbenchFile) {
                if ($filesystem->isFile($testbenchFile) && ! $filesystem->delete($testbenchFile)) {
                    throw new RuntimeException("Unable to remove Testbench configuration [{$testbenchFile}].");
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
        $configurationFile = (new EnvironmentFile($filesystem))->packageOrSkeletonFallback(
            workingPath: $workingPath,
            appBasePath: $app->basePath(),
            filename: $this->testbenchEnvironmentFile(),
        );

        $environmentFile = $app->basePath('.env');

        if ($backupExistingFile === true && $filesystem->isFile($environmentFile)) {
            $backupFile = "{$environmentFile}.backup";

            if (! $filesystem->copy($environmentFile, $backupFile)) {
                throw new RuntimeException("Unable to back up Testbench environment [{$environmentFile}].");
            }

            TerminatingConsole::beforeWhen($resetOnTerminating, static function () use ($filesystem, $environmentFile, $backupFile) {
                if (! $filesystem->isFile($backupFile) || ! $filesystem->move($backupFile, $environmentFile)) {
                    throw new RuntimeException("Unable to restore Testbench environment [{$environmentFile}].");
                }
            });
        }

        if ($configurationFile !== null) {
            if (! $filesystem->copy($configurationFile, $environmentFile)) {
                throw new RuntimeException("Unable to publish Testbench environment [{$environmentFile}].");
            }

            TerminatingConsole::beforeWhen($resetOnTerminating, static function () use ($filesystem, $environmentFile) {
                if ($filesystem->isFile($environmentFile) && ! $filesystem->delete($environmentFile)) {
                    throw new RuntimeException("Unable to remove Testbench environment [{$environmentFile}].");
                }
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
}
