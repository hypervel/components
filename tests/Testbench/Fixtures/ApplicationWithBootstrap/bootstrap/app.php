<?php

declare(strict_types=1);

use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Tests\Testbench\Fixtures\BootstrapFileApplication;

$app = BootstrapFileApplication::configure($APP_BASE_PATH)->create();
$app->bootstrapFile = __FILE__;
$app->beforeBootstrapping(LoadConfiguration::class, static function () use ($app): void {
    ++$app->frameworkBootstrapCount;
});

return $app;
