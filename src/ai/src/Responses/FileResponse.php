<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses;

class FileResponse
{
    public readonly ?string $mime;

    /**
     * Create a file response.
     */
    public function __construct(
        public readonly string $id,
        ?string $mimeType = null,
        public readonly ?string $content = null,
    ) {
        $this->mime = $mimeType;
    }

    /**
     * Get the MIME type for the file.
     */
    public function mimeType(): ?string
    {
        return $this->mime;
    }

    /**
     * Get the file's content.
     */
    public function content(): ?string
    {
        return $this->content;
    }
}
