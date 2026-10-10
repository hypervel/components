<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Wrapping;

enum WrapExecutionType: string
{
    case Disabled = 'Disabled';
    case Enabled = 'Enabled';
    case TemporarilyDisabled = 'TemporarilyDisabled';

    /**
     * Determine if wrapping should run at the current node.
     */
    public function shouldExecute(): bool
    {
        return $this === self::Enabled;
    }
}
