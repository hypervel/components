<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

// The services are analyzed from this copy of the application, so they load from it too.
$loader = new ClassLoader;
$loader->addPsr4('WorkerStateDependency\\', dirname(__DIR__) . '/app');
$loader->register();
