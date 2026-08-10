<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Support\Str;
use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure direct framework dependencies are installed with the split package.
     *
     * @throws JsonException
     */
    public function testDirectFrameworkDependenciesAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/foundation/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $rootComposer = json_decode(
            file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $internalConstraint = '^' . Str::before(
            $composer['extra']['branch-alias']['dev-main'],
            '-dev',
        );

        foreach ([
            'hypervel/cache',
            'hypervel/concurrency',
            'hypervel/encryption',
            'hypervel/events',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertSame($internalConstraint, $composer['require'][$dependency]);
        }

        foreach ([
            'guzzlehttp/guzzle',
            'league/flysystem',
            'league/uri',
            'monolog/monolog',
        ] as $dependency) {
            $this->assertSame($rootComposer['require'][$dependency], $composer['require'][$dependency]);
        }

        foreach ([
            'algolia/algoliasearch-client-php',
            'fakerphp/faker',
            'meilisearch/meilisearch-php',
            'typesense/typesense-php',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['suggest']);
        }
    }
}
