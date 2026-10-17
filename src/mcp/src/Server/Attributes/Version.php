<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Version extends ServerAttribute
{
}
