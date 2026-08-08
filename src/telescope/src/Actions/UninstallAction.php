<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Actions;

use Hypervel\Support\ServiceProvider;

class UninstallAction
{
    /**
     * Remove Telescope's application service provider.
     */
    public function handle(): void
    {
        ServiceProvider::removeProviderFromBootstrapFile('TelescopeServiceProvider');
    }
}
