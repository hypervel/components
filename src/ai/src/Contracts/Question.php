<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

interface Question
{
    /**
     * Get the question as a provider-neutral array.
     */
    public function toArray(): array;
}
