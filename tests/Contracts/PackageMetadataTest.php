<?php

declare(strict_types=1);

namespace Hypervel\Tests\Contracts;

use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure every external parent interface is installed with the split package.
     *
     * @throws JsonException
     */
    public function testExternalParentInterfaceDependenciesAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/contracts/composer.json'),
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

        foreach ([
            'monolog/monolog',
            'nesbot/carbon',
            'psr/container',
            'psr/http-message',
            'psr/log',
            'psr/simple-cache',
            'symfony/http-kernel',
        ] as $dependency) {
            $this->assertSame($rootComposer['require'][$dependency], $composer['require'][$dependency]);
        }
    }
}
