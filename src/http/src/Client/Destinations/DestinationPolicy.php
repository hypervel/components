<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Destinations;

interface DestinationPolicy
{
    /**
     * Resolve and authorize one outbound destination within the given time budget.
     *
     * @throws DisallowedDestinationException
     * @throws DestinationResolutionException
     * @throws ProxyConnectionException
     */
    public function resolve(string $url, float $timeoutSeconds): ResolvedDestination;
}
