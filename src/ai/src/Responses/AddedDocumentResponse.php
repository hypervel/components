<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses;

use Hypervel\Ai\Contracts\Files\HasProviderId;

class AddedDocumentResponse implements HasProviderId
{
    /**
     * Create an added document response.
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $fileId = null,
    ) {
    }

    /**
     * Get the provider document ID for the file that was added to the vector store.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Get the provider ID for the file that was stored for later reference, if applicable.
     */
    public function fileId(): ?string
    {
        return $this->fileId;
    }
}
