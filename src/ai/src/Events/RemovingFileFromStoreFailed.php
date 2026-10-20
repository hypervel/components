<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Providers\Provider;
use Throwable;

class RemovingFileFromStoreFailed
{
    /**
     * Create an event for a failed attempt to remove a file from a store.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $storeId,
        public string $documentId,
        public Throwable $exception,
    ) {
    }
}
