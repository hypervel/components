<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;

use function Hypervel\Filesystem\join_paths;

class Bootstrapper
{
    protected static ?ConfigContract $configuration = null;

    protected static ?Filesystem $filesystem = null;

    /**
     * The path to the disposable runtime copy of the workbench.
     *
     * Null until bootstrap() creates the copy.
     */
    protected static ?string $runtimePath = null;

    public static function bootstrap(): void
    {
        $workingPath = defined('TESTBENCH_WORKING_PATH') ? TESTBENCH_WORKING_PATH : dirname(__DIR__);

        if (! defined('TESTBENCH_WORKING_PATH')) {
            define('TESTBENCH_WORKING_PATH', $workingPath);
        }

        static::loadConfigFromYaml($workingPath);

        $sourcePath = "{$workingPath}/hypervel";
        if (static::$configuration?->offsetExists('hypervel') === true && is_string(static::$configuration['hypervel'])) {
            $sourcePath = static::$configuration['hypervel'];
        }

        $basePath = static::resolveRuntimeBasePath($sourcePath);

        ! defined('BASE_PATH') && define('BASE_PATH', $basePath);
        ! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', SWOOLE_HOOK_ALL);

        if (static::$runtimePath !== null) {
            static::generateEnv();
            static::registerPurgeFiles();
        }
    }

    public static function getConfig(): array
    {
        return static::$configuration instanceof Config
            ? static::$configuration->getAttributes()
            : [];
    }

    public static function getConfiguration(): ?ConfigContract
    {
        return static::$configuration;
    }

    protected static function getFilesystem(): Filesystem
    {
        if (static::$filesystem) {
            return static::$filesystem;
        }

        return static::$filesystem = new Filesystem();
    }

    protected static function loadConfigFromYaml(string $workingPath, ?string $filename = 'testbench.yaml', array $defaults = []): void
    {
        static::$configuration = Config::cacheFromYaml($workingPath, $filename, $defaults);
    }

    protected static function generateEnv(): void
    {
        $env = static::$configuration?->getExtraAttributes()['env'] ?? [];

        if ($env === []) {
            return;
        }

        static::getFilesystem()->replace(
            join_paths(BASE_PATH, '/.env'),
            implode(PHP_EOL, $env)
        );
    }

    /**
     * Create a disposable runtime copy of the skeleton directory.
     *
     * Tests write generated files (make:provider, make:model, etc.) and mutate
     * bootstrap/providers.php into the app's basePath. By copying the skeleton
     * to a temp directory and using that as BASE_PATH, the committed skeleton
     * stays clean. The copy is deleted on shutdown.
     */
    protected static function createRuntimeCopy(string $sourcePath): string
    {
        $token = $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? 'default';
        $pid = getmypid();
        $runtimePath = sys_get_temp_dir() . "/hypervel-components-testbench-{$token}-{$pid}";

        $filesystem = static::getFilesystem();

        // Purge stale dirs for this worker token from previous crashed runs.
        // Only delete dirs whose PID is no longer running to avoid destroying
        // a parent process's live copy when remote() spawns a subprocess.
        foreach (glob(sys_get_temp_dir() . "/hypervel-components-testbench-{$token}-*") as $staleDir) {
            if (! $filesystem->isDirectory($staleDir)) {
                continue;
            }

            $stalePid = (int) substr($staleDir, strrpos($staleDir, '-') + 1);

            if ($stalePid > 0 && posix_kill($stalePid, 0)) {
                continue; // Process still running — don't delete
            }

            $filesystem->deleteDirectory($staleDir);
        }

        $filesystem->copyDirectory($sourcePath, $runtimePath);

        static::$runtimePath = $runtimePath;

        register_shutdown_function(static function () {
            static::deleteRuntimeCopy();
        });

        return $runtimePath;
    }

    /**
     * Resolve the runtime base path for the current process.
     */
    protected static function resolveRuntimeBasePath(string $sourcePath): string
    {
        $existingRuntimePath = $_SERVER['TESTBENCH_BASE_PATH'] ?? $_ENV['TESTBENCH_BASE_PATH'] ?? null;
        $isRemoteProcess = ($_SERVER['TESTBENCH_PACKAGE_REMOTE'] ?? $_ENV['TESTBENCH_PACKAGE_REMOTE'] ?? null) === '(true)';

        if ($isRemoteProcess && is_string($existingRuntimePath) && static::getFilesystem()->isDirectory($existingRuntimePath)) {
            return $existingRuntimePath;
        }

        return static::createRuntimeCopy($sourcePath);
    }

    /**
     * Delete the disposable runtime copy.
     */
    protected static function deleteRuntimeCopy(): void
    {
        if (static::$runtimePath === null) {
            return;
        }

        $filesystem = static::getFilesystem();

        if ($filesystem->isDirectory(static::$runtimePath)) {
            $filesystem->deleteDirectory(static::$runtimePath);
        }

        static::$runtimePath = null;
    }

    protected static function registerPurgeFiles(): void
    {
        $purge = static::$configuration?->getPurgeAttributes() ?? [];
        $files = $purge['files'] ?? [];
        $directories = $purge['directories'] ?? [];

        if (! $files && ! $directories) {
            return;
        }

        register_shutdown_function(function () use ($files, $directories) {
            $filesystem = static::getFilesystem();
            foreach ($files as $file) {
                if (! $filesystem->exists($file = BASE_PATH . "/{$file}")) {
                    continue;
                }
                $filesystem->delete($file);
            }

            foreach ($directories as $directory) {
                if (! $filesystem->exists($directory = BASE_PATH . "/{$directory}")) {
                    continue;
                }
                $filesystem->deleteDirectory($directory);
            }
        });
    }
}
