<?php

declare(strict_types=1);

namespace RuntimeInputs;

use Closure;

class StrMixin
{
    /**
     * Get the fixture mixin macro.
     */
    public function fixtureMixin(): Closure
    {
        return static fn (): int => 1;
    }
}
