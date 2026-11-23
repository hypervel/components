<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Macros;

use Hypervel\Support\Traits\Macroable;

/**
 * A Macroable class that the analyzer bootstrap registers fixture macros on.
 */
class MacroHost
{
    use Macroable;
}
