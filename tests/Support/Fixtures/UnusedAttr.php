<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Fixtures;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class UnusedAttr
{
}
