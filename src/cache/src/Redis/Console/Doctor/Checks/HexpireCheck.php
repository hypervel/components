<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Redis\RedisConnection;
use Throwable;

/**
 * Checks that the HEXPIRE command is available.
 *
 * HEXPIRE is required for hash field expiration, which is essential
 * for the any tagging mode implementation.
 *
 * For all mode, this check is skipped (HEXPIRE not needed).
 */
final class HexpireCheck implements EnvironmentCheckInterface
{
    private bool $available = false;

    public function __construct(
        private readonly RedisConnection $redis,
        private readonly string $taggingMode,
    ) {}

    public function name(): string
    {
        return 'HEXPIRE Command';
    }

    public function run(): CheckResult
    {
        $result = new CheckResult();

        // Skip check for all mode - HEXPIRE not needed
        if ($this->taggingMode === 'all') {
            $result->assert(true, 'HEXPIRE check skipped (not required for all mode)');

            return $result;
        }

        try {
            // Try to use HEXPIRE on a test key
            $testKey = 'erc:doctor:hexpire-test:' . bin2hex(random_bytes(4));

            $this->redis->hset($testKey, 'field', '1');
            $this->redis->hexpire($testKey, 60, ['field']);
            $this->redis->del($testKey);

            $this->available = true;
            $result->assert(true, 'HEXPIRE command is available');
        } catch (Throwable) {
            $this->available = false;
            $result->assert(false, 'HEXPIRE command is available');
        }

        return $result;
    }

    public function getFixInstructions(): ?string
    {
        if ($this->taggingMode === 'all') {
            return null;
        }

        if (! $this->available) {
            return 'HEXPIRE requires Redis 8.0+ or Valkey 9.0+. Upgrade your Redis/Valkey server, or switch to all tagging mode.';
        }

        return null;
    }
}
