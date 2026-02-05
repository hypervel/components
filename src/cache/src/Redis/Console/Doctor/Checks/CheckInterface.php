<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Interface for functional Doctor checks.
 *
 * Functional checks test actual cache operations and require
 * a fully initialized DoctorContext with cache, store, and Redis connection.
 */
interface CheckInterface
{
    /**
     * Get the human-readable name of this check.
     */
    public function name(): string;

    /**
     * Run the check and return results.
     */
    public function run(DoctorContext $context): CheckResult;
}
