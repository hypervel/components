<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data;

use Hypervel\Data\DataServiceProvider;
use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Data dependencies and discovery metadata match the root package.
     *
     * @throws JsonException
     */
    public function testDependenciesAndProviderAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/data/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $rootComposer = json_decode(
            file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ([
            'ext-tokenizer',
            'laravel/serializable-closure',
            'nesbot/carbon',
            'phpstan/phpdoc-parser',
            'symfony/console',
            'symfony/var-dumper',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $rootComposer['require']);
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertSame($rootComposer['require'][$dependency], $composer['require'][$dependency]);
        }

        foreach (array_keys($composer['require']) as $dependency) {
            if (! str_starts_with($dependency, 'hypervel/')) {
                continue;
            }

            $this->assertSame('self.version', $rootComposer['replace'][$dependency] ?? null);
        }

        $this->assertSame(
            [DataServiceProvider::class],
            $composer['extra']['hypervel']['providers'],
        );
        $this->assertContains(
            DataServiceProvider::class,
            $rootComposer['extra']['hypervel']['providers'],
        );
        $this->assertSame('src/data/src/', $rootComposer['autoload']['psr-4']['Hypervel\Data\\']);
        $this->assertArrayHasKey('hypervel/inertia', $composer['suggest']);
    }
}
