<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Providers\Provider;

class AddingFileToStore
{
    /**
     * Create an event for a file being added to a store.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $storeId,
        public string $fileId,
    ) {
    }
}
