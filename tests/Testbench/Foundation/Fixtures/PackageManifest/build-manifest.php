<?php

declare(strict_types=1);

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Foundation\PackageManifest;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

$basePath = __DIR__;
$manifestPath = $argv[1] ?? null;

if (($argv[2] ?? null) === '--testbench-core' && ! defined('TESTBENCH_CORE')) {
    define('TESTBENCH_CORE', true);
}

if (! is_string($manifestPath) || $manifestPath === '') {
    throw new InvalidArgumentException('A manifest path is required.');
}

(new PackageManifest(new Filesystem, $basePath, $manifestPath))->build();
