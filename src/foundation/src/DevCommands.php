<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Composer\InstalledVersions;
use Hypervel\Support\NodePackageManager;
use ReflectionClass;

class DevCommands
{
    /**
     * The resolved NodePackageManager instance.
     */
    protected static ?NodePackageManager $packageManager = null;

    /**
     * Counter to keep track of how many colors have been assigned.
     *
     * Used to ensure colors are reused only after all have been used at least once.
     */
    protected static int $colorCount = 0;

    /**
     * The registered development commands.
     *
     * @var array<string, DevCommand>
     */
    protected static array $commands = [];

    /**
     * The names of commands that should be included when running the "dev" command.
     *
     * @var list<string>
     */
    protected static array $only = [];

    /**
     * The names of commands that should be excluded when running the "dev" command.
     *
     * @var list<string>
     */
    protected static array $except = [];

    /**
     * Register the default development commands.
     *
     * Boot-only. The commands persist in static properties for the worker lifetime
     * and affect every subsequent development command invocation.
     */
    public static function registerDefaults(): void
    {
        if (! app()->runningInConsole()) {
            return;
        }

        // Watcher owns the Swoole server process and restarts it after file changes.
        self::artisan('watch', 'server');
        self::artisan('queue:listen --tries=1 --timeout=0', 'queue');
        // REMOVED: Hypervel has no Pail-equivalent command for the default logs process.
        self::node('dev', 'vite');
    }

    /**
     * Register a development command.
     *
     * Boot-only. The command persists in a static property for the worker lifetime
     * and affects every subsequent development command invocation.
     */
    public static function register(string $command, ?string $name = null): DevCommand
    {
        if (! app()->runningInConsole()) {
            return new DevCommand('', [], '');
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $source = self::resolveSource($trace);
        $priority = self::resolvePriority($trace);

        $devCommand = new DevCommand($command, $source, $name, $priority);

        $existing = self::$commands[$devCommand->name()] ?? null;

        if (! $existing || $devCommand->priority() >= $existing->priority()) {
            self::$commands[$devCommand->name()] = $devCommand;
        }

        return $devCommand;
    }

    /**
     * Register an Artisan command, automatically prefixing it with "php artisan".
     *
     * Boot-only. The command persists in a static property for the worker lifetime
     * and affects every subsequent development command invocation.
     */
    public static function artisan(string $command, ?string $name = null): DevCommand
    {
        return self::register("php artisan {$command}", $name ?? DevCommand::nameFromCommand($command));
    }

    /**
     * Register a Node command, automatically prefixing it with the detected package manager's run command.
     *
     * Boot-only. The command persists in a static property for the worker lifetime
     * and affects every subsequent development command invocation.
     */
    public static function node(string $command, ?string $name = null): DevCommand
    {
        return self::register(self::getPackageManager()->getRunCommand($command), $name ?? DevCommand::nameFromCommand($command));
    }

    /**
     * Register a Node command, automatically prefixing it with the detected package manager's exec command.
     *
     * Boot-only. The command persists in a static property for the worker lifetime
     * and affects every subsequent development command invocation.
     */
    public static function nodeExec(string $command, ?string $name = null): DevCommand
    {
        return self::register(self::getPackageManager()->getExecCommand($command), $name ?? DevCommand::nameFromCommand($command));
    }

    /**
     * Get the registered development commands.
     *
     * @return list<array{command: string, name: string, color: string, source: array{file?: string, line?: int, class?: string, function?: string}, priority: int}>
     */
    public static function commands(): array
    {
        $commands = [];

        foreach (self::$commands as $command) {
            $cmd = $command->toArray();

            if ((! empty(self::$only) && ! in_array($cmd['name'], self::$only, true)) || in_array($cmd['name'], self::$except, true)) {
                continue;
            }

            $commands[] = $cmd;
        }

        return self::fillInEmptyColors($commands);
    }

    /**
     * Fill in any empty colors in the given commands array, ensuring each command has a color assigned.
     *
     * @param list<array{command: string, name: string, color: null|string, source: array{file?: string, line?: int, class?: string, function?: string}, priority: int}> $commands
     * @return list<array{command: string, name: string, color: string, source: array{file?: string, line?: int, class?: string, function?: string}, priority: int}>
     */
    protected static function fillInEmptyColors(array $commands): array
    {
        foreach ($commands as &$command) {
            if (empty($command['color'])) {
                $command['color'] = self::getColor($commands);
            }
        }

        return $commands;
    }

    /**
     * Get a color for a command, ensuring that colors are reused only after all available colors have been used at least once.
     *
     * @param list<array{command: string, name: string, color: null|string, source: array{file?: string, line?: int, class?: string, function?: string}, priority: int}> $commands
     */
    protected static function getColor(array $commands): string
    {
        $available = array_values(array_diff(
            $colors = array_map(fn (DevCommandColor $color): string => $color->value, DevCommandColor::cases()),
            $existing = array_values(array_filter(array_column($commands, 'color')))
        ));

        return $available[0] ?? $colors[self::$colorCount++ % count($colors)];
    }

    /**
     * Resolve the first external caller frame from a debug backtrace.
     *
     * @param array<int, array{file?: string, line?: int, class?: string, function?: string}> $trace
     * @return array{file?: string, line?: int, class?: string, function?: string}
     */
    protected static function resolveSource(array $trace): array
    {
        foreach ($trace as $frame) {
            if (($frame['file'] ?? null) === __FILE__) {
                continue;
            }

            if (($frame['class'] ?? null) === self::class) {
                continue;
            }

            return $frame;
        }

        return [];
    }

    /**
     * Determine the registration priority from a debug backtrace.
     *
     * @param array<int, array{file?: string, line?: int, class?: string, function?: string}> $trace
     * @return DevCommand::PRIORITY_DEFAULT|DevCommand::PRIORITY_USERLAND|DevCommand::PRIORITY_VENDOR
     */
    protected static function resolvePriority(array $trace): int
    {
        $vendorPath = realpath(base_path('vendor')) ?: base_path('vendor');

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;
            $class = $frame['class'] ?? null;

            if ($file === __FILE__) {
                continue;
            }

            if ($class === self::class && ($frame['function'] ?? null) === 'registerDefaults') {
                return DevCommand::PRIORITY_DEFAULT;
            }

            if (! $file && $class) {
                $file = (new ReflectionClass($class))->getFileName();
            }

            if (! $file || $file === base_path('artisan')) {
                continue;
            }

            $file = realpath($file) ?: $file;

            if (self::isDependencyFile($file)) {
                continue;
            }

            if (! self::isWithinPath($file, $vendorPath)) {
                return DevCommand::PRIORITY_USERLAND;
            }
        }

        return DevCommand::PRIORITY_VENDOR;
    }

