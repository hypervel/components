<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hyperf\Collection\Collection as BaseCollection;
use Hypervel\Support\Traits\TransformsToResourceCollection;

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends \Hyperf\Collection\Collection<TKey, TValue>
 */
class Collection extends BaseCollection
{
    use TransformsToResourceCollection;
}
