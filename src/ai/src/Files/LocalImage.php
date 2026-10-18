<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Hypervel\Ai\Files\Concerns\HasLocalContent;
use Hypervel\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

class LocalImage extends Image implements Arrayable, JsonSerializable, StorableFile
{
    use CanBeUploadedToProvider;
    use HasLocalContent;

    /**
     * Create a new local image.
     */
    public function __construct(public string $path, ?string $mimeType = null)
    {
        if (blank($path)) {
            throw new InvalidArgumentException('Image file path cannot be empty.');
        }

        $this->mime = $mimeType;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'local-image',
            'name' => $this->name(),
            'path' => $this->path,
            'mime' => $this->mime,
        ];
    }
}
