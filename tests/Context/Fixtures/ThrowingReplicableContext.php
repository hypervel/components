<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context\Fixtures;

use Hypervel\Context\ReplicableContext;
use RuntimeException;

class ThrowingReplicableContext implements ReplicableContext
{
    /**
     * Fail while creating an independent context copy.
     */
    public function replicate(): static
    {
        throw new RuntimeException('Unable to replicate context.');
    }
}
