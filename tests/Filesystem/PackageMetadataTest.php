<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure optional image interoperability is declared for split installs.
     *
     * @throws JsonException
     */
    public function testImageSuggestionIsDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/filesystem/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertArrayHasKey('hypervel/image', $composer['suggest']);
        $this->assertIsString($composer['suggest']['hypervel/image']);
        $this->assertNotSame('', trim($composer['suggest']['hypervel/image']));
    }
}
