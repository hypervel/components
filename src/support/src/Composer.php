<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Closure;
use Composer\Autoload\ClassLoader;
use Hypervel\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class Composer
{
    /**
     * The filesystem instance.
     */
    protected Filesystem $files;

    /**
     * The working path to regenerate from.
     */
    protected ?string $workingPath;

    /**
     * The cached Composer autoloader instance.
     *
     * Static because AOP proxy generation needs autoloader access
     * during bootstrap, before the container is available. This is
     * Hypervel-specific — Laravel doesn't manage the autoloader.
     */
    protected static ?ClassLoader $classLoader = null;

    /**
     * Create a new Composer manager instance.
     */
    public function __construct(Filesystem $files, ?string $workingPath = null)
    {
        $this->files = $files;
        $this->workingPath = $workingPath;
    }

    /**
     * Determine if the given Composer package is installed.
     *
     * @throws RuntimeException
     */
    public function hasPackage(string $package): bool
    {
        $composer = json_decode(file_get_contents($this->findComposerFile()), true);

        return array_key_exists($package, $composer['require'] ?? [])
            || array_key_exists($package, $composer['require-dev'] ?? []);
    }

    /**
     * Install the given Composer packages into the application.
     *
     * @param array<int, string> $packages
     */
    public function requirePackages(array $packages, bool $dev = false, Closure|OutputInterface|null $output = null, ?string $composerBinary = null): bool
    {
        $command = (new Collection([
            ...$this->findComposer($composerBinary),
            'require',
            ...$packages,
        ]))
            ->when($dev, function ($command) {
                $command->push('--dev');
            })->all();

        return $this->getProcess($command, ['COMPOSER_MEMORY_LIMIT' => '-1'])
            ->run(
                $output instanceof OutputInterface
                    ? function ($type, $line) use ($output) {
                        $output->write('    ' . $line);
                    } : $output
            ) === 0;
    }

    /**
     * Remove the given Composer packages from the application.
     *
     * @param array<int, string> $packages
     */
    public function removePackages(array $packages, bool $dev = false, Closure|OutputInterface|null $output = null, ?string $composerBinary = null): bool
    {
        $command = (new Collection([
            ...$this->findComposer($composerBinary),
            'remove',
            ...$packages,
        ]))
            ->when($dev, function ($command) {
                $command->push('--dev');
            })->all();

        return $this->getProcess($command, ['COMPOSER_MEMORY_LIMIT' => '-1'])
            ->run(
                $output instanceof OutputInterface
                    ? function ($type, $line) use ($output) {
                        $output->write('    ' . $line);
                    } : $output
            ) === 0;
    }

    /**
     * Modify the "composer.json" file contents using the given callback.
     *
     * @param callable(array): array $callback
     *
     * @throws RuntimeException
     */
    public function modify(callable $callback): void
    {
        $composerFile = $this->findComposerFile();

        $composer = json_decode(file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);

        file_put_contents(
            $composerFile,
            json_encode(
                call_user_func($callback, $composer),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );
    }

    /**
     * Regenerate the Composer autoloader files.
     */
    public function dumpAutoloads(string|array $extra = '', ?string $composerBinary = null): int
    {
        $extra = $extra ? (array) $extra : [];

        $command = array_merge($this->findComposer($composerBinary), ['dump-autoload'], $extra);

        return $this->getProcess($command)->run();
    }

    /**
     * Regenerate the optimized Composer autoloader files.
     */
    public function dumpOptimized(?string $composerBinary = null): int
    {
        return $this->dumpAutoloads('--optimize', $composerBinary);
    }

    /**
     * Get the Composer binary / command for the environment.
     */
    public function findComposer(?string $composerBinary = null): array
    {
        if (! is_null($composerBinary) && $this->files->exists($composerBinary)) {
            return [$this->phpBinary(), $composerBinary];
        }
        if ($this->files->exists($this->workingPath . '/composer.phar')) {
            return [$this->phpBinary(), 'composer.phar'];
        }

        return ['composer'];
    }

    /**
     * Get the path to the "composer.json" file.
     *
     * @throws RuntimeException
     */
    protected function findComposerFile(): string
    {
        $composerFile = "{$this->workingPath}/composer.json";

        if (! file_exists($composerFile)) {
            throw new RuntimeException("Unable to locate `composer.json` file at [{$this->workingPath}].");
        }

        return $composerFile;
    }

    /**
     * Get the PHP binary.
     */
    protected function phpBinary(): string
    {
        return php_binary();
    }

    /**
     * Get a new Symfony process instance.
     */
    protected function getProcess(array $command, array $env = []): Process
    {
        return (new Process($command, $this->workingPath, $env))->setTimeout(null);
    }

    /**
     * Set the working path used by the class.
     */
    public function setWorkingPath(string $path): static
    {
        $this->workingPath = realpath($path);

        return $this;
    }

    /**
     * Get the version of Composer.
     */
    public function getVersion(): ?string
    {
        $command = array_merge($this->findComposer(), ['-V', '--no-ansi']);

        $process = $this->getProcess($command);

        $process->run();

        $output = $process->getOutput();

        if (preg_match('/(\d+(\.\d+){2})/', $output, $version)) {
            return $version[1];
        }

        return explode(' ', $output)[2] ?? null;
    }

    /**
     * Get the Composer autoloader instance.
     *
     * Static because AOP proxy generation needs autoloader access
     * during bootstrap, before the container is available.
     */
    public static function getLoader(): ClassLoader
    {
        return static::$classLoader ??= static::findLoader();
    }

    /**
     * Set the Composer autoloader instance.
     */
    public static function setLoader(ClassLoader $classLoader): ClassLoader
    {
        return static::$classLoader = $classLoader;
    }

    /**
     * Find the Composer autoloader from registered autoload functions.
     */
    protected static function findLoader(): ClassLoader
    {
        $loaders = spl_autoload_functions();

        foreach ($loaders as $loader) {
            if (is_array($loader) && $loader[0] instanceof ClassLoader) {
                return $loader[0];
            }
        }

        throw new RuntimeException('Composer loader not found.');
    }

    /**
     * Flush all static state back to defaults.
     */
    public static function flushState(): void
    {
        static::$classLoader = null;
    }
}
