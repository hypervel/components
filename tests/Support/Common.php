<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use ArrayIterator;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

class TestArrayableObject implements Arrayable
{
    public function toArray(): array
    {
        return ['foo' => 'bar'];
    }
}

class TestJsonableObject implements Jsonable
{
    public function toJson($options = 0): string
    {
        return '{"foo":"bar"}';
    }
}

class TestJsonSerializeObject implements JsonSerializable
{
    public function jsonSerialize(): array
    {
        return ['foo' => 'bar'];
    }
}

class TestJsonSerializeWithScalarValueObject implements JsonSerializable
{
    public function jsonSerialize(): string
    {
        return 'foo';
    }
}

class TestTraversableAndJsonSerializableObject implements IteratorAggregate, JsonSerializable
{
    public array $items;

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function jsonSerialize(): array
    {
        return json_decode(json_encode($this->items), true);
    }
}
