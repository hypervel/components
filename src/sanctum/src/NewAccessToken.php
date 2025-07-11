<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hypervel\Support\Contracts\Arrayable;
use Hypervel\Support\Contracts\Jsonable;

class NewAccessToken implements Arrayable, Jsonable
{
    /**
     * Create a new access token result.
     */
    public function __construct(
        public PersonalAccessToken $accessToken,
        public string $plainTextToken
    ) {
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'accessToken' => $this->accessToken,
            'plainTextToken' => $this->plainTextToken,
        ];
    }

    /**
     * Convert the object to its JSON representation.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Convert the object to its string representation.
     */
    public function __toString(): string
    {
        return $this->toJson();
    }
}