<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure direct runtime dependencies are installed with the split package.
     *
     * @throws JsonException
     */
    public function testDirectRuntimeDependenciesAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/validation/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'ext-filter',
            'ext-mbstring',
            'brick/math',
            'egulias/email-validator',
            'hypervel/collections',
            'hypervel/conditionable',
            'hypervel/console',
            'hypervel/container',
            'hypervel/context',
            'hypervel/contracts',
            'hypervel/database',
            'hypervel/http',
            'hypervel/macroable',
            'hypervel/support',
            'hypervel/translation',
            'symfony/console',
            'symfony/http-foundation',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $this->assertSame(
            'Required to use ValidatesWhenResolvedTrait with precognitive requests.',
            $composer['suggest']['hypervel/foundation']
        );
        $this->assertArrayNotHasKey('hypervel/foundation', $composer['require']);
    }
}
