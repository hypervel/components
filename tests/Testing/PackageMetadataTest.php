<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Testing\TestingServiceProvider;
use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure split-package dependencies and discovery metadata match the monorepo.
     *
     * @throws JsonException
     */
    public function testDependenciesSuggestionsAndProviderAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/testing/composer.json'),
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
            'hypervel/collections',
            'hypervel/conditionable',
            'hypervel/console',
            'hypervel/container',
            'hypervel/contracts',
            'hypervel/cookie',
            'hypervel/database',
            'hypervel/di',
            'hypervel/filesystem',
            'hypervel/foundation',
            'hypervel/http',
            'hypervel/macroable',
            'hypervel/prompts',
            'hypervel/support',
            'hypervel/view',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertArrayHasKey($dependency, $rootComposer['replace']);
            $this->assertSame('^0.4', $composer['require'][$dependency]);
            $this->assertSame('self.version', $rootComposer['replace'][$dependency]);
        }

        foreach ([
            'ext-dom',
            'ext-mbstring',
            'composer-runtime-api',
            'nesbot/carbon',
            'symfony/console',
            'symfony/http-foundation',
            'symfony/process',
            'vlucas/phpdotenv',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $rootComposer['require']);
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertSame($rootComposer['require'][$dependency], $composer['require'][$dependency]);
        }

        $this->assertArrayHasKey('mockery/mockery', $rootComposer['require-dev']);
        $this->assertArrayHasKey('mockery/mockery', $composer['require']);
        $this->assertSame($rootComposer['require-dev']['mockery/mockery'], $composer['require']['mockery/mockery']);
        $this->assertSame([
            'brianium/paratest' => 'Required to run tests in parallel (^7.24).',
            'phpunit/phpunit' => "Required to use Hypervel's testing assertions and PHPUnit integration (^13.0.3).",
        ], $composer['suggest']);

        $this->assertSame(
            [TestingServiceProvider::class],
            $composer['extra']['hypervel']['providers'],
        );
        $this->assertContains(
            TestingServiceProvider::class,
            $rootComposer['extra']['hypervel']['providers'],
        );
    }

    /**
     * Ensure active manifests do not declare extensions guaranteed by supported PHP versions.
     *
     * @throws JsonException
     */
    public function testGuaranteedCoreExtensionsAreNotDeclared(): void
    {
        $paths = array_merge(
            [__DIR__ . '/../../composer.json'],
            glob(__DIR__ . '/../../src/*/composer.json') ?: [],
        );

        foreach ($paths as $path) {
            $composer = json_decode(
                file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            foreach (['ext-json', 'ext-hash'] as $extension) {
                $this->assertArrayNotHasKey($extension, $composer['require'] ?? [], $path);
                $this->assertArrayNotHasKey($extension, $composer['suggest'] ?? [], $path);
            }
        }
    }
}
