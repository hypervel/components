<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Support;

interface DeferrableProvider
{
    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array;
}
