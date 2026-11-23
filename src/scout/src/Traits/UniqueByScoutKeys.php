<?php

declare(strict_types=1);

namespace Hypervel\Scout\Traits;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;

trait UniqueByScoutKeys
{
    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    // @phpstan-ignore hypervel.legacyQueueProperty (public property; removing it would leave assignments to it without declared storage)
    public $uniqueFor = 3600;

    /**
     * Get the unique identifier for the job.
     */
    public function uniqueId(): string
    {
        return hash('sha256', json_encode([
            $this->models->getQueueableClass(),
            $this->models->map(function (Model $model) {
                /** @var Model&SearchableInterface $model */
                return $model->getScoutKey();
            })->sort()->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
