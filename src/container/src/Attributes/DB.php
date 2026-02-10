<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class DB extends Database
{
    //
}
