<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Pipeline;

interface Hub
{
    /**
     * Send an object through one of the available pipelines.
     */
    public function pipe(mixed $object, ?string $pipeline = null): mixed;
}
