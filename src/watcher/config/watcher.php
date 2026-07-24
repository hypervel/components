<?php

declare(strict_types=1);

use Hypervel\Watcher\Driver\ScanFileDriver;

return [
    /*
    |--------------------------------------------------------------------------
    | File Watcher Driver
    |--------------------------------------------------------------------------
    |
    | The driver used to detect file changes. Available drivers:
    |
    | - ScanFileDriver: Hash polling; observes creation, modification, deletion.
    | - FindDriver: Uses `find -mmin`; observes creation and modification.
    | - FindNewerDriver: Uses `find -newer`; observes creation and modification.
    | - FswatchDriver: OS events; observes creation, modification, rename, deletion.
    |
    */

    'driver' => ScanFileDriver::class,

    /*
    |--------------------------------------------------------------------------
    | Scan Interval
    |--------------------------------------------------------------------------
    |
    | How often the watcher polls for file changes, in milliseconds. This
    | applies to all polling-based drivers (ScanFile, Find, FindNewer).
    | The FswatchDriver uses OS-level events and ignores this setting.
    |
    */

    'scan_interval' => 2000,

    /*
    |--------------------------------------------------------------------------
    | Watch Paths
    |--------------------------------------------------------------------------
    |
    | Paths and glob patterns to monitor for changes. Each entry can be
    | a directory name (watches all files recursively), a glob pattern
    | (watches matching files only), or a specific file path. See the
    | Symfony Finder Glob documentation for supported pattern syntax.
    |
    */

    'watch' => [
        'app/**/*.php',
        'config/**/*.php',
        '.env',
    ],

    /*
    |--------------------------------------------------------------------------
    | PHP Binary Path
    |--------------------------------------------------------------------------
    |
    | The path to the executable used to start the server process. This must
    | be a single executable path, not a shell command or command fragment.
    |
    */

    'bin' => PHP_BINARY,

    /*
    |--------------------------------------------------------------------------
    | Server Command
    |--------------------------------------------------------------------------
    |
    | The command arguments used to start the server. The first item is the
    | script path relative to the project root; remaining items are arguments.
    |
    */

    'command' => ['artisan', 'serve'],
];
