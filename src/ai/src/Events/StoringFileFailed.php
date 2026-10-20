<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Providers\Provider;
use Throwable;

class StoringFileFailed
{
    /**
     * Create an event for a failed file upload.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public StorableFile $file,
        public Throwable $exception,
    ) {
    }
}
