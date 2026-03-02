<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Contracts\Console\Kernel as ConsoleKernel;

trait WithConsoleEvents
{
    /**
     * Register console events.
     */
    protected function setUpWithConsoleEvents(): void
    {
        $this->app[ConsoleKernel::class]->rerouteSymfonyCommandEvents();
    }
}
