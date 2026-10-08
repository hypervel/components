<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files;

use Hypervel\Ai\Contracts\Files\HasProviderId;
use Hypervel\Ai\Files\Concerns\CanBeRetrievedOrDeletedFromProvider;
use Hypervel\Contracts\Support\Arrayable;
use JsonSerializable;

class ProviderImage extends Image implements Arrayable, HasProviderId, JsonSerializable
{
    use CanBeRetrievedOrDeletedFromProvider;

    /**
     * Create a new provider image reference.
     */
    public function __construct(public string $id)
    {
    }

    /**
     * Get the provider ID for the stored file.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'provider-image',
            'id' => $this->id,
            'name' => $this->name(),
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
