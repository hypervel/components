<?php

declare(strict_types=1);

namespace Hypervel\Support\Contracts;

interface Jsonable
{
    /**
     * Convert the object to its JSON representation.
     */
    public function toJson(int $options = 0): string;
}
