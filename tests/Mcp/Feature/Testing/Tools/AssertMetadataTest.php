<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Tools\AssertMetadataTest;

use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\ExpectationFailedException;

class GarageT extends Server
{
    protected array $tools = [
        InspectionTool::class,
    ];
}

class InspectionTool extends Tool
{
    protected string $name = 'car/inspection';

    protected string $title = 'Car Inspection';

    protected string $description = 'Schedule a car inspection';

    /**
     * Return the inspection confirmation.
     */
    public function handle(): string
    {
        return 'Your car inspection is scheduled!';
    }
}

class AssertMetadataTest extends TestCase
{
    public function testMayAssertTheTitle(): void
    {
        $response = GarageT::tool(InspectionTool::class);

        $response->assertName('car/inspection')
            ->assertTitle('Car Inspection')
            ->assertDescription('Schedule a car inspection');
    }

    public function testFailsToAssertTheNameIsWrong(): void
    {
        $response = GarageT::tool(InspectionTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertName('wrong/name');
    }

    public function testFailsToAssertTheTitleIsWrong(): void
    {
        $response = GarageT::tool(InspectionTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertTitle('Wrong Title');
    }

    public function testFailsToAssertTheDescriptionIsWrong(): void
    {
        $response = GarageT::tool(InspectionTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertDescription('Wrong description');
    }
}
