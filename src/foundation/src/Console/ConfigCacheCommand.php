<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Arr;
use LogicException;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

#[AsCommand(name: 'config:cache')]
class ConfigCacheCommand extends Command
{
    /**
     * The console command signature.
     */
    protected ?string $signature = 'config:cache
                {--dump-to= : Internal option used to dump fresh configuration to a temporary file}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a cache file for faster configuration loading';

    /**
     * Create a new config cache command instance.
     */
    public function __construct(
        protected Filesystem $files,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * Uses a subprocess to build the config cache. Bootstrapping a fresh
     * application in-process would overwrite process-global state (Container
     * singleton, Facade caches) — unsafe in Swoole's long-lived workers and
     * in test suites where multiple commands share a process. The subprocess
     * boots clean, dumps the config snapshot to a temp file, and exits
     * without affecting the parent process.
     *
     * @throws LogicException
     */
    public function handle(): int
    {
        // Subprocess branch: invoked internally via --dump-to, not by the user.
        // Boots a fresh app against a guaranteed-unused cache path, builds and
        // validates the cache contents, then writes them for the parent to read.
        if (is_string($dumpPath = $this->option('dump-to')) && $dumpPath !== '') {
            try {
                $payload = [
                    'ok' => true,
                    'contents' => $this->buildFreshConfigurationCacheContents(),
                ];

                $this->files->replace($dumpPath, serialize($payload));

                return self::SUCCESS;
            } catch (LogicException $e) {
                $payload = [
                    'ok' => false,
                    'message' => $e->getMessage(),
                ];

                $this->files->replace($dumpPath, serialize($payload));

                return self::FAILURE;
            }
        }

        $configPath = $this->hypervel->getCachedConfigPath();
        $contents = $this->getFreshConfigurationCacheContentsFromSubprocess();
        $mode = null;

        if ($this->files->exists($configPath)) {
            $permissions = $this->files->chmod($configPath);

            if (! is_string($permissions)) {
                throw new RuntimeException("Unable to determine permissions for [{$configPath}].");
            }

            $mode = octdec($permissions);
        }

        $this->files->replace($configPath, $contents, $mode);

        $this->components->info('Configuration cached successfully.');

        return self::SUCCESS;
    }

    /**
     * Get fresh configuration cache contents from an isolated child process.
     */
    protected function getFreshConfigurationCacheContentsFromSubprocess(): string
    {
        $dumpPath = @tempnam(sys_get_temp_dir(), 'hypervel-config-');
        $buildPath = null;
        $exception = null;

        try {
            if ($dumpPath === false) {
                throw new LogicException('Unable to create a temporary file for the configuration cache dump.');
            }

            $buildPath = $dumpPath . '.cache';

            if ($this->files->exists($buildPath)) {
                throw new LogicException("The alternate cache path [{$buildPath}] already exists.");
            }

            $process = new Process(
                [
                    PHP_BINARY,
                    $this->hypervel->basePath('artisan'),
                    'config:cache',
                    '--dump-to=' . $dumpPath,
                ],
                $this->hypervel->basePath(),
                [
                    'APP_CONFIG_CACHE' => $buildPath,
                    'HYPERVEL_AUTOLOAD_PATH' => $this->resolveSubprocessAutoloadPath(),
                ],
            );

            $process->setTimeout(null);
            $process->run();

            $serialized = $this->files->get($dumpPath);

            if ($serialized === '') {
                throw new ProcessFailedException($process);
            }

            $payload = @unserialize($serialized, ['allowed_classes' => false]);

            if (! is_array($payload)) {
                throw new LogicException('The configuration cache subprocess returned an invalid payload.');
            }

            if (($payload['ok'] ?? false) !== true) {
                throw new LogicException((string) ($payload['message'] ?? 'The configuration cache subprocess failed.'));
            }

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $contents = $payload['contents'] ?? null;

            if (! is_string($contents) || $contents === '') {
                throw new LogicException('The configuration cache subprocess returned an empty payload.');
            }

            return $contents;
        } catch (Throwable $throwable) {
            $exception = $throwable;

            throw $throwable;
        } finally {
            $cleanupException = null;

            foreach ([$dumpPath, $buildPath] as $path) {
                if (! is_string($path)) {
                    continue;
                }

                try {
                    if (! $this->files->delete($path) && $this->files->exists($path)) {
                        throw new RuntimeException("Unable to delete the temporary configuration cache file [{$path}].");
                    }
                } catch (Throwable $throwable) {
                    $cleanupException ??= $throwable;
                }
            }

            if ($exception === null && $cleanupException !== null) {
                throw $cleanupException;
            }
        }
    }

    /**
     * Build validated cache-file contents for the current fresh application config.
     */
    protected function buildFreshConfigurationCacheContents(): string
    {
        $config = $this->hypervel['config']->all();
        $contents = '<?php return ' . var_export($config, true) . ';' . PHP_EOL;
        $cachePath = @tempnam(sys_get_temp_dir(), 'hypervel-config-cache-');
        $exception = null;

        try {
            if ($cachePath === false) {
                throw new LogicException('Unable to create a temporary file for configuration cache validation.');
            }

            $this->files->replace($cachePath, $contents);

            try {
                require $cachePath;
            } catch (Throwable $e) {
                foreach (Arr::dot($config) as $key => $value) {
                    try {
                        eval(var_export($value, true) . ';');
                    } catch (Throwable) {
                        throw new LogicException("Your configuration files could not be serialized because the value at \"{$key}\" is non-serializable.", 0, $e);
                    }
                }

                throw new LogicException('Your configuration files are not serializable.', 0, $e);
            }

            return $contents;
        } catch (Throwable $throwable) {
            $exception = $throwable;

            throw $throwable;
        } finally {
            $cleanupException = null;

            if (is_string($cachePath)) {
                try {
                    if (! $this->files->delete($cachePath) && $this->files->exists($cachePath)) {
                        throw new RuntimeException("Unable to delete the temporary configuration cache file [{$cachePath}].");
                    }
                } catch (Throwable $throwable) {
                    $cleanupException = $throwable;
                }
            }

            if ($exception === null && $cleanupException !== null) {
                throw $cleanupException;
            }
        }
    }

    /**
     * Resolve the Composer autoload path for the cache subprocess.
     */
    protected function resolveSubprocessAutoloadPath(): string
    {
        $componentRoot = dirname((new ReflectionClass(Filesystem::class))->getFileName(), 4);

        $candidates = array_unique([
            $this->hypervel->basePath('vendor/autoload.php'),
            $componentRoot . '/vendor/autoload.php',
            dirname($componentRoot, 2) . '/autoload.php',
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new LogicException('Unable to locate the Composer autoloader for the configuration cache subprocess.');
    }
}
