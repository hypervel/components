<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Data;

use Closure;
use Hypervel\Saloon\Enums\PipeOrder;

final readonly class Pipe
{
    /**
     * The callable inside the pipe.
     */
    public Closure $callable;

    /**
     * Create a middleware pipe.
     *
     * @param callable(mixed $payload): (mixed) $callable
     */
    public function __construct(
        callable $callable,
        public ?string $name = null,
        public ?PipeOrder $order = null,
    ) {
        $this->callable = $callable(...);
    }
}
