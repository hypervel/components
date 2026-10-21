<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files;

use Hypervel\Ai\Contracts\Files\HasProviderId;
use Hypervel\Ai\Files\Concerns\CanBeRetrievedOrDeletedFromProvider;
use Hypervel\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

class ProviderDocument extends Document implements Arrayable, HasProviderId, JsonSerializable
{
    use CanBeRetrievedOrDeletedFromProvider;

    /**
     * Create a new provider document reference.
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
     * Reject direct content access for a provider reference.
     *
     * @throws InvalidArgumentException
     */
    public function content(): string
    {
        throw new InvalidArgumentException(
            'ProviderDocument cannot be read directly. It is a reference to a file stored on an external provider.'
        );
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'provider-document',
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
