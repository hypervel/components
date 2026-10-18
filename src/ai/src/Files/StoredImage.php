<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Hypervel\Ai\Files\Concerns\HasStoredContent;
use Hypervel\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

class StoredImage extends Image implements Arrayable, JsonSerializable, StorableFile
{
    use CanBeUploadedToProvider;
    use HasStoredContent;

    /**
     * Create a new stored image.
     */
    public function __construct(public string $path, public ?string $disk = null)
    {
        if (blank($path)) {
            throw new InvalidArgumentException('Image file path cannot be empty.');
        }
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'stored-image',
            'name' => $this->name(),
            'path' => $this->path,
            'disk' => $this->disk ?? config()->string('filesystems.default'),
        ];
    }
}
