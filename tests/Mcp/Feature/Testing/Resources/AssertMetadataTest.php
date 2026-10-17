<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Resources\AssertMetadataTest;

use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\ExpectationFailedException;

class GarageR extends Server
{
    protected array $resources = [
        InspectionResource::class,
    ];
}

class InspectionResource extends Resource
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
        $response = GarageR::resource(InspectionResource::class);

        $response->assertName('car/inspection')
            ->assertTitle('Car Inspection')
            ->assertDescription('Schedule a car inspection');
    }

    public function testFailsToAssertTheNameIsWrong(): void
    {
        $response = GarageR::resource(InspectionResource::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertName('wrong/name');
    }

    public function testFailsToAssertTheTitleIsWrong(): void
    {
        $response = GarageR::resource(InspectionResource::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertTitle('Wrong Title');
    }

    public function testFailsToAssertTheDescriptionIsWrong(): void
    {
        $response = GarageR::resource(InspectionResource::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertDescription('Wrong description');
    }
}
