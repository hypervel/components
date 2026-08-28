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
        $composer = $this->packageMetadata();

        $this->assertArrayHasKey('hypervel/image', $composer['suggest']);
        $this->assertIsString($composer['suggest']['hypervel/image']);
        $this->assertNotSame('', trim($composer['suggest']['hypervel/image']));
    }

    /**
     * Ensure bounded stream support declares its direct runtime dependency.
     *
     * @throws JsonException
     */
    public function testPsr7DependencyIsDeclared(): void
    {
        $composer = $this->packageMetadata();

        $this->assertArrayHasKey('guzzlehttp/psr7', $composer['require']);
        $this->assertIsString($composer['require']['guzzlehttp/psr7']);
        $this->assertNotSame('', trim($composer['require']['guzzlehttp/psr7']));
    }

    /**
     * Read the split package metadata.
     *
     * @throws JsonException
     */
    private function packageMetadata(): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/../../src/filesystem/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
}
