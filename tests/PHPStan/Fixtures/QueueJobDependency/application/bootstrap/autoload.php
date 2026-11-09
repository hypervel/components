<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

// The jobs are analysed from this copy of the application, so they load from it too.
$loader = new ClassLoader;
$loader->addPsr4('QueueJobDependency\Jobs\\', dirname(__DIR__) . '/app/Jobs');
$loader->register();
