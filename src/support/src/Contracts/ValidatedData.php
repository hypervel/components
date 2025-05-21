<?php

declare(strict_types=1);

namespace Hypervel\Support\Contracts;

use ArrayAccess;
use IteratorAggregate;

interface ValidatedData extends Arrayable, ArrayAccess, IteratorAggregate
{
}
