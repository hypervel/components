<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Providers\Provider;

class StoringFile
{
    /**
     * Create an event for a file being stored.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public StorableFile $file,
    ) {
    }
}
