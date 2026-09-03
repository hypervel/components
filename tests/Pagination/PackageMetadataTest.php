<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use Hypervel\Pagination\PaginationServiceProvider;
use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure package metadata is declared consistently.
     *
     * @throws JsonException
     */
    public function testPackageMetadataIsDeclaredConsistently(): void
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
        $this->assertArrayNotHasKey('hypervel/database', $composer['require']);
        $this->assertArrayNotHasKey('hypervel/http', $composer['require']);
        $this->assertArrayNotHasKey('hypervel/view', $composer['require']);
        $this->assertArrayHasKey('hypervel/http', $composer['suggest']);
        $this->assertNotSame('', trim($composer['suggest']['hypervel/http']));
        $this->assertArrayHasKey('hypervel/view', $composer['suggest']);
        $this->assertNotSame('', trim($composer['suggest']['hypervel/view']));
    }
}
