<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Ai\Responses\StoredFileResponse;

class FileStored
{
    /**
     * Create an event for a stored file.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public StorableFile $file,
        public StoredFileResponse $response,
    ) {
    }
}
