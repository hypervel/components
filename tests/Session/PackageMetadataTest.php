<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure runtime requirements are declared by the split package.
     *
     * @throws JsonException
     */
    public function testRuntimePackageRequirementsAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/session/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'ext-ctype',
            'ext-mbstring',
            'ext-session',
            'hypervel/auth',
            'hypervel/cache',
            'hypervel/collections',
            'hypervel/console',
            'hypervel/container',
            'hypervel/context',
            'hypervel/contracts',
            'hypervel/cookie',
            'hypervel/database',
            'hypervel/filesystem',
            'hypervel/http',
            'hypervel/macroable',
            'hypervel/redis',
            'hypervel/routing',
            'hypervel/support',
            'symfony/console',
            'symfony/finder',
            'symfony/http-foundation',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $this->assertSame(
            'Required to use the Redis session driver (^6.1); user session tracking requires ^6.3.',
            $composer['suggest']['ext-redis'],
        );
    }
}
