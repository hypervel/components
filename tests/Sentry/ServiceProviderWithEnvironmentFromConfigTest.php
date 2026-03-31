<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

/**
 * @internal
 * @coversNothing
 */
class ServiceProviderWithEnvironmentFromConfigTest extends SentryTestCase
{
    public function testSentryEnvironmentDefaultsToHypervelEnvironment(): void
    {
        $this->assertEquals('testing', app()->environment());
    }

    public function testEmptySentryEnvironmentDefaultsToHypervelEnvironment(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.environment' => '',
        ]);

        $this->assertEquals('testing', $this->getSentryClientFromContainer()->getOptions()->getEnvironment());

        $this->resetApplicationWithConfig([
            'sentry.environment' => null,
        ]);

        $this->assertEquals('testing', $this->getSentryClientFromContainer()->getOptions()->getEnvironment());
    }

    public function testSentryEnvironmentDefaultGetsOverriddenByConfig(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.environment' => 'override_env',
        ]);

        $this->assertEquals('override_env', $this->getSentryClientFromContainer()->getOptions()->getEnvironment());
    }
}
