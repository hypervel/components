<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Redis\RedisConnection;
use Throwable;

/**
 * Checks that hash-field expiration commands are available.
 *
 * Any tag mode depends on Redis/Valkey hash-field expiration commands:
 * HSETEX for tagged writes and HEXPIRE when plain touch updates tagged keys.
 *
 * For all mode, this check is skipped (hash-field expiration is not needed).
 */
final class HashFieldExpirationCheck implements EnvironmentCheckInterface
{
    private bool $available = false;

    public function __construct(
        private readonly RedisConnection $redis,
        private readonly string $taggingMode,
    ) {
    }

    public function name(): string
    {
        return 'Hash Field Expiration Commands';
    }

    public function run(): CheckResult
    {
        $result = new CheckResult;

        if ($this->taggingMode === 'all') {
            $result->assert(true, 'Hash-field expiration check skipped (not required for all mode)');

            return $result;
        }

        $testKey = 'erc:doctor:hash-field-expiration-test:' . bin2hex(random_bytes(4));

        try {
            $this->redis->hsetex($testKey, ['field' => '1'], ['EX' => 60]);
            $this->redis->hexpire($testKey, 60, ['field']);

            $this->available = true;
            $result->assert(true, 'HSETEX and HEXPIRE commands are available');
        } catch (Throwable) {
            $this->available = false;
            $result->assert(false, 'HSETEX and HEXPIRE commands are available');
        } finally {
            try {
                $this->redis->del($testKey);
            } catch (Throwable) {
                // The command probe result above is the failure that matters.
            }
        }

        return $result;
    }

    public function getFixInstructions(): ?string
    {
        if ($this->taggingMode === 'all') {
            return null;
        }

        if (! $this->available) {
            return 'Any tagging mode requires Redis 8.0+ or Valkey 9.0+ for hash-field expiration commands such as HSETEX and HEXPIRE. Upgrade your Redis/Valkey server, or switch to all tagging mode.';
        }

        return null;
    }
}
