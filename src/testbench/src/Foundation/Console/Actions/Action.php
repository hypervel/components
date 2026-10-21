<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console\Actions;

/**
 * @api
 */
abstract class Action
{
    /**
     * Determine if action should only be pretended.
     */
    protected bool $pretending = false;
}
