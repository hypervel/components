<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Hypervel\Tests\TestCase;
use Hypervel\View\ViewServiceProvider;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure View dependencies and provider discovery are declared consistently.
     *
     * @throws JsonException
     */
    public function testDependenciesAndProviderAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/view/composer.json'),
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

        $this->assertSame(
            $rootComposer['require']['symfony/http-foundation'],
            $composer['require']['symfony/http-foundation']
        );
        $this->assertSame(
            $rootComposer['require']['symfony/http-kernel'],
            $composer['require']['symfony/http-kernel']
        );
        $this->assertArrayNotHasKey('hypervel/foundation', $composer['require']);
        $this->assertArrayNotHasKey('hypervel/validation', $composer['require']);
        $this->assertArrayHasKey('hypervel/foundation', $composer['suggest']);
        $this->assertNotSame('', trim($composer['suggest']['hypervel/foundation']));

        $this->assertSame(
            [ViewServiceProvider::class],
            $composer['extra']['hypervel']['providers']
        );
        $this->assertContains(
            ViewServiceProvider::class,
            $rootComposer['extra']['hypervel']['providers']
        );
    }
}
