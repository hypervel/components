<?php

declare(strict_types=1);

namespace Hypervel\Tests\Image\Drivers;

use Closure;
use Hypervel\Contracts\Image\Transformation;
use Hypervel\Image\Drivers\InterventionDriver;
use Hypervel\Image\ImageException;
use Hypervel\Tests\TestCase;
use Intervention\Image\Interfaces\ImageManagerInterface;
use Mockery as m;
use RuntimeException;

class InterventionDriverTest extends TestCase
{
    public function testRequirementsAreCheckedBeforeManagerCreation(): void
    {
        $managerCreated = false;

        try {
            new FailingRequirementsInterventionDriver(function () use (&$managerCreated): void {
                $managerCreated = true;
            });
            $this->fail('Expected the driver requirement check to fail.');
        } catch (ImageException $exception) {
            $this->assertSame('Missing image dependency.', $exception->getMessage());
        }

        $this->assertFalse($managerCreated);
    }

    public function testTransformationHandlersPersistOnTheDriver(): void
    {
        $driver = new InspectableInterventionDriver(m::mock(ImageManagerInterface::class));
        $callback = static function (): void {
        };

        $this->assertSame(
            $driver,
            $driver->transformUsing(InterventionDriverTestTransformation::class, $callback),
        );
        $this->assertSame($callback, $driver->handlerFor(new InterventionDriverTestTransformation));
        $this->assertSame($callback, $driver->handlerFor(new InterventionDriverTestTransformation));
    }
}

class FailingRequirementsInterventionDriver extends InterventionDriver
{
    /**
     * Create a driver with a manager-creation recorder.
     */
    public function __construct(private Closure $managerRecorder)
    {
        parent::__construct();
    }

    /**
     * Fail the dependency requirement check.
     */
    public function ensureRequirementsAreMet(): never
    {
        throw new ImageException('Missing image dependency.');
    }

    /**
     * Record an attempted manager creation.
     */
    protected function createManager(): ImageManagerInterface
    {
        ($this->managerRecorder)();

        throw new RuntimeException('The image manager must not be created.');
    }
}

class InspectableInterventionDriver extends InterventionDriver
{
    /**
     * Create a driver with the given image manager.
     */
    public function __construct(private ImageManagerInterface $testManager)
    {
        parent::__construct();
    }

    /**
     * Create the underlying image manager.
     */
    protected function createManager(): ImageManagerInterface
    {
        return $this->testManager;
    }

    /**
     * Get the registered handler for a transformation.
     */
    public function handlerFor(Transformation $transformation): ?callable
    {
        return $this->transformationHandlerFor($transformation);
    }
}

readonly class InterventionDriverTestTransformation implements Transformation
{
}
