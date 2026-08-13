<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon;

use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\DefaultProviders;
use Hypervel\Tests\TestCase;
use JsonException;
use ReflectionClass;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure direct runtime dependencies are installed with the split package.
     *
     * @throws JsonException
     */
    public function testDirectRuntimeDependenciesAreDeclared(): void
    {
        $composer = $this->packageComposer();

        foreach ([
            'php',
            'ext-dom',
            'ext-mbstring',
            'ext-simplexml',
            'guzzlehttp/guzzle',
            'guzzlehttp/psr7',
            'hypervel/cache',
            'hypervel/collections',
            'hypervel/conditionable',
            'hypervel/console',
            'hypervel/container',
            'hypervel/contracts',
            'hypervel/coroutine',
            'hypervel/events',
            'hypervel/filesystem',
            'hypervel/foundation',
            'hypervel/http',
            'hypervel/macroable',
            'hypervel/prompts',
            'hypervel/rate-limiter',
            'hypervel/reflection',
            'hypervel/support',
            'nesbot/carbon',
            'psr/http-message',
            'symfony/console',
            'symfony/dom-crawler',
            'symfony/finder',
            'symfony/var-dumper',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }
    }

    /**
     * Ensure discovery metadata is wired in both package manifests.
     *
     * @throws JsonException
     */
    public function testProviderAndFacadeAreDiscoverable(): void
    {
        $composer = $this->packageComposer();
        $rootComposer = $this->rootComposer();

        $this->assertSame(
            [SaloonServiceProvider::class],
            $composer['extra']['hypervel']['providers'],
        );
        $this->assertSame(
            Saloon::class,
            $composer['extra']['hypervel']['aliases']['Saloon'],
        );
        $this->assertContains(
            SaloonServiceProvider::class,
            $rootComposer['extra']['hypervel']['providers'],
        );
        $this->assertSame(
            Saloon::class,
            $rootComposer['extra']['hypervel']['aliases']['Saloon'],
        );
        $this->assertSame('src/saloon/src/', $rootComposer['autoload']['psr-4']['Hypervel\Saloon\\']);
        $this->assertArrayHasKey('hypervel/saloon', $rootComposer['replace']);
        $this->assertNotContains(SaloonServiceProvider::class, (new DefaultProviders)->toArray());
    }

    public function testFacadeDocumentsTheManagerSurface(): void
    {
        $docblock = (new ReflectionClass(Saloon::class))->getDocComment();
        $this->assertIsString($docblock);

        foreach ([
            'middleware',
            'fake',
            'mockClient',
            'clearFake',
            'assertSent',
            'assertNotSent',
            'assertSentInOrder',
            'assertNothingSent',
            'assertSentCount',
            'resolveCacheScopeUsing',
            'fixturePath',
            'throwOnMissingFixtures',
        ] as $method) {
            $this->assertStringContainsString(" {$method}(", $docblock);
        }
    }

    /**
     * Read the split-package Composer manifest.
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    protected function packageComposer(): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/../../src/saloon/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Read the root Composer manifest.
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    protected function rootComposer(): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
