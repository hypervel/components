<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Collections;

use Hypervel\Support\Collection;

/**
 * @template TKey of array-key
 * @template TData of \Hypervel\Tests\Data\Fixtures\SimpleData
 *
 * @extends \Hypervel\Support\Collection<TKey, TData>
 */
class SimpleDataCollectionWithAnotations extends Collection
{
}
