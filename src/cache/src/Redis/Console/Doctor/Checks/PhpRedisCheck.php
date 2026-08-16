<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;

/**
 * Checks that PHPRedis is installed with the version required by the tag mode.
 */
final class PhpRedisCheck implements EnvironmentCheckInterface
{
    private const string MINIMUM_VERSION = '6.1.0';

    private const string ANY_TAG_MINIMUM_VERSION = '6.3.0';

    private ?string $installedVersion = null;

    /**
     * Create a new PHPRedis check instance.
     */
    public function __construct(
        private readonly string $taggingMode,
    ) {
    }

    public function name(): string
    {
        return 'PHPRedis Extension';
    }

    public function run(): CheckResult
    {
        $result = new CheckResult;

        if (! extension_loaded('redis')) {
            $result->assert(false, 'PHPRedis extension is installed');

            return $result;
        }

        $this->installedVersion = phpversion('redis') ?: 'unknown';

        $result->assert(true, "PHPRedis extension is installed (v{$this->installedVersion})");

        $requiredVersion = $this->requiredVersion();
        $versionOk = version_compare($this->installedVersion, $requiredVersion, '>=');
        $result->assert(
            $versionOk,
            'PHPRedis version >= ' . $requiredVersion
        );

        return $result;
    }

    public function getFixInstructions(): ?string
    {
        if (! extension_loaded('redis')) {
            return 'Install PHPRedis: pie install phpredis/phpredis';
        }

        $requiredVersion = $this->requiredVersion();

        if ($this->installedVersion !== null && version_compare($this->installedVersion, $requiredVersion, '<')) {
            return "Upgrade PHPRedis: pie install phpredis/phpredis (current: {$this->installedVersion}, required: {$requiredVersion}+)";
        }

        return null;
    }

    /**
     * Get the minimum required PHPRedis version.
     */
    private function requiredVersion(): string
    {
        return $this->taggingMode === 'any'
            ? self::ANY_TAG_MINIMUM_VERSION
            : self::MINIMUM_VERSION;
    }
}
