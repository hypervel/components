<?php

declare(strict_types=1);

namespace Hypervel\Scout\Events;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;

/**
 * Event fired when models are imported to the search index.
 *
 * @template TModel of Model&SearchableInterface
 */
class ModelsImported
{
    /**
     * @param Collection<int, TModel> $models
     */
    public function __construct(
        public readonly Collection $models
    ) {
    }
}
