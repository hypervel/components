<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Hypervel\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

class Base64Image extends Image implements Arrayable, JsonSerializable, StorableFile
{
    use CanBeUploadedToProvider;

    /**
     * Create a new Base64 image.
     */
    public function __construct(public string $base64, ?string $mimeType = null)
    {
        if (blank($base64)) {
            throw new InvalidArgumentException('Base64 image content cannot be empty.');
        }

        $this->mime = $mimeType;
    }

    /**
     * Get the raw representation of the file.
     */
    public function content(): string
    {
        return base64_decode($this->base64);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'base64-image',
            'name' => $this->name(),
            'base64' => $this->base64,
            'mime' => $this->mime,
        ];
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
