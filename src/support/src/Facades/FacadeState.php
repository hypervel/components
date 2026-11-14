<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Context\NonCopyableContext;

class FacadeState implements NonCopyableContext
{
    /**
     * The coroutine-local facade roots, keyed by accessor, with the generation each was resolved in.
     *
     * @var array<string, array{int, mixed}>
     */
    public array $roots = [];
}
