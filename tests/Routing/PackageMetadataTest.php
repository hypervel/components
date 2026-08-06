<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing;

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
            file_get_contents(__DIR__ . '/../../src/routing/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'ext-filter' => '*',
            'ext-hash' => '*',
            'hypervel/auth' => '^0.4',
            'hypervel/prompts' => '^0.4',
            'hypervel/redis' => '^0.4',
            'laravel/serializable-closure' => '^2.0.10',
            'psr/http-message' => '^2.0',
        ] as $dependency => $constraint) {
            $this->assertSame($constraint, $composer['require'][$dependency] ?? null);
        }
    }
}
