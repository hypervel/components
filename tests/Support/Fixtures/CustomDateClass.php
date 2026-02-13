<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Fixtures;

class CustomDateClass
{
    protected mixed $original;

    public function __construct(mixed $original)
    {
        $this->original = $original;
    }

    public static function instance(mixed $original): static
    {
        return new static($original);
    }

    public function getOriginal(): mixed
    {
        return $this->original;
    }
}
