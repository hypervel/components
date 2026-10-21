<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Fixtures;

use Hypervel\Core\Events\OnStart;
use Hypervel\Support\ServiceProvider;

class ServeMasterReadyServiceProvider extends ServiceProvider
{
    /**
     * Report when the serve master has finished its start listeners.
     */
    public function boot(): void
    {
        // Configured Hypervel providers boot before discovered ones such as the server provider,
        // so register once every provider has booted to run after the server's OnStart listeners.
        $this->app->booted(function (): void {
            $this->app->make('events')->listen(OnStart::class, static function (): void {
                fwrite(STDOUT, 'serve master ready' . PHP_EOL);
            });
        });
    }
}
