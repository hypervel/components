<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Hypervel\Ai\Files\Concerns\HasRemoteContent;
use Hypervel\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

class RemoteDocument extends Document implements Arrayable, JsonSerializable, StorableFile
{
    use CanBeUploadedToProvider;
    use HasRemoteContent;

    /**
     * Create a new remote document.
     */
    public function __construct(public string $url, ?string $mimeType = null)
    {
        if (blank($url)) {
            throw new InvalidArgumentException('Remote document URL cannot be empty.');
        }

        $this->mime = $mimeType;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'remote-document',
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
