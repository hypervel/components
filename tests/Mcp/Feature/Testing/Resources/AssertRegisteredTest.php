<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Resources\AssertRegisteredTest;

use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class GarageListResourcesServer extends Server
{
    public int $defaultPaginationLength = 1;

    protected array $resources = [
        OilChangeResource::class,
        TireRotationResource::class,
    ];
}

class OilChangeResource extends Resource
{
    public static bool $shouldRegister = true;

    protected string $name = 'garage/oil-change';

    /**
     * Determine whether this resource is registered.
     */
    public function shouldRegister(): bool
    {
        return static::$shouldRegister;
    }

    /**
     * Return the oil change confirmation.
     */
    public function handle(): string
    {
        return 'Oil changed.';
    }
}

class TireRotationResource extends Resource
{
    protected string $name = 'garage/tire-rotation';

    /**
     * Return the tire rotation confirmation.
     */
    public function handle(): string
    {
        return 'Tires rotated.';
    }
}

class AssertRegisteredTest extends TestCase
{
    /**
     * Restore the fixture's registration state.
     */
    protected function tearDown(): void
    {
        OilChangeResource::$shouldRegister = true;

        parent::tearDown();
    }

    public function testMayAssertThatResourcesAreRegisteredOnAServer(): void
    {
        OilChangeResource::$shouldRegister = true;

        GarageListResourcesServer::resources()->assertRegistered([
            OilChangeResource::class,
            TireRotationResource::class,
        ]);
    }

    public function testMayFailToAssertThatResourcesAreRegisteredOnAServer(): void
    {
        OilChangeResource::$shouldRegister = false;

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The expected class [' . OilChangeResource::class . '] was not found in the response content.');
        GarageListResourcesServer::resources()->assertRegistered([
            TireRotationResource::class,
            OilChangeResource::class,
        ]);
    }

    public function testMayAssertThatResourcesAreNotRegisteredOnAServer(): void
    {
        OilChangeResource::$shouldRegister = false;

        GarageListResourcesServer::resources()->assertRegistered([
            TireRotationResource::class,
        ])->assertNotRegistered([
            OilChangeResource::class,
        ]);
    }

    public function testMayFailToAssertThatResourcesAreNotRegisteredOnAServer(): void
    {
        OilChangeResource::$shouldRegister = true;

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The expected class [' . OilChangeResource::class . '] was found in the response content.');
        GarageListResourcesServer::resources()->assertRegistered([
            TireRotationResource::class,
        ])->assertNotRegistered([
            OilChangeResource::class,
        ]);

        $this->fail('Expected an AssertionFailedError to be thrown, but it was not.');
    }
}
