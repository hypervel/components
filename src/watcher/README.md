File Watcher for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/watcher)

A file watcher with pluggable drivers and restart strategies for Hypervel. Detects file changes using coroutine-native drivers and triggers configurable restart actions.

Ported from: https://github.com/hyperf/hyperf/tree/master/src/watcher

## Configuration

```php
// config/watcher.php
return [
    'driver' => ScanFileDriver::class,
    'scan_interval' => 2000,

    'watch' => [
        'app/**/*.php',
        'config/**/*.php',
        '.env',
    ],

    // A single executable path, not a shell command or command fragment.
    'bin' => PHP_BINARY,
    // The project-relative script followed by its arguments.
    'command' => ['artisan', 'serve'],
];
```

### Watch Paths

Each entry in the `watch` array can be:

- **A directory name** — `'app'` watches all files recursively
- **A glob pattern** — `'config/**/*.php'` watches only matching files
- **A specific file** — `'.env'` or `'composer.json'`

Glob patterns support `*` (single directory segment), `**` (recursive), `?` (single character), and `{a,b}` (alternation), powered by Symfony Finder's glob engine.

### Drivers

| Driver | Description |
|--------|-------------|
| `ScanFileDriver` | Cross-platform hash polling for created, modified, and deleted files |
| `FindDriver` | Uses `find -mmin` for created and modified files (`gfind` on macOS) |
| `FindNewerDriver` | Uses `find -newer` for created and modified files |
| `FswatchDriver` | Uses OS events for created, modified, renamed, and deleted files |

## Usage

```bash
# Watch and restart server on file changes
php artisan watch

# Watch additional paths beyond config
php artisan watch --path=routes --path=database/**/*.php

# Watch without restarting (detect changes only)
php artisan watch --no-restart
```

When the watch command receives `SIGINT`, `SIGTERM`, or `SIGQUIT`, it stops the active driver and managed server before allowing the signal to terminate the command.

## Architecture

The watcher separates three concerns:

- **`Option`** — Parses watch configuration into typed `WatchPath` objects
- **Drivers** (`DriverInterface`) — Own one blocking watch lifecycle, push changed paths to a channel, and unblock when stopped
- **Restart Strategies** (`RestartStrategy`) — Own the managed process lifecycle around detected changes

### Restart Strategies

The `RestartStrategy` interface enables different packages to reuse the file watching infrastructure:

```php
interface RestartStrategy
{
    public function start(): void;
    public function restart(): void;
    public function stop(): void;
}
```

The built-in `ServerRestartStrategy` owns and restarts the Swoole server child process directly. Other packages (e.g., Horizon) can implement their own strategy to restart different process types.
