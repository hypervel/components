<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;

class SanctumConfigTest extends TestCase
{
    public function testCacheIntervalsAreLoadedAsIntegersFromEnvironment(): void
    {
        $config = $this->loadConfigWithEnvironmentValues([
            'SANCTUM_CACHE_TTL' => '600',
            'SANCTUM_LAST_USED_UPDATE_INTERVAL' => '120',
        ]);

        $this->assertSame(600, $config['cache']['ttl']);
        $this->assertSame(120, $config['cache']['last_used_at_update_interval']);
    }

    public function testInvalidLastUsedUpdateIntervalRemainsInvalid(): void
    {
        $config = $this->loadConfigWithEnvironmentValues([
            'SANCTUM_LAST_USED_UPDATE_INTERVAL' => 'not-an-interval',
        ]);

        $this->assertNull($config['cache']['last_used_at_update_interval']);
    }

    public function testNullStatefulDomainsDoNotCrashConfigLoading(): void
    {
        $config = $this->loadConfigWithEnvironmentValues([
            'SANCTUM_STATEFUL_DOMAINS' => '(null)',
        ]);

        $this->assertSame([''], $config['stateful_domains']);
    }

    /**
     * Load the Sanctum configuration with temporary environment values.
     *
     * @param array<string, string> $environment
     * @return array<string, mixed>
     */
    private function loadConfigWithEnvironmentValues(array $environment): array
    {
        $original = [];

        foreach ($environment as $key => $value) {
            $original[$key] = [
                'putenv' => getenv($key),
                'serverExists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
                'envExists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
            ];

            $this->setEnvironmentValue($key, $value);
        }

        try {
            return require dirname(__DIR__, 2) . '/src/sanctum/config/sanctum.php';
        } finally {
            foreach ($original as $key => $values) {
                $values['putenv'] === false
                    ? putenv($key)
                    : putenv("{$key}={$values['putenv']}");

                if ($values['serverExists']) {
                    $_SERVER[$key] = $values['server'];
                } else {
                    unset($_SERVER[$key]);
                }

                if ($values['envExists']) {
                    $_ENV[$key] = $values['env'];
                } else {
                    unset($_ENV[$key]);
                }
            }

            Env::flushRepository();
        }
    }
}
