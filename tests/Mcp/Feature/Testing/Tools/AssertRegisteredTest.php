<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Tools\AssertRegisteredTest;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class GarageListToolsServer extends Server
{
    public int $defaultPaginationLength = 1;

    protected array $tools = [
        OilChangeTool::class,
        TireRotationTool::class,
        EngineDiagnosticTool::class,
    ];
}

class DiagnosticsRepository
{
}

class OilChangeTool extends Tool
{
    public static bool $shouldRegister = true;

    /**
     * Resolve the application's tool name through its configured dependencies.
     */
    public function __construct(Repository $config)
    {
        $this->name = $config->string('app.name') . '/oil-change';
    }

    /**
     * Determine whether this tool is registered.
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

class TireRotationTool extends Tool
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

class EngineDiagnosticTool extends Tool
{
    protected string $name = 'garage/engine-diagnostics';

    /**
     * Create the diagnostic tool.
     */
    public function __construct(protected DiagnosticsRepository $diagnostics)
    {
    }

    /**
     * Return the diagnostic confirmation.
     */
    public function handle(): string
    {
        return 'Diagnostics complete.';
    }
}

class BrakeInspectionTool extends Tool
{
    protected string $name = 'garage/brake-inspection';

    /**
     * Create the brake inspection tool.
     */
    public function __construct(protected DiagnosticsRepository $diagnostics)
    {
    }

    /**
     * Return the brake inspection confirmation.
     */
    public function handle(): string
    {
        return 'Brakes inspected.';
    }
}

class AssertRegisteredTest extends TestCase
{
    /**
     * Restore the fixture's registration state.
     */
    protected function tearDown(): void
    {
        OilChangeTool::$shouldRegister = true;

        parent::tearDown();
    }

    public function testMayAssertThatToolsAreRegisteredOnAServer(): void
    {
        OilChangeTool::$shouldRegister = true;

        GarageListToolsServer::tools()->assertRegistered([
            OilChangeTool::class,
            TireRotationTool::class,
        ]);
    }

    public function testMayFailToAssertThatToolsAreRegisteredOnAServer(): void
    {
        OilChangeTool::$shouldRegister = false;

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The expected class [' . OilChangeTool::class . '] was not found in the response content.');
        GarageListToolsServer::tools()->assertRegistered([
            TireRotationTool::class,
            OilChangeTool::class,
        ]);
    }

    public function testMayAssertThatToolsAreNotRegisteredOnAServer(): void
    {
        OilChangeTool::$shouldRegister = false;

        GarageListToolsServer::tools()->assertRegistered([
            TireRotationTool::class,
        ])->assertNotRegistered([
            OilChangeTool::class,
        ]);
    }

    public function testMayFailToAssertThatToolsAreNotRegisteredOnAServer(): void
    {
        OilChangeTool::$shouldRegister = true;

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The expected class [' . OilChangeTool::class . '] was found in the response content.');
        GarageListToolsServer::tools()->assertRegistered([
            TireRotationTool::class,
        ])->assertNotRegistered([
            OilChangeTool::class,
        ]);
    }

    public function testResolvesToolsWithConstructorDependenciesWhenAssertingTheyAreRegistered(): void
    {
        GarageListToolsServer::tools()->assertRegistered(EngineDiagnosticTool::class);
    }

    public function testResolvesToolsWithConstructorDependenciesWhenAssertingTheyAreNotRegistered(): void
    {
        GarageListToolsServer::tools()->assertNotRegistered(BrakeInspectionTool::class);
    }
}
