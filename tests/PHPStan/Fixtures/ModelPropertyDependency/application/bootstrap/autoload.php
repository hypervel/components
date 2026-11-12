<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

// The models are analyzed from this copy of the application, so they load from it too.
$loader = new ClassLoader;
$loader->addPsr4('ModelPropertyDependency\Models\\', dirname(__DIR__) . '/app/Models');
$loader->register();
