<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tinker;

use Hypervel\Tests\TestCase;
use Hypervel\Tinker\TinkerServiceProvider;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Tinker's split metadata matches its runtime surface.
     *
     * @throws JsonException
     */
    public function testPackageMetadataMatchesTheRootPackage(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/tinker/composer.json'),
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
            'php',
            'hypervel/collections',
            'hypervel/console',
            'hypervel/foundation',
            'hypervel/support',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
        }

        foreach (['psy/psysh', 'symfony/console', 'symfony/var-dumper'] as $dependency) {
            $this->assertSame($rootComposer['require'][$dependency], $composer['require'][$dependency]);
        }

        $this->assertArrayNotHasKey('hypervel/contracts', $composer['require']);
        $this->assertSame([
            'hypervel/database' => 'Required for Eloquent model casting in Tinker (^0.4).',
        ], $composer['suggest']);
        $this->assertSame([
            TinkerServiceProvider::class,
        ], $composer['extra']['hypervel']['providers']);
        $this->assertContains(
            TinkerServiceProvider::class,
            $rootComposer['extra']['hypervel']['providers'],
        );
    }
}
