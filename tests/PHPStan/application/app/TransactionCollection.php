<?php

namespace App;

use Hypervel\Database\Eloquent\Collection;

/**
 * @template TKey of array-key
 * @template TModel
 *
 * @extends Collection<TKey, TModel>
 */
class TransactionCollection extends Collection
{
}
