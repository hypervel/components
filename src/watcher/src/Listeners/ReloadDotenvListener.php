<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Listeners;

use Hypervel\Foundation\Application;
use Hypervel\Support\DotenvManager;
use Hypervel\Watcher\Events\BeforeServerRestart;

class ReloadDotenvListener
{
    public function __construct(
        protected Application $app,
    ) {
    }

    /**
     * Handle the event.
     */
    public function handle(BeforeServerRestart $event): void
    {
        if (! file_exists($this->app->environmentFilePath())) {
            return;
        }

        DotenvManager::reload(
            [$this->app->environmentPath()],
            $this->app->environmentFile(),
        );
    }
}
