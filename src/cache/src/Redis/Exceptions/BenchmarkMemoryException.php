<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Exceptions;

use RuntimeException;

/**
 * Exception thrown when the benchmark command runs out of memory.
 *
 * This exception is thrown proactively before PHP's fatal memory error,
 * allowing the benchmark to fail gracefully with actionable guidance.
 */
class BenchmarkMemoryException extends RuntimeException
{
    /**
     * Create a new benchmark memory exception instance.
     */
    public function __construct(
        public readonly int $currentUsageBytes,
        public readonly int $limitBytes,
        public readonly int $usagePercent,
    ) {
        $currentMB = round($currentUsageBytes / 1024 / 1024);
        $limitMB = round($limitBytes / 1024 / 1024);

        $message = <<<EOT
Memory limit nearly exhausted during benchmark ({$currentMB}MB of {$limitMB}MB used, {$usagePercent}%).

To resolve this issue:

1. Increase PHP memory limit:
   - Add to your command: php -d memory_limit=512M artisan cache:redis-benchmark
   - Or set in php.ini: memory_limit = 512M

2. Disable memory-hungry packages during benchmarking:
   - Hypervel Telescope: Set TELESCOPE_ENABLED=false in .env
   - Disable other event listeners that accumulate data

3. Use a smaller scale:
   - Try --scale=small (1,000 items) instead of medium/large

4. Run with fewer benchmark runs:
   - Use --runs=1 instead of the default 3

Recommended memory limits by scale:
  - small:   256MB
  - medium:  512MB
  - large:   1GB
  - extreme: 2GB+
EOT;

        parent::__construct($message);
    }
}
