<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use DateInterval;
use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Ai\Store;
use Hypervel\Support\Collection;

class StoreCreated
{
    /**
     * Create an event for a created store.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $name,
        public ?string $description,
        public Collection $fileIds,
        public ?DateInterval $expiresWhenIdleFor,
        public Store $store,
    ) {
    }
}
