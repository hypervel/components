<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Hypervel\Ai\Files\Concerns\HasRemoteContent;
use Hypervel\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

class RemoteImage extends Image implements Arrayable, JsonSerializable, StorableFile
{
    use CanBeUploadedToProvider;
    use HasRemoteContent;

    /**
     * Create a new remote image.
     */
    public function __construct(public string $url, ?string $mimeType = null)
    {
        if (blank($url)) {
            throw new InvalidArgumentException('Remote image URL cannot be empty.');
        }

        $this->mime = $mimeType;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'remote-image',
            'name' => $this->name(),
            'url' => $this->url,
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
}
