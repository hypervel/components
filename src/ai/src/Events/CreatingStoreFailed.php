<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use DateInterval;
use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Support\Collection;
use Throwable;

class CreatingStoreFailed
{
    /**
     * Create an event for a failed store creation.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $name,
        public ?string $description,
        public Collection $fileIds,
        public ?DateInterval $expiresWhenIdleFor,
        public Throwable $exception,
    ) {
    }
}
