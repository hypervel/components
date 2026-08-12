<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\RateLimiter\RateLimiterServiceProvider;
use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure direct runtime dependencies are installed with the split package.
     *
     * @throws JsonException
     */
    public function testDirectRuntimeDependenciesAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/rate-limiter/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ([
            'php',
            'ext-swoole',
            'hypervel/collections',
            'hypervel/console',
            'hypervel/container',
            'hypervel/contracts',
            'hypervel/coordinator',
            'hypervel/core',
            'hypervel/database',
            'hypervel/redis',
            'hypervel/support',
            'psr/log',
            'symfony/console',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }
    }

    /**
     * Ensure standalone installations discover the package provider.
     *
     * @throws JsonException
     */
    public function testServiceProviderIsDiscoverable(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/rate-limiter/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            [RateLimiterServiceProvider::class],
            $composer['extra']['hypervel']['providers'],
        );
    }
}
