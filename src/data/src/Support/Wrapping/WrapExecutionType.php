<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Wrapping;

enum WrapExecutionType
{
    case Disabled;
    case Enabled;
    case TemporarilyDisabled;

    /**
     * Determine if wrapping should run at the current node.
     */
    public function shouldExecute(): bool
    {
        return $this === self::Enabled;
    }
}
