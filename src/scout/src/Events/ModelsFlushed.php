<?php

declare(strict_types=1);

namespace Hypervel\Scout\Events;

use Hypervel\Database\Eloquent\Collection;

/**
 * Event fired when models are flushed from the search index.
 */
class ModelsFlushed
{
    public function __construct(
        public readonly Collection $models
    ) {
    }
}
