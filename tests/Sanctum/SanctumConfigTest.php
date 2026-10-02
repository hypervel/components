<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Sanctum\Sanctum;
use Hypervel\Testbench\TestCase;

class SanctumConfigTest extends TestCase
{
    public function testCacheIntervalsAreLoadedAsIntegersFromEnvironment(): void
    {
        $config = $this->loadConfigWithEnvironmentValues([
            'SANCTUM_CACHE_TTL' => '600',
            'SANCTUM_LAST_USED_AT_UPDATE_INTERVAL' => '120',
        ]);

        $this->assertSame(600, $config['cache']['ttl']);
        $this->assertSame(120, $config['cache']['last_used_at_update_interval']);
    }

    public function testBooleanEnvironmentValuesAreLoadedAsBooleans(): void
    {
        $config = $this->loadConfigWithEnvironmentValues([
            'SANCTUM_LAST_USED_AT' => '0',
            'SANCTUM_CACHE_ENABLED' => '1',
        ]);

        $this->assertFalse($config['last_used_at']);
        $this->assertTrue($config['cache']['enabled']);
    }

    public function testInvalidLastUsedUpdateIntervalRemainsInvalid(): void
    {
        $config = $this->loadConfigWithEnvironmentValues([
            'SANCTUM_LAST_USED_AT_UPDATE_INTERVAL' => 'not-an-interval',
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

    public function testDefaultStatefulDomainsIncludeTheApplicationUrlPort(): void
    {
        config()->set('app.url', 'http://localhost:8000');

        $config = $this->loadConfigWithEnvironmentValues([
            'SANCTUM_STATEFUL_DOMAINS' => null,
        ]);

        $this->assertContains('localhost:8000', $config['stateful_domains']);
    }

    public function testRouteDefaultsAreDeclared(): void
    {
        $config = $this->loadConfigWithEnvironmentValues([
            'SANCTUM_CACHE_TTL' => null,
            'SANCTUM_LAST_USED_AT_UPDATE_INTERVAL' => null,
        ]);

        $this->assertTrue($config['routes']);
        $this->assertSame('sanctum', $config['prefix']);
        $this->assertSame(Sanctum::DEFAULT_CACHE_TTL, $config['cache']['ttl']);
        $this->assertSame(
            Sanctum::DEFAULT_LAST_USED_AT_UPDATE_INTERVAL,
            $config['cache']['last_used_at_update_interval'],
        );
    }

    /**
     * Load the Sanctum configuration with temporary environment values.
     *
     * @param array<string, null|string> $environment
     * @return array<string, mixed>
     */
    private function loadConfigWithEnvironmentValues(array $environment): array
    {
        return $this->withEnvironmentValues(
            $environment,
            fn (): array => require dirname(__DIR__, 2) . '/src/sanctum/config/sanctum.php',
        );
    }
}
