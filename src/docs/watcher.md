# File Watching

- [Introduction](#introduction)
- [Running the Watcher](#running-the-watcher)
    - [Additional Watch Paths](#additional-watch-paths)
    - [Watching Without Restarting](#watching-without-restarting)
    - [Stopping the Watcher](#stopping-the-watcher)
- [Configuration](#configuration)
    - [Watch Paths](#watch-paths)
    - [Scan Interval](#scan-interval)
    - [Server Command](#server-command)
- [Watcher Drivers](#watcher-drivers)
    - [Choosing a Driver](#choosing-a-driver)
    - [Custom Drivers](#custom-drivers)
- [Custom Restart Strategies](#custom-restart-strategies)
- [Credits](#credits)

<a name="introduction"></a>
## Introduction

Because Hypervel application workers remain in memory between requests, changes to your application code are not loaded until the server restarts. During local development, you may use the file watcher to monitor your application and automatically restart the server when a watched file changes.

The watcher may also be used by other long-running processes. For example, [Hypervel Horizon](/docs/{{version}}/horizon#automatically-restarting-horizon) uses it to restart Horizon when your application changes.

<a name="running-the-watcher"></a>
## Running the Watcher

To start the development server and watch your application, invoke the `watch` Artisan command:

```shell
php artisan watch
```

When watched files change, Hypervel prints each path. Changes detected together are batched into a single server restart. Before starting the new server process, Hypervel reloads your application's environment file so changes to the file are applied.

<a name="additional-watch-paths"></a>
### Additional Watch Paths

The `--path` option may be used to watch additional paths for the current command. You may provide this option multiple times:

```shell
php artisan watch --path=routes --path="database/**/*.php"
```

These paths are added to those defined by the `watch` option in your application's `config/watcher.php` configuration file.

Quote glob patterns in the `--path` option so your shell passes the pattern to Artisan unchanged.

<a name="watching-without-restarting"></a>
### Watching Without Restarting

Sometimes you may want to check which files are detected without starting or restarting the development server. You may do so using the `--no-restart` option:

```shell
php artisan watch --no-restart
```

<a name="stopping-the-watcher"></a>
### Stopping the Watcher

You may stop the watcher by pressing `Ctrl+C`. Hypervel stops the active watcher driver and the development server before the command exits. The command also performs this cleanup when it receives a `SIGTERM` or `SIGQUIT` signal.

<a name="configuration"></a>
## Configuration

Your application's file watcher configuration is stored in the `config/watcher.php` configuration file. If your application does not contain this file, you may publish it using the `vendor:publish` Artisan command:

```shell
php artisan vendor:publish --tag=watcher-config
```

The default configuration uses the `ScanFileDriver` to check common application files every two seconds and starts the server using `php artisan serve`:

```php
use Hypervel\Watcher\Driver\ScanFileDriver;

return [
    'driver' => ScanFileDriver::class,
    'scan_interval' => 2000,

    'watch' => [
        'app/**/*.php',
        'config/**/*.php',
        '.env',
    ],

    'bin' => PHP_BINARY,
    'command' => ['artisan', 'serve'],
];
```

<a name="watch-paths"></a>
### Watch Paths

The `watch` option accepts directories, glob patterns, and specific files relative to your application's base directory:

```php
'watch' => [
    'app',
    'config/**/*.php',
    'routes/?.php',
    'lang/[a-z][a-z].php',
    'resources/**/*.{php,blade.php}',
    '.env',
],
```

At least one path must be provided through the configuration or the command line. Paths may not be empty or absolute.

Use `.` to watch the application root. A path may also begin with `..` to watch a directory outside your application, such as a package you are developing alongside it. Redundant separators, trailing separators, and `.` path segments are ignored:

```php
'watch' => [
    './app',
    '../packages/example/src/**/*.php',
],
```

A directory entry watches every file within that directory recursively, while a specific file entry watches only that file. When the watcher starts, it treats a plain path that names an existing directory as a directory. Otherwise, it treats the path as a file. If you create a configured directory after starting the watcher, restart the command so the path can be classified as a directory.

Glob patterns use Symfony Finder's glob syntax. A single `*` matches within one directory, while `**` may match across directories. You may also use `?` to match one character, braces to match one of several values, and brackets to match a character range.

A directory given directly as a watch path may be a symbolic link. The watcher follows that root, but does not traverse symbolic links found inside a watched directory.

<a name="scan-interval"></a>
### Scan Interval

The `scan_interval` option determines how often polling drivers check for changes, in milliseconds. The default value is `2000`, which checks for changes every two seconds:

```php
'scan_interval' => 2000,
```

The scan interval must be greater than zero.

This option is used by the `ScanFileDriver` and `FindDriver`. The `FswatchDriver` receives operating system events and does not use the scan interval.

<a name="server-command"></a>
### Server Command

The `bin` and `command` options determine how the watcher starts the development server. The `bin` option must contain one executable path. The first value in the `command` array is a script relative to your application's base directory, and any remaining values are passed to that script as arguments:

```php
'bin' => PHP_BINARY,
'command' => ['artisan', 'serve', '--port=8001'],
```

The executable and arguments are passed directly to the operating system without a shell. Your server's `server.settings.daemonize` configuration option must be `false` when using the watcher so the watcher can manage the server process.

<a name="watcher-drivers"></a>
## Watcher Drivers

Hypervel includes several drivers for detecting file changes:

| Driver | Requirements | Detected Changes |
|---|---|---|
| `ScanFileDriver` | None | Created, modified, renamed, and deleted files |
| `FindDriver` | `find` | Created, modified, renamed, and deleted files |
| `FswatchDriver` | `fswatch` | Created, modified, renamed, and deleted files |

You may select a driver using the `driver` option in your `watcher.php` configuration file:

```php
'driver' => Hypervel\Watcher\Driver\FswatchDriver::class,
```

<a name="choosing-a-driver"></a>
### Choosing a Driver

The `ScanFileDriver` requires no external tools and works on every supported platform. It reads each matched file during every scan, allowing it to detect content changes even when a file's metadata does not change. However, this may result in more disk activity when watching large directory trees. If part of the watched tree becomes unreadable, its files are reported as removed and then added again when access returns.

The `FindDriver` uses your system's `find` executable and is a good polling choice on Unix systems. Since it checks filesystem metadata instead of reading file contents, it generally requires less disk activity than the `ScanFileDriver`. However, it cannot detect a rewrite that preserves the file's modification time. On filesystems that record modification times only to the nearest second, a rewrite may also be missed when its timestamp matches the time of the previous scan.

If `find` cannot finish listing the watched files, such as when a watched directory cannot be read, deletions are not reported until a later scan completes. If it also cannot finish checking for changes, changes that were already detected may be reported again until the filesystem error is fixed.

The `FswatchDriver` uses operating system events instead of repeatedly scanning your files, giving it the lowest steady-state resource usage on local filesystems. This driver requires the `fswatch` executable and depends on your operating system delivering file events. On Linux, Hypervel registers only the directories required by your watch patterns, reducing inotify usage. On macOS, each watch root is observed recursively, so you should avoid unnecessarily broad roots.

Polling is generally safer when files live in containers, virtual machines, or network mounts that do not reliably forward operating system events.

<a name="custom-drivers"></a>
### Custom Drivers

If the included watcher drivers do not fit your application's needs, you may create a custom driver by implementing the `Hypervel\Watcher\Driver\DriverInterface` interface:

```php
namespace Hypervel\Watcher\Driver;

use Hypervel\Engine\Channel;

interface DriverInterface
{
    public function watch(Channel $channel): void;

    public function stop(): void;
}
```

The `watch` method should run the watch loop and push each changed file path into the provided channel. The `stop` method should release the driver's resources, promptly unblock its watch loop, and safely handle repeated calls. After calling `stop`, Hypervel waits up to one second for `watch` to return before reporting an error. Hypervel resolves the configured driver through the service container. The driver's constructor may accept the current `Hypervel\Watcher\Option` instance using an `$option` parameter, as well as any other dependencies it needs.

Once you have implemented the driver, specify its class in your application's configuration:

```php
'driver' => App\Watcher\CustomDriver::class,
```

<a name="custom-restart-strategies"></a>
## Custom Restart Strategies

The watcher uses a restart strategy to control the process that should be started and restarted when files change. The `watch` command uses `ServerRestartStrategy`, while Horizon provides its own strategy for restarting Horizon.

Packages may reuse the watcher for another process by implementing the `Hypervel\Watcher\RestartStrategy` interface and passing the strategy to the `Watcher` constructor:

```php
namespace Hypervel\Watcher;

interface RestartStrategy
{
    public function start(): void;

    public function restart(): void;

    public function stop(): void;
}
```

The watcher calls `start` before it begins watching, `restart` after files change, and `stop` when the watcher exits. The `stop` method may be called more than once and should safely ignore repeated calls. You may omit the strategy when you only need to detect and report file changes.

<a name="credits"></a>
## Credits

Hypervel Watcher began as a port of [Hyperf Watcher](https://github.com/hyperf/hyperf/tree/master/src/watcher) and has been adapted for Hypervel's framework architecture and coroutine runtime.
