<?php

declare(strict_types=1);

use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Foundation\Configuration\Middleware;
use Hypervel\Routing\Middleware\SubstituteBindings;
use Hypervel\Tests\Testbench\Fixtures\BootstrapFileApplication;

$app = BootstrapFileApplication::configure($APP_BASE_PATH)
    ->withMiddleware(static function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/fixture-login');
        $middleware->alias(['fixture-alias' => SubstituteBindings::class]);
        $middleware->web(append: ['fixture-alias']);
    })
    ->create();
$app->bootstrapFile = __FILE__;
$app->beforeBootstrapping(LoadConfiguration::class, static function () use ($app): void {
    ++$app->frameworkBootstrapCount;
});

return $app;
