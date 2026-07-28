<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\TestCase;
use JsonException;
use ReflectionClass;

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
            file_get_contents(__DIR__ . '/../../src/queue/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'laravel/serializable-closure',
            'nesbot/carbon',
            'symfony/console',
            'symfony/process',
            'hypervel/bus',
            'hypervel/cache',
            'hypervel/collections',
            'hypervel/config',
            'hypervel/console',
            'hypervel/container',
            'hypervel/contracts',
            'hypervel/context',
            'hypervel/coordinator',
            'hypervel/coroutine',
            'hypervel/database',
            'hypervel/encryption',
            'hypervel/engine',
            'hypervel/events',
            'hypervel/filesystem',
            'hypervel/foundation',
            'hypervel/log',
            'hypervel/object-pool',
            'hypervel/pipeline',
            'hypervel/redis',
            'hypervel/support',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }
    }

    public function testFacadeDocumentsInspectionAndFakeSurfaces(): void
    {
        $docblock = (new ReflectionClass(Queue::class))->getDocComment();
        $this->assertIsString($docblock);

        foreach ([
            'getPausedQueues',
            'pendingJobs',
            'delayedJobs',
            'reservedJobs',
            'allPendingJobs',
            'allDelayedJobs',
            'allReservedJobs',
            'reserve',
            'clearReserved',
            'beforePushing',
            'afterPushing',
        ] as $method) {
            $this->assertStringContainsString(" {$method}(", $docblock);
        }
    }
}
