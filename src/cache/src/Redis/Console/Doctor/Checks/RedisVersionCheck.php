<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Redis\RedisConnection;
use Throwable;

/**
 * Checks that Redis/Valkey version meets requirements.
 *
 * For any mode: Requires Redis 8.0+ or Valkey 9.0+ for HEXPIRE support.
 * For all mode: Any version is acceptable (just verifies connection).
 */
final class RedisVersionCheck implements EnvironmentCheckInterface
{
    private const REDIS_REQUIRED_VERSION = '8.0.0';

    private const VALKEY_REQUIRED_VERSION = '9.0.0';

    private ?string $serviceName = null;

    private ?string $serviceVersion = null;

    private bool $connectionFailed = false;

    public function __construct(
        private readonly RedisConnection $redis,
        private readonly string $taggingMode,
    ) {
    }

    public function name(): string
    {
        return 'Redis/Valkey Version';
    }

    public function run(): CheckResult
    {
        $result = new CheckResult();

        try {
            $info = $this->redis->info('server');

            if (isset($info['valkey_version'])) {
                $this->serviceName = 'Valkey';
                $this->serviceVersion = $info['valkey_version'];
                $requiredVersion = self::VALKEY_REQUIRED_VERSION;
            } elseif (isset($info['redis_version'])) {
                $this->serviceName = 'Redis';
                $this->serviceVersion = $info['redis_version'];
                $requiredVersion = self::REDIS_REQUIRED_VERSION;
            } else {
                $result->assert(false, 'Could not determine Redis/Valkey version');

                return $result;
            }

            $result->assert(true, "{$this->serviceName} server is reachable (v{$this->serviceVersion})");

            // Version requirement only applies to any mode
            if ($this->taggingMode === 'any') {
                $versionOk = version_compare($this->serviceVersion, $requiredVersion, '>=');
                $result->assert(
                    $versionOk,
                    "{$this->serviceName} version >= {$requiredVersion} (required for any tagging mode)"
                );
            } else {
                $result->assert(
                    true,
                    "{$this->serviceName} version check skipped (all mode has no version requirement)"
                );
            }
        } catch (Throwable $e) {
            $this->connectionFailed = true;
            $result->assert(false, 'Redis/Valkey server is reachable: ' . $e->getMessage());
        }

        return $result;
    }

    public function getFixInstructions(): ?string
    {
        if ($this->connectionFailed) {
            return 'Ensure Redis/Valkey server is running and accessible';
        }

        if ($this->taggingMode !== 'any') {
            return null;
        }

        if ($this->serviceName === 'Redis' && $this->serviceVersion !== null) {
            if (version_compare($this->serviceVersion, self::REDIS_REQUIRED_VERSION, '<')) {
                return 'Upgrade to Redis ' . self::REDIS_REQUIRED_VERSION . '+ or Valkey ' . self::VALKEY_REQUIRED_VERSION . '+ for any tagging mode';
            }
        }

        if ($this->serviceName === 'Valkey' && $this->serviceVersion !== null) {
            if (version_compare($this->serviceVersion, self::VALKEY_REQUIRED_VERSION, '<')) {
                return 'Upgrade to Valkey ' . self::VALKEY_REQUIRED_VERSION . '+ for any tagging mode';
            }
        }

        return null;
    }
}
