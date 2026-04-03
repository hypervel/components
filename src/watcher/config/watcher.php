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
    | - ScanFileDriver: Cross-platform, polls files using MD5 checksums.
    | - FindDriver: Uses `find -mmin` (Linux) or `gfind` (macOS via Homebrew).
    | - FindNewerDriver: Uses `find -newer` with a reference file for comparison.
    | - FswatchDriver: Uses `fswatch` (macOS native or Linux via `apt`/`brew`).
    |
    */

    'driver' => ScanFileDriver::class,

    /*
    |--------------------------------------------------------------------------
    | PHP Binary Path
    |--------------------------------------------------------------------------
    |
    | The path to the PHP binary used to start the server process.
    |
    */

    'bin' => PHP_BINARY,

    /*
    |--------------------------------------------------------------------------
    | Server Command
    |--------------------------------------------------------------------------
    |
    | The command used to start the server, relative to the project root.
    |
    */

    'command' => 'artisan serve',

    /*
    |--------------------------------------------------------------------------
    | Watch Paths
    |--------------------------------------------------------------------------
    |
    | Directories and individual files to monitor for changes. Directories
    | are scanned recursively. Individual files are checked directly.
    |
    */

    'watch' => [
        'dir' => ['app', 'config'],
        'file' => ['.env'],
        'scan_interval' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | File Extensions
    |--------------------------------------------------------------------------
    |
    | Only files matching these extensions will trigger a reload.
    |
    */

    'ext' => ['.php', '.env'],
];
