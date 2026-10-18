<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files\Concerns;

use Hypervel\Filesystem\Filesystem;
use RuntimeException;

trait HasLocalContent
{
    /**
     * Get the raw representation of the file.
     *
     * @throws RuntimeException if the file does not exist at the configured path
     */
    public function content(): string
    {
        $content = file_get_contents($this->path);

        if ($content === false) {
            throw new RuntimeException("File does not exist at path [{$this->path}]");
        }

        return $content;
    }

    /**
     * Get the displayable name of the file.
     */
    public function name(): ?string
    {
        return $this->name ?? basename($this->path);
    }

    /**
     * Get the file's MIME type.
     */
    public function mimeType(): ?string
    {
        return $this->mime ?? ((new Filesystem)->mimeType($this->path) ?: null);
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get the file content as a string.
     */
    public function __toString(): string
    {
        return $this->content();
    }
}
