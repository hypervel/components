<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel\Scaling;

final readonly class SubscriptionResult
{
    /**
     * Create a new subscription result instance.
     */
    public function __construct(
        public bool $channelOccupied,
        public bool $channelVacated,
        public bool $memberAdded,
        public bool $memberRemoved,
        public int $subscriptionCount,
    ) {
    }
}
