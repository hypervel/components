<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Permission\PermissionServiceProvider;
use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Permission dependencies and discovery metadata match the root package.
     *
     * @throws JsonException
     */
    public function testDependenciesAndProviderAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/permission/composer.json'),
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

        foreach ($composer['require'] as $dependency => $constraint) {
            // The root package replaces the Hypervel packages instead of requiring them.
            if (str_starts_with($dependency, 'hypervel/')) {
                continue;
            }

            $this->assertSame($rootComposer['require'][$dependency] ?? null, $constraint, $dependency);
        }

        $this->assertSame(
            [PermissionServiceProvider::class],
            $composer['extra']['hypervel']['providers'],
        );
        $this->assertContains(
            PermissionServiceProvider::class,
            $rootComposer['extra']['hypervel']['providers'],
        );
    }
}
