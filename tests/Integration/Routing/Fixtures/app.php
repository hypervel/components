<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing\Fixtures;

use Hypervel\Foundation\Application;

if (! class_exists(AppCache::class)) {
    class AppCache
    {
        public static $app;
    }
}

if (isset($refresh)) {
    return AppCache::$app = Application::configure(basePath: __DIR__)->create();
}
return AppCache::$app ??= Application::configure(basePath: __DIR__)->create();
