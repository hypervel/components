<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Exceptions\FailoverableException;
use Hypervel\Ai\Providers\Provider;

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
