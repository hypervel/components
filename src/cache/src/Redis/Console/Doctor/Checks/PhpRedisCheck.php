<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;

/**
 * Checks that PHPRedis extension is installed with required version.
 *
 * Requires phpredis ≥6.3.0 for full feature support (HSETEX, etc.).
 */
final class PhpRedisCheck implements EnvironmentCheckInterface
{
    private const REQUIRED_VERSION = '6.3.0';

    private ?string $installedVersion = null;

    public function name(): string
    {
        return 'PHPRedis Extension';
    }

    public function run(): CheckResult
    {
        $result = new CheckResult();

        if (! extension_loaded('redis')) {
            $result->assert(false, 'PHPRedis extension is installed');

            return $result;
        }

        $this->installedVersion = phpversion('redis') ?: 'unknown';

        $result->assert(true, "PHPRedis extension is installed (v{$this->installedVersion})");

        $versionOk = version_compare($this->installedVersion, self::REQUIRED_VERSION, '>=');
        $result->assert(
            $versionOk,
            'PHPRedis version >= ' . self::REQUIRED_VERSION
        );

        return $result;
    }

    public function getFixInstructions(): ?string
    {
        if (! extension_loaded('redis')) {
            return 'Install PHPRedis: pecl install redis';
        }

        if ($this->installedVersion !== null && version_compare($this->installedVersion, self::REQUIRED_VERSION, '<')) {
            return "Upgrade PHPRedis: pecl upgrade redis (current: {$this->installedVersion}, required: " . self::REQUIRED_VERSION . '+)';
        }

        return null;
    }
}
