<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\Foundation\EnvironmentFile;
use JsonException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class Bootstrapper
{
    protected const RUNTIME_PROCESS_MARKER = '.testbench-process';

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

        $basePath = static::resolveRuntimeBasePath($sourcePath, $workingPath);

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
    protected static function createRuntimeCopy(string $sourcePath, string $workingPath): string
    {
        $token = $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? 'default';
        $pid = getmypid();
        // Normalize the temp dir so that BASE_PATH matches paths derived via
        // realpath(). On macOS, sys_get_temp_dir() returns /var/folders/...
        // but glob() resolves symlinks to /private/var/folders/..., causing
        // BASE_PATH to differ from app->basePath() in test assertions.
        $tempDir = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $runtimePath = $tempDir . "/hypervel-components-testbench-{$token}-{$pid}";

        $filesystem = static::getFilesystem();

        // Purge stale dirs from previous crashed runs, including copies created
        // under a different ParaTest token or a reused PID.
        // A dir is stale when its owning PID is dead, reused by this process
        // without being the active copy, or orphaned (PPID=1, meaning the test
        // process that spawned it exited). Orphaned serve processes (confirmed
        // by PID, command, and process incarnation) are killed before removal.
        foreach (glob($tempDir . '/hypervel-components-testbench-*') as $staleDir) {
            if (! $filesystem->isDirectory($staleDir)) {
                continue;
            }

            if ($staleDir === static::$runtimePath) {
                continue;
            }

            $stalePid = (int) substr($staleDir, strrpos($staleDir, '-') + 1);

            if ($stalePid > 0 && $stalePid !== $pid && posix_kill($stalePid, 0)) {
                // Process is alive — check if it's an orphaned serve process.
                if (static::isOrphanedServeProcess($stalePid, $staleDir)) {
                    static::killProcessTree($stalePid);
                } else {
                    continue; // Legitimately running — don't delete
                }
            }

            static::deleteRuntimeDirectory($staleDir);
        }

        try {
            if (! $filesystem->copyDirectory($sourcePath, $runtimePath)) {
                throw new RuntimeException("Unable to create the Testbench runtime copy at [{$runtimePath}].");
            }

            if (Env::has('TESTBENCH_PACKAGE_TESTER')) {
                static::copyPackageEnvironmentFile($filesystem, $runtimePath, $workingPath);
            }

            $startIdentity = static::processStartIdentity($pid);

            if ($startIdentity !== null) {
                $filesystem->replace(
                    join_paths($runtimePath, static::RUNTIME_PROCESS_MARKER),
                    json_encode([
                        'pid' => $pid,
                        'started_at' => $startIdentity,
                    ], JSON_THROW_ON_ERROR),
                    0600,
                );
            }
        } catch (Throwable $exception) {
            try {
                static::deleteRuntimeDirectory($runtimePath);
            } catch (Throwable) {
                // Preserve the runtime-creation failure when rollback also fails.
            }

            throw $exception;
        }

        static::$runtimePath = $runtimePath;

        register_shutdown_function(static function () {
            static::deleteRuntimeCopy();
        });

        return $runtimePath;
    }

    /**
     * Copy the package or workbench environment file into the runtime copy.
     */
    protected static function copyPackageEnvironmentFile(Filesystem $filesystem, string $runtimePath, string $workingPath): void
    {
        $environmentFile = (new EnvironmentFile($filesystem))->packageOrSkeletonFallback(
            workingPath: $workingPath,
            appBasePath: $runtimePath,
            filename: static::testbenchEnvironmentFile(),
        );

        if ($environmentFile !== null) {
            $filesystem->copy($environmentFile, join_paths($runtimePath, '.env'));
        }
    }

    /**
     * Determine the active Testbench environment file name.
     */
    protected static function testbenchEnvironmentFile(): string
    {
        $environmentFile = Env::get('TESTBENCH_ENVIRONMENT_FILENAME', '.env');

        return is_string($environmentFile) && $environmentFile !== ''
            ? $environmentFile
            : '.env';
    }

    /**
     * Resolve the runtime base path for the current process.
     */
    protected static function resolveRuntimeBasePath(string $sourcePath, string $workingPath): string
    {
        $existingRuntimePath = $_SERVER['TESTBENCH_BASE_PATH'] ?? $_ENV['TESTBENCH_BASE_PATH'] ?? null;
        $isRemoteProcess = ($_SERVER['TESTBENCH_PACKAGE_REMOTE'] ?? $_ENV['TESTBENCH_PACKAGE_REMOTE'] ?? null) === '(true)';

        if ($isRemoteProcess && is_string($existingRuntimePath) && static::getFilesystem()->isDirectory($existingRuntimePath)) {
            return $existingRuntimePath;
        }

        return static::createRuntimeCopy($sourcePath, $workingPath);
    }

    /**
     * Delete the disposable runtime copy.
     */
    protected static function deleteRuntimeCopy(): void
    {
        if (static::$runtimePath === null) {
            return;
        }

        static::deleteRuntimeDirectory(static::$runtimePath);

        static::$runtimePath = null;
    }

    /**
     * Delete a runtime copy while tolerating sibling cleanup races.
     *
     * Multiple same-token Testbench children can bootstrap at once and purge
     * the same stale runtime copy. If another child wins the race, the missing
     * directory is the desired postcondition; if the directory remains, the
     * original filesystem failure is still surfaced.
     */
    protected static function deleteRuntimeDirectory(string $directory): void
    {
        $filesystem = static::getFilesystem();

        if (! static::runtimeDirectoryExists($filesystem, $directory)) {
            return;
        }

        try {
            $filesystem->deleteDirectory($directory);

            return;
        } catch (UnexpectedValueException) {
            clearstatcache(true, $directory);

            if (! static::runtimeDirectoryExists($filesystem, $directory)) {
                return;
            }
        }

        try {
            $filesystem->deleteDirectory($directory);
        } catch (UnexpectedValueException $retryException) {
            clearstatcache(true, $directory);

            if (static::runtimeDirectoryExists($filesystem, $directory)) {
                throw $retryException;
            }
        }
    }

    /**
     * Determine if a runtime directory exists.
     *
     * @phpstan-impure
     */
    protected static function runtimeDirectoryExists(Filesystem $filesystem, string $directory): bool
    {
        return $filesystem->isDirectory($directory);
    }

    /**
     * Determine if the given PID is an orphaned serve process.
     *
     * A process is considered an orphaned serve process only when its parent
     * is init and its PID, command, and process incarnation all match the
     * runtime directory.
     */
    protected static function isOrphanedServeProcess(int $pid, string $runtimeDir): bool
    {
        if ($pid <= 0 || ! posix_kill($pid, 0)) {
            return false;
        }

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

        return static::matchesServeProcessIdentity($pid, $runtimeDir);
    }

    /**
     * Determine whether a process identity matches a Testbench serve runtime.
     */
    protected static function matchesServeProcessIdentity(int $pid, string $runtimeDir): bool
    {
        $pidFile = join_paths($runtimeDir, 'storage/framework/hypervel.pid');
        $pidContents = @file_get_contents($pidFile);

        if ($pidContents === false
            || ! ctype_digit($pidContents = trim($pidContents))
            || (int) $pidContents !== $pid
        ) {
            return false;
        }

        $command = static::processCommand($pid);

        if ($command === null
            || preg_match(
                '/(?:^|\s)\S*(?:testbench|hypervel)(?:\.php)?\s+serve(?:\s|$)/i',
                $command,
            ) !== 1
        ) {
            return false;
        }

        $marker = @file_get_contents(join_paths($runtimeDir, static::RUNTIME_PROCESS_MARKER));

        if ($marker === false) {
            return false;
        }

        try {
            $identity = json_decode($marker, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($identity)
            || count($identity) !== 2
            || ($identity['pid'] ?? null) !== $pid
            || ! is_string($startedAt = $identity['started_at'] ?? null)
            || $startedAt === ''
        ) {
            return false;
        }

        $currentStartIdentity = static::processStartIdentity($pid);

        return $currentStartIdentity !== null
            && hash_equals($startedAt, $currentStartIdentity);
    }

    /**
     * Read the command line for a process.
     */
    protected static function processCommand(int $pid): ?string
    {
        if (is_dir('/proc')) {
            $path = "/proc/{$pid}/cmdline";

            if (! is_readable($path)
                || ($contents = @file_get_contents($path)) === false
                || $contents === ''
            ) {
                return null;
            }

            return trim(str_replace("\0", ' ', $contents));
        }

        $output = [];
        exec("ps -ww -p {$pid} -o command= 2>/dev/null", $output);
        $command = trim(implode("\n", $output));

        return $command !== '' ? $command : null;
    }

    /**
     * Read the OS identity of the process incarnation.
     *
     * Linux exposes the start clock tick exactly. macOS `lstart` has one-second
     * resolution, which is sufficient alongside the PID and validated command.
     */
    protected static function processStartIdentity(int $pid): ?string
    {
        if (is_dir('/proc')) {
            $path = "/proc/{$pid}/stat";

            if (! is_readable($path)
                || ($contents = @file_get_contents($path)) === false
                || ($commandEnd = strrpos($contents, ')')) === false
            ) {
                return null;
            }

            $fields = preg_split('/\s+/', trim(substr($contents, $commandEnd + 1)));
            $startedAt = $fields[19] ?? null;

            return is_string($startedAt) && ctype_digit($startedAt)
                ? $startedAt
                : null;
        }

        $output = [];
        exec("ps -p {$pid} -o lstart= 2>/dev/null", $output);
        $startedAt = trim(implode(' ', $output));

        return $startedAt !== '' ? $startedAt : null;
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

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$configuration = null;
        // The runtime path and filesystem are process-wide infrastructure:
        // runtimePath must survive until shutdown cleanup, and Filesystem is reusable.
    }
}