    /**
     * Determine whether a file belongs to an installed Composer dependency.
     */
    protected static function isDependencyFile(string $file): bool
    {
        $rootPackage = InstalledVersions::getRootPackage()['name'];

        foreach (InstalledVersions::getInstalledPackages() as $package) {
            if ($package === $rootPackage) {
                continue;
            }

            $installPath = InstalledVersions::getInstallPath($package);

            if ($installPath === null || ($installPath = realpath($installPath)) === false) {
                continue;
            }

            if (self::isWithinPath($file, $installPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a path is the given directory or one of its descendants.
     */
    protected static function isWithinPath(string $path, string $directory): bool
    {
        return $path === $directory || str_starts_with($path, $directory . DIRECTORY_SEPARATOR);
    }

    /**
     * Set the commands that should be included when running the "dev" command.
     *
     * Boot-only. The filter persists in a static property for the worker lifetime
     * and affects every subsequent development command invocation.
     */
    public static function only(string ...$names): void
    {
        self::$only = $names;
    }

    /**
     * Set the commands that should be excluded when running the "dev" command.
     *
     * Boot-only. The filter persists in a static property for the worker lifetime
     * and affects every subsequent development command invocation.
     */
    public static function except(string ...$names): void
    {
        self::$except = $names;
    }

    /**
     * Resolve and return the NodePackageManager instance.
     */
    protected static function getPackageManager(): NodePackageManager
    {
        return self::$packageManager ??= new NodePackageManager;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$packageManager = null;
        self::$colorCount = 0;
        self::$commands = [];
        self::$only = [];
        self::$except = [];
    }
}
