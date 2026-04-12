File Watcher for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/watcher)

A file watcher with pluggable drivers and restart strategies for Hypervel. Detects file changes using coroutine-native drivers and triggers configurable restart actions.

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

    'bin' => PHP_BINARY,
    'command' => 'artisan serve',
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
| `ScanFileDriver` | Cross-platform, polls files using MD5 checksums |
| `FindDriver` | Uses `find -mmin` (Linux) or `gfind` (macOS via Homebrew) |
| `FindNewerDriver` | Uses `find -newer` with a reference file for comparison |
| `FswatchDriver` | Uses `fswatch` (macOS native or Linux via `apt`/`brew`) |

## Usage

```bash
# Watch and restart server on file changes
php artisan watch

# Watch additional paths beyond config
php artisan watch --path=routes --path=database/**/*.php

# Watch without restarting (detect changes only)
php artisan watch --no-restart
```

## Architecture

The watcher separates three concerns:

- **`Option`** — Parses watch configuration into typed `WatchPath` objects
- **Drivers** (`DriverInterface`) — Detect file changes and push paths to a Channel
- **Restart Strategies** (`RestartStrategy`) — Define what happens when changes are detected

### Restart Strategies

The `RestartStrategy` interface enables different packages to reuse the file watching infrastructure:

```php
interface RestartStrategy
{
    public function start(): void;
    public function restart(): void;
}
```

The built-in `ServerRestartStrategy` handles Swoole server restart via PID file. Other packages (e.g., Horizon) can implement their own strategy to restart different process types.
