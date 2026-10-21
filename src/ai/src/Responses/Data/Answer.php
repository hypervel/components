<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses\Data;

use Hypervel\Contracts\Support\Arrayable;
use JsonSerializable;

abstract class Answer implements Arrayable, JsonSerializable
{
    /**
     * Get the instance as an array.
     */
    abstract public function toArray(): array;

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
