<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Fixtures;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Exceptions\Handler;
use Hypervel\Support\ServiceProvider;
use RuntimeException;

class ComposerUninstallServiceProvider extends ServiceProvider
{
    /**
     * Register package cleanup listeners in the child application.
     */
    public function boot(Dispatcher $events, Filesystem $files, ExceptionHandler $exceptions): void
    {
        /** @var Handler $exceptions */
        $exceptions->dontReport(RuntimeException::class);

        $marker = $this->app->storagePath('composer-uninstall-events.log');
        $events->listen('composer_package.example/*:pre_uninstall', static function (string $event) use ($files, $marker): void {
            $files->append($marker, $event . PHP_EOL);

            if ($event === 'composer_package.example/failing:pre_uninstall') {
                throw new RuntimeException('Package cleanup failed.');
            }
        });
    }
}
