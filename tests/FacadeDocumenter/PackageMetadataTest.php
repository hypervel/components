<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Facade Documenter declares its direct runtime dependencies.
     *
     * @throws JsonException
     */
    public function testDirectRuntimeDependenciesAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/facade-documenter/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $dependencies = array_keys($composer['require']);
        $expectedDependencies = [
            'php',
            'composer-runtime-api',
            'phpstan/phpdoc-parser',
            'hypervel/filesystem',
            'hypervel/support',
        ];

        sort($dependencies);
        sort($expectedDependencies);

        $this->assertSame($expectedDependencies, $dependencies);
    }
}
