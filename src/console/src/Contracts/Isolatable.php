<?php

declare(strict_types=1);

namespace Hypervel\Console\Contracts;

/**
 * Marker interface for commands that should only run one instance at a time.
 *
 * When a command implements this interface, an --isolated option is added
 * that prevents concurrent execution using a cache-based mutex.
 */
interface Isolatable
{
    //
}
