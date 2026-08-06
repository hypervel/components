<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use Hypervel\Pagination\PaginationServiceProvider;
use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure provider discovery metadata is declared consistently.
     *
     * @throws JsonException
     */
    public function testProviderDiscoveryMetadataIsDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/pagination/composer.json'),
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
            [PaginationServiceProvider::class],
            $composer['extra']['hypervel']['providers']
        );
        $this->assertContains(
            PaginationServiceProvider::class,
            $rootComposer['extra']['hypervel']['providers']
        );
    }
}
