<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses\Data;

use Hypervel\Ai\Concerns\Storable;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Str;
use JsonSerializable;
use Stringable;

class GeneratedImage implements Stringable, Arrayable, JsonSerializable
{
    use Storable;

    public ?string $mime = null;

    /**
     * Create a generated image.
     *
     * @param string $image the Base64 representation of the image
     */
    public function __construct(
        public string $image,
        ?string $mimeType = null,
    ) {
        $this->mime = $mimeType;
    }

    /**
     * Get the image's MIME type, falling back to a sensible default.
     */
    public function mime(): string
    {
        return $this->mime ?: 'image/png';
    }

    /**
     * Get a default filename for the file.
     */
    protected function randomStorageName(): string
    {
        return $this->randomStorageName ??= Str::random(40) . match ($this->mime()) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
            default => '.png',
        };
    }

    /**
     * Get the raw string content of the image.
     */
    public function content(): string
    {
        return base64_decode($this->image);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'image' => $this->image,
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
     * Get the raw string content of the image.
     */
    public function __toString(): string
    {
        return $this->content();
    }
}
