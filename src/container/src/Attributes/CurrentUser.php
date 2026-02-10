<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class CurrentUser extends Authenticated
{
    //
}
