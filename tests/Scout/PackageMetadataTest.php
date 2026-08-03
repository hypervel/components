<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout;

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
            file_get_contents(__DIR__ . '/../../src/scout/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'guzzlehttp/guzzle',
            'hypervel/collections',
            'hypervel/conditionable',
            'hypervel/config',
            'hypervel/console',
            'hypervel/container',
            'hypervel/context',
            'hypervel/contracts',
            'hypervel/coroutine',
            'hypervel/database',
            'hypervel/foundation',
            'hypervel/macroable',
            'hypervel/pagination',
            'hypervel/queue',
            'hypervel/support',
            'psr/http-message',
            'symfony/console',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $this->assertSame('^7.15.1', $composer['require']['guzzlehttp/guzzle']);
        $this->assertSame('^2.0', $composer['require']['psr/http-message']);

        foreach ([
            'algolia/algoliasearch-client-php',
            'meilisearch/meilisearch-php',
            'typesense/typesense-php',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['suggest']);
            $this->assertArrayNotHasKey($dependency, $composer['require']);
        }
    }
}
