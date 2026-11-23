<?php

declare(strict_types=1);

namespace ModelPropertyDependency\Models;

/**
 * Builder calls through a model whose attribute names its builder by another class's constant.
 */
class MemberUsage
{
    /**
     * Call a method of the builder the constant names.
     */
    public function published(): void
    {
        Member::query()->published();
    }
}
