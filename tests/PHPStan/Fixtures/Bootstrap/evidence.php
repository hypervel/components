<?php

declare(strict_types=1);

use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Str;

$app = Container::getInstance();

file_put_contents(
    (string) getenv('HYPERVEL_PHPSTAN_BOOT_EVIDENCE'),
    json_encode([
        'pid' => getmypid(),
        'application' => $app instanceof Application,
        'basePath' => $app instanceof Application ? $app->basePath() : null,
        'booted' => $app instanceof Application && $app->isBooted(),
        'macro' => Str::hasMacro('phpstanBootstrapFixture'),
    ], JSON_THROW_ON_ERROR) . PHP_EOL,
    FILE_APPEND | LOCK_EX,
);
