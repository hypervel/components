<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Providers\Provider;
use Throwable;

class AddingFileToStoreFailed
{
    /**
     * Create an event for a failed attempt to add a file to a store.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $storeId,
        public string $fileId,
        public Throwable $exception,
    ) {
    }
}
