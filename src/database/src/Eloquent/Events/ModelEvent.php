<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Events;

use Hypervel\Database\Eloquent\Model;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Base class for all Eloquent model events.
 */
abstract class ModelEvent implements StoppableEventInterface
{
    protected bool $propagationStopped = false;

    /**
     * The event method name (e.g., 'creating', 'created').
     */
    public readonly string $method;

    public function __construct(
        public readonly Model $model,
        ?string $method = null,
    ) {
        $this->method = $method ?? lcfirst(class_basename(static::class));
    }

    /**
     * Is propagation stopped?
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * Stop event propagation.
     */
    public function stopPropagation(): static
    {
        $this->propagationStopped = true;

        return $this;
    }
}
