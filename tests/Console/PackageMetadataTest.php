<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

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
            file_get_contents(__DIR__ . '/../../src/console/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'dragonmantank/cron-expression',
            'ext-mbstring',
            'ext-pcntl',
            'ext-posix',
            'ext-swoole',
            'guzzlehttp/guzzle',
            'nesbot/carbon',
            'nunomaduro/termwind',
            'psr/http-client',
            'symfony/console',
            'symfony/event-dispatcher',
            'symfony/finder',
            'symfony/process',
            'hypervel/bus',
            'hypervel/cache',
            'hypervel/collections',
            'hypervel/container',
            'hypervel/context',
            'hypervel/contracts',
            'hypervel/coroutine',
            'hypervel/engine',
            'hypervel/filesystem',
            'hypervel/log',
            'hypervel/macroable',
            'hypervel/prompts',
            'hypervel/queue',
            'hypervel/reflection',
            'hypervel/support',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }
    }
}
