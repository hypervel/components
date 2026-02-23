<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Stubs;

use Hypervel\Contracts\Support\Arrayable;

class ArrayableStubObject implements Arrayable
{
    public function __construct(protected array $data = [])
    {
    }

    public static function make(array $data = []): static
    {
        return new static($data);
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
