<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Providers\Provider;

class RemovingFileFromStore
{
    /**
     * Create an event for a file being removed from a store.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $storeId,
        public string $documentId,
    ) {
    }
}
