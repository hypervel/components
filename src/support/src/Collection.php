<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hyperf\Collection\Collection as BaseCollection;

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends \Hyperf\Collection\Collection<TKey, TValue>
 */
class Collection extends BaseCollection
{
}
