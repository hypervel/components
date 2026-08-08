<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys;

use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure split-package dependencies match the monorepo constraints.
     *
     * @throws JsonException
     */
    public function testDirectDependenciesMatchTheMonorepoConstraints(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/passkeys/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $rootComposer = json_decode(
            file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ([
            'php',
            'ext-hash',
            'ext-json',
            'hypervel/auth',
            'hypervel/collections',
            'hypervel/config',
            'hypervel/console',
            'hypervel/container',
            'hypervel/context',
            'hypervel/contracts',
            'hypervel/database',
            'hypervel/events',
            'hypervel/foundation',
            'hypervel/http',
            'hypervel/queue',
            'hypervel/routing',
            'hypervel/session',
            'hypervel/support',
            'hypervel/validation',
            'nesbot/carbon',
            'paragonie/constant_time_encoding',
            'symfony/console',
            'symfony/http-foundation',
            'symfony/http-kernel',
            'symfony/serializer',
            'web-auth/cose-lib',
            'web-auth/webauthn-lib',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        foreach ([
            'ext-hash',
            'ext-json',
            'nesbot/carbon',
            'paragonie/constant_time_encoding',
            'symfony/console',
            'symfony/http-foundation',
            'symfony/http-kernel',
            'symfony/serializer',
            'web-auth/cose-lib',
            'web-auth/webauthn-lib',
        ] as $dependency) {
            $this->assertSame($rootComposer['require'][$dependency], $composer['require'][$dependency]);
        }

        $this->assertSame('^0.4', $composer['require']['hypervel/context']);
        $this->assertSame('self.version', $rootComposer['replace']['hypervel/context']);
    }
}
