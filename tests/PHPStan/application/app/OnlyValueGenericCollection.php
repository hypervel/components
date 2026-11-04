<?php

namespace App;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;

/**
 * @template TModel of Model
 *
 * @extends Collection<int, TModel>
 */
class OnlyValueGenericCollection extends Collection
{
}
