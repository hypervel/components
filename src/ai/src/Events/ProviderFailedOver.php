<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Ai\Exceptions\FailoverableException;

class ProviderFailedOver
{
    /**
     * Create an event for a provider failover.
     */
    public function __construct(
        public Provider $provider,
        public string $model,
        public FailoverableException $exception
    ) {
    }
}
