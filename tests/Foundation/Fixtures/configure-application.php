<?php

declare(strict_types=1);

use Hypervel\Foundation\Application;

require __DIR__ . '/../../../vendor/autoload.php';

try {
    Application::configure(basePath: __DIR__);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage());

    exit(1);
}
