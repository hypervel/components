<?php

declare(strict_types=1);

namespace Hypervel\Scout\Events;

use Hypervel\Database\Eloquent\Collection;

/**
 * Event fired when models are imported to the search index.
 */
class ModelsImported
{
    public function __construct(
        public readonly Collection $models
    ) {
    }
}
