<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;

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

    /**
     * Bootstrap the testbench environment.
     */
    public static function bootstrap(): void
    {
        $workingPath = defined('TESTBENCH_WORKING_PATH') ? TESTBENCH_WORKING_PATH : package_path();

        if (! defined('TESTBENCH_WORKING_PATH')) {
            define('TESTBENCH_WORKING_PATH', $workingPath);
        }

        static::loadConfigFromYaml(static::resolveConfigurationPath($workingPath));

        $sourcePath = testbench_path('hypervel');
        if (static::$configuration?->offsetExists('hypervel') === true && is_string(static::$configuration['hypervel'])) {
            $sourcePath = static::$configuration['hypervel'];
        }

        $basePath = static::resolveRuntimeBasePath($sourcePath);

        ! defined('BASE_PATH') && define('BASE_PATH', $basePath);
        ! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', SWOOLE_HOOK_ALL);

        if (static::$runtimePath !== null) {
            static::registerPurgeFiles();
        }
    }

    /**
     * Get the configuration attributes as an array.
     */
    public static function getConfig(): array
    {
        return static::$configuration instanceof Config
            ? static::$configuration->getAttributes()
            : [];
    }

    /**
     * Get the cached configuration instance.
     */
    public static function getConfiguration(): ?ConfigContract
    {
        return static::$configuration;
    }

    /**
     * Flush the cached bootstrap state.
     */
    public static function flushState(): void
    {
        static::$configuration = null;
        static::$runtimePath = null;
        static::$filesystem = null;
    }

    /**
     * Get the filesystem instance.
     */
    protected static function getFilesystem(): Filesystem
    {
        if (static::$filesystem) {
            return static::$filesystem;
        }

        return static::$filesystem = new Filesystem;
    }

    protected static function loadConfigFromYaml(string $workingPath, ?string $filename = 'testbench.yaml', array $defaults = []): void
    {
        static::$configuration = Config::cacheFromYaml($workingPath, $filename, $defaults);
    }

    /**
     * Resolve the directory that owns the active testbench.yaml file.
     */
    protected static function resolveConfigurationPath(string $workingPath): string
    {
        return static::hasConfigurationFile($workingPath)
            ? $workingPath
            : testbench_path();
    }

    /**
     * Determine if the given path contains a testbench configuration file.
     */
    protected static function hasConfigurationFile(string $workingPath, string $filename = 'testbench.yaml'): bool
    {
        foreach ([$filename, "{$filename}.example", "{$filename}.dist"] as $candidate) {
            if (static::getFilesystem()->isFile(join_paths($workingPath, $candidate))) {
                return true;
            }
        }

        return false;
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
        // Normalize the temp dir so that BASE_PATH matches paths derived via
        // realpath() (e.g. default_skeleton_path()). On macOS, sys_get_temp_dir()
        // returns /var/folders/... but glob() resolves symlinks to /private/var/...
        // causing BASE_PATH to differ from app->basePath() used in test assertions.
        $tempDir = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $runtimePath = $tempDir . "/hypervel-components-testbench-{$token}-{$pid}";

        $filesystem = static::getFilesystem();

        // Purge stale dirs for this worker token from previous crashed runs.
        // A dir is stale when its owning PID is either dead or orphaned
        // (PPID=1, meaning the test process that spawned it exited). Orphaned
        // serve processes (confirmed via hypervel.pid) are killed before their
        // dirs are removed.
        foreach (glob($tempDir . "/hypervel-components-testbench-{$token}-*") as $staleDir) {
            if (! $filesystem->isDirectory($staleDir)) {
                continue;
            }

            $stalePid = (int) substr($staleDir, strrpos($staleDir, '-') + 1);

            if ($stalePid > 0 && posix_kill($stalePid, 0)) {
                // Process is alive — check if it's an orphaned serve process.
                if (static::isOrphanedServeProcess($stalePid, $staleDir)) {
                    static::killProcessTree($stalePid);
                } else {
                    continue; // Legitimately running — don't delete
                }
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

    /**
     * Determine if the given PID is an orphaned serve process.
     *
     * A process is considered an orphaned serve process when its parent is
     * PID 1 (re-parented by init after the original parent exited) and the
     * runtime directory contains a Swoole PID file, confirming it was a
     * serve command that started a server.
     */
    protected static function isOrphanedServeProcess(int $pid, string $runtimeDir): bool
    {
        // Check PPID = 1 (orphaned) via /proc on Linux.
        $statusFile = "/proc/{$pid}/status";

        if (is_readable($statusFile)) {
            $contents = @file_get_contents($statusFile);

            if ($contents !== false && preg_match('/^PPid:\s+(\d+)$/m', $contents, $matches)) {
                if ((int) $matches[1] !== 1) {
                    return false;
                }
            } else {
                return false;
            }
        } else {
            // Fallback for macOS: use ps to check PPID.
            $output = [];
            exec("ps -o ppid= -p {$pid} 2>/dev/null", $output);

            if (! isset($output[0]) || (int) trim($output[0]) !== 1) {
                return false;
            }
        }

        // Confirm this was a serve process by checking for the PID file.
        return is_file("{$runtimeDir}/storage/framework/hypervel.pid");
    }

    /**
     * Kill a process and all its descendants.
     *
     * Collects the full descendant tree first (single /proc scan), then
     * kills leaves before parents to avoid re-parenting races where killing
     * a parent causes its children to be adopted by init before we can
     * find them.
     */
    protected static function killProcessTree(int $pid): void
    {
        $descendants = static::collectDescendants($pid);

        // Kill leaves first (reverse of parent-before-children order).
        foreach (array_reverse($descendants) as $descendantPid) {
            if (posix_kill($descendantPid, 0)) {
                posix_kill($descendantPid, SIGKILL);
            }
        }

        // Kill the root process itself.
        if (posix_kill($pid, 0)) {
            posix_kill($pid, SIGKILL);
        }
    }

    /**
     * Collect all descendant PIDs of the given PID in depth-first order.
     *
     * Scans /proc once to build a PID→children map, then walks the subtree.
     * Returns PIDs in parent-before-children order.
     *
     * @return array<int, int>
     */
    protected static function collectDescendants(int $rootPid): array
    {
        $childrenMap = static::buildChildrenMap();
        $descendants = [];

        $stack = $childrenMap[$rootPid] ?? [];

        while ($stack !== []) {
            $pid = array_pop($stack);
            $descendants[] = $pid;

            foreach ($childrenMap[$pid] ?? [] as $childPid) {
                $stack[] = $childPid;
            }
        }

        return $descendants;
    }

    /**
     * Build a map of PID → direct child PIDs by scanning /proc once.
     *
     * @return array<int, array<int, int>>
     */
    protected static function buildChildrenMap(): array
    {
        $map = [];

        if (is_dir('/proc')) {
            foreach (scandir('/proc') as $entry) {
                if (! ctype_digit($entry)) {
                    continue;
                }

                $statusFile = "/proc/{$entry}/status";
                if (! is_readable($statusFile)) {
                    continue;
                }

                $contents = @file_get_contents($statusFile);
                if ($contents === false) {
                    continue;
                }

                if (preg_match('/^PPid:\s+(\d+)$/m', $contents, $matches)) {
                    $map[(int) $matches[1]][] = (int) $entry;
                }
            }

            return $map;
        }

        // Fallback for macOS: use ps to get all PID/PPID pairs.
        $output = [];
        exec('ps -eo pid=,ppid= 2>/dev/null', $output);

        foreach ($output as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) === 2) {
                $map[(int) $parts[1]][] = (int) $parts[0];
            }
        }

        return $map;
    }

    /**
     * Register shutdown handlers to purge configured files and directories.
     */
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
