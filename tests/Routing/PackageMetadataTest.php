<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing;

use Hypervel\Support\Str;
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
            file_get_contents(__DIR__ . '/../../src/routing/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $rootComposer = json_decode(
            file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ([
            'ext-filter',
            'laravel/serializable-closure',
            'psr/http-message',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $rootComposer['require']);
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertSame($rootComposer['require'][$dependency], $composer['require'][$dependency]);
        }

        $internalConstraint = '^' . Str::before(
            $composer['extra']['branch-alias']['dev-main'],
            '-dev',
        );

        foreach (['hypervel/auth', 'hypervel/prompts', 'hypervel/rate-limiter'] as $dependency) {
            $this->assertSame($internalConstraint, $composer['require'][$dependency]);
        }
    }
}
