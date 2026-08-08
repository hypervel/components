<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys;

use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Passkeys dependencies match the root package.
     *
     * @throws JsonException
     */
    public function testDependenciesAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/passkeys/composer.json'),
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

        foreach (['nesbot/carbon', 'symfony/http-kernel'] as $dependency) {
            $this->assertSame($rootComposer['require'][$dependency], $composer['require'][$dependency]);
        }
    }
}
