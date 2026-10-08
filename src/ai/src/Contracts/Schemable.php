<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

interface Schemable
{
    /**
     * Get the name of the schema.
     */
    public function name(): string;

    /**
     * Get the array representation of the schema.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array;
}
