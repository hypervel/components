<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Macros;

/**
 * An invokable object registered as a macro.
 */
class InvokableMacro
{
    /**
     * Run the macro.
     */
    public function __invoke(int &$value): int
    {
        return $value;
    }
}
