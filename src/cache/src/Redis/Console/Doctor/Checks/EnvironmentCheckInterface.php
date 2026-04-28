<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;

/**
 * Interface for environment/requirement checks.
 *
 * Environment checks verify system requirements (PHP extensions, Redis version, etc.)
 * and run BEFORE the full DoctorContext is created. They fail fast if requirements
 * aren't met, preventing functional checks from running.
 */
interface EnvironmentCheckInterface
{
    /**
     * Get the human-readable name of this check.
     */
    public function name(): string;

    /**
     * Run the check and return results.
     */
    public function run(): CheckResult;

    /**
     * Get details about how to fix a failed check.
     * Returns null if no specific fix instructions are needed.
     */
    public function getFixInstructions(): ?string;
}
