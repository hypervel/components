<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

// PHPStan registers the extensions in this copy of the application, so they load from it too.
$loader = new ClassLoader;
$loader->addPsr4('AttributeServices\Extensions\\', dirname(__DIR__) . '/extensions');
$loader->register();
