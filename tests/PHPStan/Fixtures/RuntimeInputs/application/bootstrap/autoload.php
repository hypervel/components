<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

// The application is analysed from this copy, so its classes load from it too.
$loader = new ClassLoader;
$loader->addPsr4('RuntimeInputs\\', dirname(__DIR__) . '/app');
$loader->register();
