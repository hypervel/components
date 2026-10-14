<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;

/**
 * @implements Arrayable<array<string, string>>
 */
class Scope implements Arrayable, Jsonable, Transient
{
    /**
     * Create a new scope instance.
     */
    public function __construct(
        public string $id,
        public string $description,
    ) {
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
        ];
    }

    /**
     * Convert the object to its JSON representation.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }
}
