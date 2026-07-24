<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

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
            file_get_contents(__DIR__ . '/../../src/database/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'brick/math',
            'ext-pdo',
            'ext-swoole',
            'nesbot/carbon',
            'psr/log',
            'symfony/console',
            'symfony/finder',
            'symfony/polyfill-php86',
            'symfony/process',
            'hypervel/broadcasting',
            'hypervel/collections',
            'hypervel/conditionable',
            'hypervel/console',
            'hypervel/container',
            'hypervel/context',
            'hypervel/contracts',
            'hypervel/coordinator',
            'hypervel/core',
            'hypervel/coroutine',
            'hypervel/engine',
            'hypervel/events',
            'hypervel/filesystem',
            'hypervel/http',
            'hypervel/macroable',
            'hypervel/pagination',
            'hypervel/pool',
            'hypervel/prompts',
            'hypervel/queue',
            'hypervel/support',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $this->assertArrayNotHasKey('hypervel/config', $composer['require']);
    }
}
