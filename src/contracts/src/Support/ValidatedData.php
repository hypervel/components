<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Support;

use ArrayAccess;
use IteratorAggregate;

interface ValidatedData extends Arrayable, ArrayAccess, IteratorAggregate
{
}
