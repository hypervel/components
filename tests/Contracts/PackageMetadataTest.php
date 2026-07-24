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

        foreach ([
            'monolog/monolog',
            'psr/container',
            'psr/log',
            'psr/simple-cache',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }
    }
}
