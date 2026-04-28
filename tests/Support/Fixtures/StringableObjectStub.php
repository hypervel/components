<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Fixtures;

class StringableObjectStub
{
    private string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
