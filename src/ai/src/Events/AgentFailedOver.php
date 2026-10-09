<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Ai\Exceptions\FailoverableException;

class AgentFailedOver extends ProviderFailedOver
{
    /**
     * Create an event for an agent failing over to another provider.
     */
    public function __construct(
        public string $invocationId,
        public Agent $agent,
        public Provider $provider,
        public string $model,
        public FailoverableException $exception
    ) {
    }
}
