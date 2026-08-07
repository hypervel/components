<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb;

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
            file_get_contents(__DIR__ . '/../../src/reverb/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'ext-swoole',
            'hypervel/api-client',
            'hypervel/bus',
            'hypervel/collections',
            'hypervel/console',
            'hypervel/container',
            'hypervel/context',
            'hypervel/contracts',
            'hypervel/coordinator',
            'hypervel/core',
            'hypervel/coroutine',
            'hypervel/filesystem',
            'hypervel/foundation',
            'hypervel/http',
            'hypervel/http-server',
            'hypervel/log',
            'hypervel/prompts',
            'hypervel/queue',
            'hypervel/rate-limiter',
            'hypervel/redis',
            'hypervel/routing',
            'hypervel/server',
            'hypervel/support',
            'hypervel/validation',
            'hypervel/websocket-server',
            'symfony/console',
            'symfony/http-foundation',
            'symfony/http-kernel',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $this->assertArrayNotHasKey('hypervel/broadcasting', $composer['require']);
    }
}
