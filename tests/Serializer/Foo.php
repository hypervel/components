<?php

declare(strict_types=1);

namespace Hypervel\Tests\Serializer;

use JsonSerializable;

class Foo implements JsonSerializable
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }

    public function jsonSerialize(): mixed
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }

    public static function jsonDeSerialize(mixed $data): static
    {
        return new static($data['id'], $data['name']);
    }
}
