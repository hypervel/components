<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Events;

use Hypervel\Database\Eloquent\Model;

/**
 * Base class for all Eloquent model events.
 */
abstract class ModelEvent
{
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
}
