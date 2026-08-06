<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure HTTP extension dependencies are declared consistently.
     *
     * @throws JsonException
     */
    public function testExtensionDependenciesAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/http/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('*', $composer['require']['ext-filter']);
        $this->assertSame(
            'Required to use Hypervel\Http\Testing\FileFactory::image().',
            $composer['suggest']['ext-gd']
        );
    }
}
