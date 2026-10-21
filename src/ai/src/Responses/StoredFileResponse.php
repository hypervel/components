<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses;

use Hypervel\Ai\Contracts\Files\HasProviderId;
use Hypervel\Ai\Responses\Concerns\HasRawResponse;

class StoredFileResponse implements HasProviderId
{
    use HasRawResponse;

    /**
     * Create a stored file response.
     */
    public function __construct(
        public readonly string $id,
    ) {
    }

    /**
     * Get the provider ID for the stored file.
     */
    public function id(): string
    {
        return $this->id;
    }
}
