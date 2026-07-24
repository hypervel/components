<?php

declare(strict_types=1);

namespace Hypervel\Log;

use Hypervel\Context\ReplicableContext;

final class LoggerState implements ReplicableContext
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public array $context = [],
        public int $depth = 0
    ) {
    }

    /**
     * Copy durable context without inheriting an in-progress logging stack.
     */
    public function replicate(): static
    {
        return new self($this->context);
    }
}
