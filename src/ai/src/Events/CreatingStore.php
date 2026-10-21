<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use DateInterval;
use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Support\Collection;

class CreatingStore
{
    /**
     * Create an event for a store being created.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $name,
        public ?string $description,
        public Collection $fileIds,
        public ?DateInterval $expiresWhenIdleFor,
    ) {
    }
}
