<?php

declare(strict_types=1);

namespace Hypervel\Pagination;

use Hypervel\Support\ServiceProvider;

class PaginationServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        PaginationState::resolveUsing($this->app);
    }
}
