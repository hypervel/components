<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Providers\Provider;

class FileDeleted
{
    /**
     * Create an event for a deleted file.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $fileId,
    ) {
    }
}
