<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Listeners;

use Hypervel\Support\DotenvManager;
use Hypervel\Watcher\Events\BeforeServerRestart;

class ReloadDotenvListener
{
    /**
     * Handle the event.
     */
    public function handle(BeforeServerRestart $event): void
    {
        if (file_exists(BASE_PATH . '/.env')) {
            DotenvManager::reload([BASE_PATH]);
        }
    }
}
